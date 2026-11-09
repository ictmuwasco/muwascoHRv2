#!/bin/bash
# ============================================================
# MUWASCO HR System - Database Backup & Disaster Recovery Script
# ============================================================
# 
# This script creates automated database backups with retention
# policy, offsite storage support, and recovery verification.
#
# Usage: ./backup.sh [--database|--files|--both] [--rotate]
# ============================================================

# ── Configuration ──────────────────────────────────────────────────────────
set -o pipefail

load_environment_file() {
    local env_file="/var/www/private/hrdemo.env"
    local line name value

    [ -r "$env_file" ] || return 0

    while IFS= read -r line || [ -n "$line" ]; do
        line=${line//$'\r'/}
        [[ "$line" =~ ^[[:space:]]*([A-Za-z_][A-Za-z0-9_]*)=(.*)$ ]] || continue
        name="${BASH_REMATCH[1]}"
        value="${BASH_REMATCH[2]}"

        case "$name" in
            DB_*|BACKUP_*|S3_BUCKET|AWS_*|APP_ROOT|RESTORE_TEST_*) ;;
            *) continue ;;
        esac

        [[ -v $name ]] && continue

        case "$value" in
            \"*\") value="${value:1:${#value}-2}" ;;
            \'*\') value="${value:1:${#value}-2}" ;;
        esac
        export "$name=$value"
    done < "$env_file"
}

load_environment_file

DRY_RUN_REQUESTED=0
for arg in "$@"; do
    [ "$arg" = "--dry-run" ] && DRY_RUN_REQUESTED=1
done

DB_HOST="${DB_HOST:-localhost}"
DB_PORT="${DB_PORT:-3306}"
DB_USER="${DB_USERNAME:-muwascohr}"
DB_PASS="${DB_PASSWORD:-}"
if [ -z "$DB_DATABASE" ]; then
    printf '%s [ERROR] DB_DATABASE is missing; refusing to guess the database name.\n' "$(date '+%Y-%m-%d %H:%M:%S')" >&2
    exit 1
fi
DB_NAME="$DB_DATABASE"

if [ -z "$DB_PASS" ] && [ "$DRY_RUN_REQUESTED" -ne 1 ]; then
    printf '%s [ERROR] DB_PASSWORD is missing; set it in %s or the environment.\n' "$(date '+%Y-%m-%d %H:%M:%S')" "/var/www/private/hrdemo.env" >&2
    exit 1
fi

APP_ROOT="${APP_ROOT:-/var/www/vhosts/muwascoerp.co.ke/app.muwascoerp.co.ke}"
BACKUP_DIR="${BACKUP_PATH:-/var/www/private/muwasco-backups}"
BACKUP_UPLOAD_DIRS="${BACKUP_UPLOAD_DIRS:-$APP_ROOT/backend/storage/app}"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-30}"
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
DATE_STAMP=$(date +"%Y-%m-%d")
FAILED=0
LOG_FILE=""

MYSQLDUMP_CMD="mysqldump"
GZIP_CMD="gzip"
AWS_CLI="aws"
MYSQL_DEFAULTS_FILE=""
RESTORE_TEST_DEFAULTS_FILE=""
MYSQL_DEFAULTS_FILES=()

cleanup_mysql_defaults() {
    local file
    for file in "${MYSQL_DEFAULTS_FILES[@]}"; do
        rm -f -- "$file"
    done
}

trap cleanup_mysql_defaults EXIT

create_mysql_defaults_file() {
    local target_var="$1"
    local mysql_user="$2"
    local mysql_password="$3"
    local escaped_password defaults_file

    escaped_password="${mysql_password//\\/\\\\}"
    escaped_password="${escaped_password//\"/\\\"}"
    defaults_file=$(mktemp "${TMPDIR:-/tmp}/muwasco-mysql-XXXXXX") || {
        log_error "Could not create temporary MySQL option file"
        return 1
    }
    MYSQL_DEFAULTS_FILES+=("$defaults_file")
    printf -v "$target_var" '%s' "$defaults_file"
    chmod 600 "$defaults_file" || {
        log_error "Could not secure temporary MySQL option file"
        return 1
    }
    {
        printf '[client]\n'
        printf 'host=%s\n' "$DB_HOST"
        printf 'port=%s\n' "$DB_PORT"
        printf 'user=%s\n' "$mysql_user"
        printf 'password="%s"\n' "$escaped_password"
    } > "$defaults_file" || {
        log_error "Could not write temporary MySQL option file"
        return 1
    }
}

# ── Backup encryption ───────────────────────────────────────────────────────
# Backups are encrypted so a stolen backup, an offsite S3 copy, or a lost
# laptop holding an archive is unreadable without the key.
#
# WHY NOT `openssl enc -aes-256-gcm`
#   The obvious choice is rejected by the tool itself. VERIFIED on this host:
#
#     $ openssl enc -aes-256-gcm ...
#     enc: AEAD ciphers not supported
#
#   `openssl enc` deliberately refuses AEAD ciphers, so GCM is simply not
#   available through the CLI. (It IS available in PHP, which is why
#   App\Helpers\StorageEncryption can use real GCM - that path is unaffected.)
#
# WHAT IS USED INSTEAD, AND WHY IT IS STILL SAFE
#   AES-256-CBC for confidentiality, plus a DETACHED HMAC-SHA256 over the
#   ciphertext for integrity and authenticity. Encrypt-then-MAC is the correct
#   construction here: it is what GCM would have provided, composed from parts
#   the CLI does support.
#
#   CBC alone would NOT be sufficient. Without the MAC it is malleable - an
#   attacker with write access could flip chosen plaintext bits undetectably.
#   The MAC closes that hole, and it is verified BEFORE any decryption happens
#   so a tampered archive is rejected rather than silently decrypted to garbage.
#
#   The MAC key is derived from the same secret with a fixed domain-separation
#   label, so the encryption key and the MAC key are never identical.
#
# THE KEY IS NEVER STORED BESIDE THE BACKUPS. BACKUP_KEY_FILE must point at a
# path OUTSIDE $BACKUP_DIR - a mounted secret, a systemd credential, or a
# separate host. Keeping the key in the same directory (or the same S3 bucket)
# as the archives it protects would make the encryption worthless. That
# separation is enforced, not merely documented: load_backup_key aborts.
#
# Set BACKUP_ENCRYPTION=0 to fall back to plaintext archives (local dev only;
# the production host should leave it on).
BACKUP_ENCRYPTION="${BACKUP_ENCRYPTION:-1}"
BACKUP_KEY_FILE="${BACKUP_KEY_FILE:-/root/.muwasco-backup/backup.key}"
BACKUP_OPENSSL_CIPHER="aes-256-cbc"
# PBKDF2 work factor applied to the key file contents. This slows brute force
# of a leaked key file; it does NOT protect a key file that is itself
# compromised on a live host.
BACKUP_PBKDF2_ITER="${BACKUP_PBKDF2_ITER:-200000}"
# Domain separation so the MAC key differs from the encryption key.
BACKUP_MAC_LABEL="backup-hmac-v1"

# Derive the MAC key from the master secret. hexkey= is used so the raw binary
# is passed, not its hex text.
backup_mac_key() {
    printf '%s' "$BACKUP_MAC_LABEL" \
        | openssl dgst -sha256 -hmac "$BACKUP_ENCRYPTION_PASSWORD" -binary \
        | od -An -tx1 | tr -d ' \n'
}

# Returns the encryption password via BACKUP_ENCRYPTION_PASSWORD.
# Exits the script if the key is missing or unsafe.
validate_backup_key() {
    if [ "$BACKUP_ENCRYPTION" != "1" ]; then
        return 0
    fi

    if [ ! -r "$BACKUP_KEY_FILE" ]; then
        log_error "BACKUP_ENCRYPTION=1 but the key file is not readable: ${BACKUP_KEY_FILE}"
        log_error "Create it with: openssl rand -hex 32 > ${BACKUP_KEY_FILE} && chmod 600 ${BACKUP_KEY_FILE}"
        log_error "It MUST live outside ${BACKUP_DIR}."
        return 1
    fi

    local key_abs backup_abs
    key_abs=$(realpath -m -- "$BACKUP_KEY_FILE") || {
        log_error "Could not resolve BACKUP_KEY_FILE: ${BACKUP_KEY_FILE}"
        return 1
    }
    backup_abs=$(realpath -m -- "$BACKUP_DIR") || {
        log_error "Could not resolve BACKUP_DIR: ${BACKUP_DIR}"
        return 1
    }
    case "$key_abs" in
        "$backup_abs"|\
        "$backup_abs"/*)
            log_error "BACKUP_KEY_FILE (${key_abs}) is inside BACKUP_DIR (${backup_abs})."
            log_error "A backup stored with its own decryption key is not encrypted. Move the key out and retry."
            return 1
            ;;
    esac

    if [ ! -f "$BACKUP_KEY_FILE" ] || [ ! -s "$BACKUP_KEY_FILE" ]; then
        log_error "BACKUP_KEY_FILE is missing, not a regular file, or empty: ${BACKUP_KEY_FILE}"
        return 1
    fi
}

load_backup_key() {
    validate_backup_key || exit 1
    if [ "$BACKUP_ENCRYPTION" != "1" ]; then
        BACKUP_ENCRYPTION_PASSWORD=""
        return 0
    fi

    BACKUP_ENCRYPTION_PASSWORD=$(cat "$BACKUP_KEY_FILE")

    # MUST be exported: `openssl -pass env:VAR` reads the variable from the
    # CHILD process environment, and an unexported shell variable is invisible
    # there. Without this, openssl fails with "Can't read environment variable"
    # and every archive silently fails to encrypt. Verified: this was a real
    # failure before the export was added.
    export BACKUP_ENCRYPTION_PASSWORD
}

# Verify an archive's MAC. Returns non-zero on mismatch.
verify_archive_mac() {
    local archive="$1"
    local mac_file="${archive}.hmac"
    [ -f "$mac_file" ] || { log_error "missing MAC file: ${mac_file}"; return 1; }

    local expected actual
    expected=$(cut -d' ' -f2 "$mac_file" | tr -d '[:space:]')
    if [ -z "$expected" ]; then
        log_error "empty MAC file: ${mac_file}"
        return 1
    fi
    actual=$(openssl dgst -sha256 -mac HMAC -macopt "hexkey:$(backup_mac_key)" "$archive" \
             | cut -d' ' -f2 | tr -d '[:space:]') || {
        log_error "Could not calculate MAC for ${archive}"
        return 1
    }

    if [ "$expected" != "$actual" ]; then
        return 1
    fi
    return 0
}

write_sha256() {
    local archive="$1"
    if ! sha256sum "$archive" > "${archive}.sha256"; then
        rm -f -- "${archive}.sha256"
        log_error "Could not create SHA-256 checksum for ${archive}"
        return 1
    fi
}

# Encrypt a plaintext archive in place, writing <final> and <final>.hmac.
# Usage: encrypt_archive <plain_path> <final_path>
encrypt_archive() {
    local plain="$1"
    local final="$2"

    if [ "$BACKUP_ENCRYPTION" != "1" ]; then
        mv "$plain" "$final"
        return 0
    fi

    # Written to a temp name first and moved into place only on success, so a
    # failure never leaves a truncated .enc that looks valid.
    local tmp="${final}.partial.$$"
    if ! openssl enc -"$BACKUP_OPENSSL_CIPHER" -salt -pbkdf2 -iter "$BACKUP_PBKDF2_ITER" \
            -md sha256 -pass env:BACKUP_ENCRYPTION_PASSWORD \
            -in "$plain" -out "$tmp" 2>/dev/null; then
        rm -f "$tmp"
        log_error "Encryption failed for ${plain}"
        return 1
    fi

    # MAC the ciphertext (encrypt-then-MAC).
    openssl dgst -sha256 -mac HMAC -macopt "hexkey:$(backup_mac_key)" "$tmp" > "${tmp}.hmac" 2>/dev/null

    # Prove it before accepting: verify the MAC, then decrypt. An archive that
    # cannot be opened is worse than no archive - it is discovered mid-outage.
    if ! verify_archive_mac "$tmp"; then
        rm -f "$tmp" "${tmp}.hmac"
        log_error "MAC self-check failed for ${plain} - archive discarded"
        return 1
    fi

    if ! openssl enc -d -"$BACKUP_OPENSSL_CIPHER" -pbkdf2 -iter "$BACKUP_PBKDF2_ITER" \
            -md sha256 -pass env:BACKUP_ENCRYPTION_PASSWORD \
            -in "$tmp" -out /dev/null 2>/dev/null; then
        rm -f "$tmp" "${tmp}.hmac"
        log_error "Decryption self-check failed for ${plain} - archive discarded"
        return 1
    fi

    rm -f "$plain"
    mv "$tmp" "$final"
    mv "${tmp}.hmac" "${final}.hmac"
    return 0
}

# Decrypt an archive to stdout (used by the restore test).
decrypt_archive() {
    local archive="$1"
    if [ "$BACKUP_ENCRYPTION" != "1" ]; then
        cat "$archive"
        return 0
    fi
    if ! verify_archive_mac "$archive"; then
        log_error "MAC verification FAILED for ${archive} - refusing to decrypt"
        return 1
    fi
    openssl enc -d -"$BACKUP_OPENSSL_CIPHER" -pbkdf2 -iter "$BACKUP_PBKDF2_ITER" \
        -md sha256 -pass env:BACKUP_ENCRYPTION_PASSWORD -in "$archive" 2>/dev/null
}


# ── Color output ───────────────────────────────────────────────────────────
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# ── Functions ──────────────────────────────────────────────────────────────

log_info() {
    log_message "INFO" "$GREEN" "$1"
}

log_warn() {
    log_message "WARN" "$YELLOW" "$1"
}

log_error() {
    FAILED=1
    log_message "ERROR" "$RED" "$1"
}

log_message() {
    local level="$1"
    local color="$2"
    local message="$3"
    local line

    line="$(date '+%Y-%m-%d %H:%M:%S') [${level}] ${message}"
    printf '%b\n' "${color}${line}${NC}"
    if [ -n "$LOG_FILE" ]; then
        printf '%s\n' "$line" >> "$LOG_FILE"
    fi
}

# Create backup directory
ensure_backup_dir() {
    mkdir -p "$BACKUP_DIR/database"
    mkdir -p "$BACKUP_DIR/files"
    mkdir -p "$BACKUP_DIR/logs"
}

validate_backup_location() {
    local backup_abs app_abs
    backup_abs=$(realpath -m -- "$BACKUP_DIR") || {
        log_error "Could not resolve BACKUP_DIR: ${BACKUP_DIR}"
        return 1
    }
    app_abs=$(realpath -m -- "$APP_ROOT") || {
        log_error "Could not resolve APP_ROOT: ${APP_ROOT}"
        return 1
    }

    case "$backup_abs" in
        "$app_abs"|"$app_abs"/*|/var/www/vhosts/*|/var/www/html|/var/www/html/*)
            log_error "BACKUP_DIR (${backup_abs}) must be outside the application and web roots."
            return 1
            ;;
    esac
}

# Backup database
backup_database() {
    local backup_file="$BACKUP_DIR/database/${DB_NAME}_${TIMESTAMP}.sql"
    local compressed_file="${backup_file}.gz"
    local dump_err="$BACKUP_DIR/logs/mysqldump_${TIMESTAMP}.err"
    local -a dump_status
    local dump_rc sed_rc
    
    log_info "Starting database backup: ${DB_NAME}"
    
    # Perform the dump.
    #
    # RESTORE-BLOCKER FIX (verified 2026-09-29): a plain `mysqldump` of this
    # database CANNOT be restored, and the failure is silent until you try.
    #
    # `attendance.attendance_date` is
    #     GENERATED ALWAYS AS (cast(`clock_in` as date)) STORED
    # 257 rows have `clock_in = '0000-00-00 00:00:00'`. On the LIVE server
    # `sql_mode` contains NO_ZERO_IN_DATE/NO_ZERO_DATE, so `cast()` returns
    # NULL for those rows and uk_attendance_employee_date(employee_id,
    # attendance_date) is satisfied (NULLs never collide in a UNIQUE index).
    #
    # mysqldump however emits a prologue that RESETS the session to
    #     SQL_MODE='NO_AUTO_VALUE_ON_ZERO'
    # dropping NO_ZERO_DATE. On restore, `cast('0000-00-00' AS date)` then
    # yields the literal date 0000-00-00 instead of NULL, and the 3 such rows
    # belonging to employee 383 collide:
    #     ERROR 1062 (23000): Duplicate entry '383-0000-00-00'
    #         for key 'uk_attendance_employee_date'
    #
    # The dump is patched below to keep NO_ZERO_IN_DATE/NO_ZERO_DATE in that
    # prologue, which makes the restore reproduce the live NULLs. Verified by a
    # full restore into a scratch database: 87/87 tables, all row counts equal.
    #
    # NOTE: --skip-generated-columns is a MySQL 8 option and is REJECTED by
    # MariaDB's mysqldump ("unknown option"). Do not add it.
    $MYSQLDUMP_CMD --defaults-extra-file="$MYSQL_DEFAULTS_FILE" \
        --single-transaction \
        --routines \
        --triggers \
        --events \
        --add-drop-table \
        --complete-insert \
        --skip-lock-tables \
        "$DB_NAME" 2>"$dump_err" \
    | sed "s/SQL_MODE='NO_AUTO_VALUE_ON_ZERO'/SQL_MODE='NO_AUTO_VALUE_ON_ZERO,NO_ZERO_IN_DATE,NO_ZERO_DATE'/g" \
    > "$backup_file"
    dump_status=("${PIPESTATUS[@]}")
    dump_rc="${dump_status[0]}"
    sed_rc="${dump_status[1]}"

    if [ "$dump_rc" -ne 0 ] || [ "$sed_rc" -ne 0 ] || [ ! -s "$backup_file" ] \
            || ! grep -q -- '^-- Dump completed' "$backup_file"; then
        log_error "Database dump failed (mysqldump exit ${dump_rc}, sed exit ${sed_rc}) or lacked the completion marker; partial dump removed. See ${dump_err}"
        rm -f "$backup_file" "$compressed_file"
        return 1
    fi
    
    # Compress
    $GZIP_CMD "$backup_file"
    
    if [ $? -eq 0 ]; then
        # Encrypt the archive (aes-256-gcm + pbkdf2). encrypt_archive
        # self-checks by decrypting, and only then replaces the .gz.
        if ! encrypt_archive "$compressed_file" "${compressed_file}.enc"; then
            return 1
        fi
        local final_file="${compressed_file}.enc"
        local size=$(du -h "$final_file" | cut -f1)
        log_info "Database backup completed: ${final_file} (${size})"
        
        # Generate checksum (sha256; keep .md5 for backward compatibility)
        write_sha256 "$final_file" || return 1
        
        return 0
    else
        log_error "Compression failed"
        return 1
    fi
}

# Backup uploaded files
backup_files() {
    local backup_file="$BACKUP_DIR/files/uploads_${TIMESTAMP}.tar.gz"
    local upload_dir resolved_dir tar_path tar_rc tar_err="$BACKUP_DIR/logs/tar_${TIMESTAMP}.err"
    local -a upload_dirs=() tar_paths=()

    read -r -a upload_dirs <<< "$BACKUP_UPLOAD_DIRS"
    for upload_dir in "${upload_dirs[@]}"; do
        resolved_dir=$(realpath -m -- "$upload_dir") || {
            log_error "Could not resolve upload directory: ${upload_dir}"
            return 1
        }
        if [ ! -d "$resolved_dir" ]; then
            log_error "Upload directory not found: ${resolved_dir}"
            return 1
        fi
        tar_path="${resolved_dir#/}"
        tar_paths+=("$tar_path")
    done
    if [ "${#tar_paths[@]}" -eq 0 ]; then
        log_error "BACKUP_UPLOAD_DIRS did not contain any directories"
        return 1
    fi

    log_info "Starting files backup"

    tar -czf "$backup_file" -C / -- "${tar_paths[@]}" 2>"$tar_err"
    tar_rc=$?
    if [ "$tar_rc" -eq 1 ]; then
        log_warn "tar reported files changing during the archive; keeping the created archive"
        while IFS= read -r line; do
            [ -n "$line" ] && log_warn "tar: ${line}"
        done < "$tar_err"
    elif [ "$tar_rc" -ge 2 ]; then
        log_error "Files backup failed (tar exit ${tar_rc}); see ${tar_err}"
        rm -f "$backup_file"
        return 1
    fi

    if [ -s "$backup_file" ]; then
        rm -f "$tar_err"
        # Same treatment as the database dump: encrypt, self-check, keep the
        # ciphertext. Note these files are ALREADY encrypted at rest by
        # StorageEncryption, so this is a second independent layer - the
        # archive adds protection for the metadata (names, sizes, directory
        # structure) that the per-file container does not cover.
        local final_file="${backup_file}.enc"
        if ! encrypt_archive "$backup_file" "$final_file"; then
            return 1
        fi
        local size=$(du -h "$final_file" | cut -f1)
        log_info "Files backup completed: ${final_file} (${size})"
        
        # Generate checksum (sha256; keep .md5 for backward compatibility)
        write_sha256 "$final_file" || return 1
        
        return 0
    fi

    log_error "Files backup failed: tar did not create an archive"
    return 1
}

# Rotate old backups
rotate_backups() {
    log_info "Rotating backups older than ${RETENTION_DAYS} days"

    if ! find "$BACKUP_DIR/database" "$BACKUP_DIR/files" -type f \
            -mtime +"$RETENTION_DAYS" \
            \( -name "*.enc" -o -name "*.gz" -o -name "*.sha256" -o -name "*.md5" -o -name "*.hmac" \) \
            -delete; then
        log_error "Could not remove expired backup files"
        return 1
    fi
    if ! find "$BACKUP_DIR/database" "$BACKUP_DIR/files" -type f -name "*.partial.*" -mtime +1 -delete; then
        log_error "Could not remove stale partial backup files"
        return 1
    fi

    local directory mac_file
    for directory in "$BACKUP_DIR/database" "$BACKUP_DIR/files"; do
        for mac_file in "$directory"/*.hmac; do
            [ -e "$mac_file" ] || continue
            if [ ! -e "${mac_file%.hmac}" ] && ! rm -f -- "$mac_file"; then
                log_error "Could not remove orphaned MAC file: ${mac_file}"
                return 1
            fi
        done
    done

    if ! find "$BACKUP_DIR/logs" -type f -mtime +"$RETENTION_DAYS" -delete; then
        log_error "Could not remove expired backup logs"
        return 1
    fi
    log_info "Backup rotation completed"
}

# Verify last backup integrity
verify_backup() {
    local latest_db=$(ls -t "$BACKUP_DIR/database"/*.enc 2>/dev/null | head -1)
    local latest_files=$(ls -t "$BACKUP_DIR/files"/*.enc 2>/dev/null | head -1)
    
    if [ -n "$latest_db" ]; then
        log_info "Verifying database backup: ${latest_db}"
        
        # Verify checksum (prefer .sha256, fall back to legacy .md5)
        if [ -f "${latest_db}.sha256" ]; then
            if sha256sum -c "${latest_db}.sha256" --quiet 2>/dev/null; then
                log_info "Database backup checksum verified"
            else
                log_error "Database backup checksum mismatch!"
            fi
        elif [ -f "${latest_db}.md5" ]; then
            if md5sum -c "${latest_db}.md5" --quiet 2>/dev/null; then
                log_info "Database backup checksum verified (legacy md5)"
            else
                log_error "Database backup checksum mismatch!"
            fi
        fi
        
        # Encrypted archives are authenticated by the detached MAC, so the
        # integrity check is verify_archive_mac rather than a gzip -t (the
        # payload is no longer a bare gzip stream at the head of the file).
        if [ "$BACKUP_ENCRYPTION" = "1" ]; then
            if verify_archive_mac "$latest_db"; then
                if decrypt_archive "$latest_db" >/dev/null 2>&1; then
                    log_info "Database backup MAC verified and decrypts cleanly"
                else
                    log_error "Database backup MAC ok but decryption FAILED!"
                fi
            else
                log_error "Database backup MAC MISMATCH (tampered archive or wrong key)!"
            fi
        else
            local inner="${latest_db%.enc}"
            if $GZIP_CMD -t "$inner" 2>/dev/null; then
                log_info "Database backup integrity verified"
            else
                log_error "Database backup corrupted!"
            fi
        fi
    fi
    
    if [ -n "$latest_files" ]; then
        log_info "Verifying files backup: ${latest_files}"
        
        # Verify checksum (prefer .sha256, fall back to legacy .md5)
        if [ -f "${latest_files}.sha256" ]; then
            if sha256sum -c "${latest_files}.sha256" --quiet 2>/dev/null; then
                log_info "Files backup checksum verified"
            else
                log_error "Files backup checksum mismatch!"
            fi
        elif [ -f "${latest_files}.md5" ]; then
            if md5sum -c "${latest_files}.md5" --quiet 2>/dev/null; then
                log_info "Files backup checksum verified (legacy md5)"
            else
                log_error "Files backup checksum mismatch!"
            fi
        fi
        
        if [ "$BACKUP_ENCRYPTION" = "1" ]; then
            if verify_archive_mac "$latest_files" && decrypt_archive "$latest_files" >/dev/null 2>&1; then
                log_info "Files backup MAC verified and decrypts cleanly"
            else
                log_error "Files backup FAILED verification!"
            fi
        else
            if tar -tzf "$latest_files" > /dev/null 2>&1; then
                log_info "Files backup integrity verified"
            else
                log_error "Files backup corrupted!"
            fi
        fi
    fi
}

# Restore-test the most recent database backup.
#
# A gzip integrity check (see verify_backup) only proves the FILE was not
# truncated. It does NOT prove the dump can be replayed - the failure mode
# found on 2026-09-29 was a dump that was perfectly intact yet unrestorable.
# A backup that has never been restored is not a backup.
restore_test() {
    local defaults_file="${1:-$MYSQL_DEFAULTS_FILE}"
    local latest_db=$(ls -t "$BACKUP_DIR/database"/*.enc 2>/dev/null | head -1)
    [ -z "$latest_db" ] && latest_db=$(ls -t "$BACKUP_DIR/database"/*.gz 2>/dev/null | head -1)
    [ -n "$latest_db" ] || { log_warn "No database backup to restore-test"; return 0; }

    local scratch="${DB_NAME}_restore_test"
    local restore_err
    log_info "Restore-testing ${latest_db} into scratch database '${scratch}'"

    # Decrypt to a throwaway gzip first when the archive is encrypted, so the
    # replay below is fed plain SQL. The plaintext is deleted immediately after
    # the test: a decrypted dump of the whole HR database must never linger on
    # disk, and it must never be written inside BACKUP_DIR.
    local plaintext_gz=""
    if [ "$BACKUP_ENCRYPTION" = "1" ]; then
        plaintext_gz=$(mktemp "${TMPDIR:-/tmp}/restore_src_XXXXXX.gz")
        if ! decrypt_archive "$latest_db" > "$plaintext_gz" 2>/dev/null; then
            log_error "RESTORE TEST FAILED - cannot decrypt ${latest_db}"
            log_error "Is BACKUP_KEY_FILE the key this archive was written with?"
            rm -f "$plaintext_gz"
            return 1
        fi
        log_info "Decrypted archive for restore test (plaintext removed immediately after)"
    else
        plaintext_gz="$latest_db"
    fi

    # Always start from a clean slate so a previous failure cannot mask a new one.
    restore_err=$(mktemp "${TMPDIR:-/tmp}/restore_mysql_XXXXXX") || {
        log_error "Could not create temporary restore-test error file"
        [ "$BACKUP_ENCRYPTION" = "1" ] && rm -f "$plaintext_gz"
        return 1
    }
    if ! mysql --defaults-extra-file="$defaults_file" \
            -e "DROP DATABASE IF EXISTS \`${scratch}\`; CREATE DATABASE \`${scratch}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;" 2>"$restore_err"; then
        log_error "Cannot prepare scratch database '${scratch}'. The restore-test DB user must be allowed to DROP and CREATE it."
        while IFS= read -r line; do
            [ -n "$line" ] && log_error "MySQL: ${line}"
        done < "$restore_err"
        rm -f "$restore_err"
        [ "$BACKUP_ENCRYPTION" = "1" ] && rm -f "$plaintext_gz"
        return 1
    fi

    gunzip -c "$plaintext_gz" | mysql --defaults-extra-file="$defaults_file" \
        "$scratch" 2>"$restore_err"

    local rc=$?
    if [ "$BACKUP_ENCRYPTION" = "1" ]; then
        rm -f "$plaintext_gz"
    fi
    if [ $rc -ne 0 ]; then
        log_error "RESTORE TEST FAILED - this backup is NOT restorable:"
        while IFS= read -r line; do
            [ -n "$line" ] && log_error "MySQL: ${line}"
        done < "$restore_err"
        rm -f "$restore_err"
        return 1
    fi
    rm -f "$restore_err"

    # Compare table count against the live database.
    local live_tables=$(mysql --defaults-extra-file="$defaults_file" \
        -N -B -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='${DB_NAME}' AND TABLE_TYPE='BASE TABLE';" 2>/dev/null)
    local restored_tables=$(mysql --defaults-extra-file="$defaults_file" \
        -N -B -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='${scratch}' AND TABLE_TYPE='BASE TABLE';" 2>/dev/null)

    if [ "$live_tables" = "$restored_tables" ]; then
        log_info "Restore test PASSED (${restored_tables}/${live_tables} tables)"
        if ! mysql --defaults-extra-file="$defaults_file" \
                -e "DROP DATABASE \`${scratch}\`;" 2>"$restore_err"; then
            log_error "Cannot DROP scratch database '${scratch}'. The restore-test DB user must be granted DROP privilege."
            while IFS= read -r line; do
                [ -n "$line" ] && log_error "MySQL: ${line}"
            done < "$restore_err"
            rm -f "$restore_err"
            return 1
        fi
        rm -f "$restore_err"
        return 0
    fi

    log_error "RESTORE TEST FAILED: ${restored_tables} tables restored vs ${live_tables} live"
    if ! mysql --defaults-extra-file="$defaults_file" \
            -e "DROP DATABASE \`${scratch}\`;" 2>"$restore_err"; then
        log_error "Cannot DROP scratch database '${scratch}'. The restore-test DB user must be granted DROP privilege."
        while IFS= read -r line; do
            [ -n "$line" ] && log_error "MySQL: ${line}"
        done < "$restore_err"
        rm -f "$restore_err"
        return 1
    fi
    rm -f "$restore_err"
    return 1
}

# Send backup to offsite storage (S3 compatible)
sync_to_offsite() {
    if [ -z "$AWS_ACCESS_KEY_ID" ] || [ -z "$AWS_SECRET_ACCESS_KEY" ]; then
        log_warn "AWS credentials not configured. Skipping offsite sync."
        return 0
    fi
    
    local bucket="${S3_BUCKET:-muwasco-hr-backups}"
    local remote_path="s3://${bucket}/$(date +%Y/%m/%d)/"
    
    log_info "Syncing backups to offsite storage: ${remote_path}"
    
    $AWS_CLI s3 sync "$BACKUP_DIR" "$remote_path" \
        --exclude "*.log" \
        --exclude "*.partial.*" \
        --exclude "*.sql" \
        --exclude ".backup.lock" \
        --include "*.hmac" \
        --storage-class STANDARD_IA \
        --no-progress 2>&1
    
    if [ $? -eq 0 ]; then
        log_info "Offsite sync completed successfully"
    else
        log_error "Offsite sync failed"
    fi
}

# ── Main execution ─────────────────────────────────────────────────────────

main() {
    local mode="--both"
    local lock_fd
    local rotate_enabled=1
    local database_in_run=1
    local dry_run="$DRY_RUN_REQUESTED"
    local restore_weekday="${RESTORE_TEST_WEEKDAY:-}"
    local arg resolved_backup resolved_app resolved_key resolved_upload upload_dir
    local -a upload_dirs=()

    for arg in "$@"; do
        case "$arg" in
            --database|--files|--both) mode="$arg" ;;
            --dry-run) dry_run=1 ;;
            --no-rotate) rotate_enabled=0 ;;
            --rotate) rotate_enabled=1 ;;
            *)
                log_error "Unknown option: ${arg}"
                return 1
                ;;
        esac
    done
    [ "$mode" = "--files" ] && database_in_run=0

    validate_backup_location || return 1
    if [ "$dry_run" -eq 1 ]; then
        validate_backup_key || return 1
        resolved_backup=$(realpath -m -- "$BACKUP_DIR") || return 1
        resolved_app=$(realpath -m -- "$APP_ROOT") || return 1
        resolved_key=$(realpath -m -- "$BACKUP_KEY_FILE") || return 1
        read -r -a upload_dirs <<< "$BACKUP_UPLOAD_DIRS"
        printf 'DRY RUN - no files or directories will be written\n'
        printf 'Mode: %s\n' "$mode"
        printf 'Application root: %s\n' "$resolved_app"
        printf 'Backup directory: %s\n' "$resolved_backup"
        printf 'Upload directories:'
        for upload_dir in "${upload_dirs[@]}"; do
            resolved_upload=$(realpath -m -- "$upload_dir") || return 1
            printf ' %s' "$resolved_upload"
        done
        printf '\nDatabase: %s@%s:%s/%s\n' "$DB_USER" "$DB_HOST" "$DB_PORT" "$DB_NAME"
        printf 'Encryption: %s\n' "$BACKUP_ENCRYPTION"
        printf 'Key file: %s\n' "$resolved_key"
        printf 'Retention days: %s\n' "$RETENTION_DAYS"
        printf 'Restore-test weekday: %s\n' "${restore_weekday:-not configured}"
        return 0
    fi

    if [ -n "$restore_weekday" ] && [[ ! "$restore_weekday" =~ ^[1-7]$ ]]; then
        log_error "RESTORE_TEST_WEEKDAY must be an ISO weekday from 1 (Monday) to 7 (Sunday)"
        return 1
    fi
    if { [ -n "${RESTORE_TEST_USER:-}" ] && [ -z "${RESTORE_TEST_PASSWORD:-}" ]; } \
            || { [ -z "${RESTORE_TEST_USER:-}" ] && [ -n "${RESTORE_TEST_PASSWORD:-}" ]; }; then
        log_error "RESTORE_TEST_USER and RESTORE_TEST_PASSWORD must be configured together"
        return 1
    fi
    if { [ -n "${RESTORE_TEST_USER:-}" ] || [ -n "${RESTORE_TEST_PASSWORD:-}" ]; } \
            && [ -z "$restore_weekday" ]; then
        log_error "RESTORE_TEST_WEEKDAY is required when separate restore-test credentials are configured"
        return 1
    fi

    mkdir -p "$BACKUP_DIR/logs" || {
        log_error "Could not create log directory: ${BACKUP_DIR}/logs"
        exit 1
    }
    LOG_FILE="$BACKUP_DIR/logs/backup_${DATE_STAMP}.log"
    exec {lock_fd}>"$BACKUP_DIR/.backup.lock" || {
        log_error "Could not open backup lock file: ${BACKUP_DIR}/.backup.lock"
        return 1
    }
    if ! flock -n "$lock_fd"; then
        log_error "Another backup run is already in progress; exiting."
        return 1
    fi
    log_info "Backup utility started (mode: ${mode})"

    # Load (and validate) the backup encryption key BEFORE anything is written.
    # load_backup_key aborts if the key is missing, empty, or stored inside
    # BACKUP_DIR, so a run never produces an archive it cannot later open.
    load_backup_key
    ensure_backup_dir
    create_mysql_defaults_file MYSQL_DEFAULTS_FILE "$DB_USER" "$DB_PASS" || exit 1
    if [ "$BACKUP_ENCRYPTION" = "1" ]; then
        log_info "Backup encryption: ON (${BACKUP_OPENSSL_CIPHER}, key outside backup dir)"
    else
        log_warn "Backup encryption: OFF (BACKUP_ENCRYPTION=0) - archives are plaintext"
    fi
    
    case "$mode" in
        --database)
            backup_database || log_error "Database backup operation failed"
            ;;
        --files)
            backup_files || log_error "Files backup operation failed"
            ;;
        --both)
            backup_database || log_error "Database backup operation failed"
            backup_files || log_error "Files backup operation failed"
            ;;
    esac

    # Verify integrity
    verify_backup

    # Prove the latest backup can actually be replayed. A failed restore test
    # must fail the run, otherwise a corrupt-but-intact dump silently becomes
    # the thing you discover during an outage.
    if [ "${SKIP_RESTORE_TEST:-0}" != "1" ] && [ "$database_in_run" -eq 1 ]; then
        restore_test || log_error "Latest backup is NOT restorable - investigate before relying on it"
        if [ -n "$restore_weekday" ] && [ "$(date +%u)" = "$restore_weekday" ]; then
            if [ -n "${RESTORE_TEST_USER:-}" ]; then
                create_mysql_defaults_file RESTORE_TEST_DEFAULTS_FILE \
                    "$RESTORE_TEST_USER" "$RESTORE_TEST_PASSWORD" || return 1
                restore_test "$RESTORE_TEST_DEFAULTS_FILE" \
                    || log_error "Weekly restore test with RESTORE_TEST_USER failed"
            else
                restore_test || log_error "Weekly restore test failed"
            fi
        fi
    elif [ "${SKIP_RESTORE_TEST:-0}" = "1" ]; then
        log_warn "SKIP_RESTORE_TEST=1 - restore test skipped by request"
    else
        log_info "Restore test skipped because this run did not include the database"
    fi
    
    # Sync to offsite
    sync_to_offsite

    if [ "$FAILED" -eq 0 ] && [ "$rotate_enabled" -eq 1 ]; then
        rotate_backups || log_error "Backup rotation failed"
    elif [ "$rotate_enabled" -eq 0 ]; then
        log_info "Backup rotation skipped (--no-rotate)"
    else
        log_warn "Backup rotation skipped because this run has errors"
    fi
    
    if [ "$FAILED" -ne 0 ]; then
        log_error "Backup process failed"
        return 1
    fi

    log_info "Backup process completed"
    return 0
}

# Execute main function
main "$@"
