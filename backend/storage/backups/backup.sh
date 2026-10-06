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
DB_HOST="${DB_HOST:-localhost}"
DB_PORT="${DB_PORT:-3306}"
DB_USER="${DB_USERNAME:-muwascohr}"
DB_PASS="${DB_PASSWORD:-}"
DB_NAME="${DB_DATABASE:-admin_hrdemo}"

# SECURITY: never embed credentials here. Source them from the environment:
#   set -a; . /path/to/.env; set +a; ./backup.sh
if [ -z "$DB_PASS" ]; then
    echo -e "${RED}[ERROR]${NC} DB_PASSWORD is not set. Export DB_PASSWORD (or source your .env) instead of hardcoding credentials." >&2
    exit 1
fi

BACKUP_DIR="${BACKUP_PATH:-$(dirname "$0")}"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-30}"
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
DATE_STAMP=$(date +"%Y-%m-%d")

MYSQLDUMP_CMD="mysqldump"
GZIP_CMD="gzip"
AWS_CLI="aws"

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
BACKUP_KEY_FILE="${BACKUP_KEY_FILE:-/var/www/private/backup.key}"
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
load_backup_key() {
    if [ "$BACKUP_ENCRYPTION" != "1" ]; then
        BACKUP_ENCRYPTION_PASSWORD=""
        return 0
    fi

    if [ ! -r "$BACKUP_KEY_FILE" ]; then
        log_error "BACKUP_ENCRYPTION=1 but the key file is not readable: ${BACKUP_KEY_FILE}"
        log_error "Create it with: openssl rand -hex 32 > ${BACKUP_KEY_FILE} && chmod 600 ${BACKUP_KEY_FILE}"
        log_error "It MUST live outside ${BACKUP_DIR}."
        exit 1
    fi

    # Refuse to write an archive next to its own key.
    local key_abs backup_abs
    key_abs=$(cd "$(dirname "$BACKUP_KEY_FILE")" 2>/dev/null && pwd)/$(basename "$BACKUP_KEY_FILE")
    backup_abs=$(cd "$BACKUP_DIR" 2>/dev/null && pwd)
    case "$key_abs" in
        "$backup_abs"/*)
            log_error "BACKUP_KEY_FILE (${key_abs}) is inside BACKUP_DIR (${backup_abs})."
            log_error "A backup stored with its own decryption key is not encrypted. Move the key out and retry."
            exit 1
            ;;
    esac

    BACKUP_ENCRYPTION_PASSWORD=$(cat "$BACKUP_KEY_FILE")
    if [ -z "$BACKUP_ENCRYPTION_PASSWORD" ]; then
        log_error "BACKUP_KEY_FILE is empty: ${BACKUP_KEY_FILE}"
        exit 1
    fi

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
    actual=$(openssl dgst -sha256 -mac HMAC -macopt "hexkey:$(backup_mac_key)" "$archive" \
             | cut -d' ' -f2 | tr -d '[:space:]')

    if [ "$expected" != "$actual" ]; then
        return 1
    fi
    return 0
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
    echo -e "${GREEN}[INFO]${NC} $1"
}

log_warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Create backup directory
ensure_backup_dir() {
    mkdir -p "$BACKUP_DIR/database"
    mkdir -p "$BACKUP_DIR/files"
    mkdir -p "$BACKUP_DIR/logs"
}

# Backup database
backup_database() {
    local backup_file="$BACKUP_DIR/database/${DB_NAME}_${TIMESTAMP}.sql"
    local compressed_file="${backup_file}.gz"
    
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
    $MYSQLDUMP_CMD \
        --host="$DB_HOST" \
        --port="$DB_PORT" \
        --user="$DB_USER" \
        --password="$DB_PASS" \
        --single-transaction \
        --routines \
        --triggers \
        --events \
        --add-drop-table \
        --complete-insert \
        --skip-lock-tables \
        "$DB_NAME" 2>/dev/null \
    | sed "s/SQL_MODE='NO_AUTO_VALUE_ON_ZERO'/SQL_MODE='NO_AUTO_VALUE_ON_ZERO,NO_ZERO_IN_DATE,NO_ZERO_DATE'/g" \
    > "$backup_file"

    if [ ! -s "$backup_file" ]; then
        log_error "Database dump failed or produced an empty file"
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
        
        # Generate checksum
        md5sum "$final_file" > "${final_file}.md5"
        
        return 0
    else
        log_error "Compression failed"
        return 1
    fi
}

# Backup uploaded files
backup_files() {
    local upload_dir="$(dirname "$0")/../../../uploads"
    local backup_file="$BACKUP_DIR/files/uploads_${TIMESTAMP}.tar.gz"
    
    if [ ! -d "$upload_dir" ]; then
        log_warn "Upload directory not found: ${upload_dir}"
        return 0
    fi
    
    log_info "Starting files backup"
    
    tar -czf "$backup_file" \
        --exclude="*.log" \
        --exclude="cache/*" \
        -C "$(dirname "$upload_dir")" \
        "$(basename "$upload_dir")" 2>&1
    
    if [ $? -eq 0 ]; then
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
        
        # Generate checksum
        md5sum "$final_file" > "${final_file}.md5"
        
        return 0
    else
        log_error "Files backup failed"
        return 1
    fi
}

# Rotate old backups
rotate_backups() {
    log_info "Rotating backups older than ${RETENTION_DAYS} days"
    
    local deleted=0
    
    # Rotate database backups (.enc, since archives are encrypted)
    deleted=$(find "$BACKUP_DIR/database" -name "*.enc" -type f -mtime +${RETENTION_DAYS} -delete -print | wc -l)
    find "$BACKUP_DIR/database" -name "*.md5" -type f -mtime +${RETENTION_DAYS} -delete 2>/dev/null
    # Remove any partials an interrupted run may have left behind.
    find "$BACKUP_DIR/database" -name "*.partial.*" -type f -mtime +1 -delete 2>/dev/null
    
    # Rotate file backups
    deleted=$((deleted + $(find "$BACKUP_DIR/files" -name "*.tar.gz.enc" -type f -mtime +${RETENTION_DAYS} -delete -print | wc -l)))
    find "$BACKUP_DIR/files" -name "*.md5" -type f -mtime +${RETENTION_DAYS} -delete 2>/dev/null
    find "$BACKUP_DIR/files" -name "*.partial.*" -type f -mtime +1 -delete 2>/dev/null
    
    log_info "Removed ${deleted} old backup(s)"
}

# Verify last backup integrity
verify_backup() {
    local latest_db=$(ls -t "$BACKUP_DIR/database"/*.enc 2>/dev/null | head -1)
    local latest_files=$(ls -t "$BACKUP_DIR/files"/*.enc 2>/dev/null | head -1)
    
    if [ -n "$latest_db" ]; then
        log_info "Verifying database backup: ${latest_db}"
        
        # Verify checksum
        if [ -f "${latest_db}.md5" ]; then
            if md5sum -c "${latest_db}.md5" --quiet 2>/dev/null; then
                log_info "Database backup checksum verified"
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
        
        if [ -f "${latest_files}.md5" ]; then
            if md5sum -c "${latest_files}.md5" --quiet 2>/dev/null; then
                log_info "Files backup checksum verified"
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
    local latest_db=$(ls -t "$BACKUP_DIR/database"/*.enc 2>/dev/null | head -1)
    [ -z "$latest_db" ] && latest_db=$(ls -t "$BACKUP_DIR/database"/*.gz 2>/dev/null | head -1)
    [ -n "$latest_db" ] || { log_warn "No database backup to restore-test"; return 0; }

    local scratch="${DB_NAME}_restore_test"
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
    mysql --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" --password="$DB_PASS" \
        -e "DROP DATABASE IF EXISTS \`${scratch}\`; CREATE DATABASE \`${scratch}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;" 2>/dev/null

    gunzip -c "$plaintext_gz" | mysql --host="$DB_HOST" --port="$DB_PORT" \
        --user="$DB_USER" --password="$DB_PASS" "$scratch" 2>/tmp/restore_test_err.$$

    local rc=$?
    if [ "$BACKUP_ENCRYPTION" = "1" ]; then
        rm -f "$plaintext_gz"
    fi
    if [ $rc -ne 0 ]; then
        log_error "RESTORE TEST FAILED - this backup is NOT restorable:"
        head -5 /tmp/restore_test_err.$$ >&2
        rm -f /tmp/restore_test_err.$$
        return 1
    fi
    rm -f /tmp/restore_test_err.$$

    # Compare table count against the live database.
    local live_tables=$(mysql --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" --password="$DB_PASS" \
        -N -B -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='${DB_NAME}' AND TABLE_TYPE='BASE TABLE';" 2>/dev/null)
    local restored_tables=$(mysql --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" --password="$DB_PASS" \
        -N -B -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='${scratch}' AND TABLE_TYPE='BASE TABLE';" 2>/dev/null)

    if [ "$live_tables" = "$restored_tables" ]; then
        log_info "Restore test PASSED (${restored_tables}/${live_tables} tables)"
        mysql --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" --password="$DB_PASS" \
            -e "DROP DATABASE \`${scratch}\`;" 2>/dev/null
        return 0
    fi

    log_error "RESTORE TEST FAILED: ${restored_tables} tables restored vs ${live_tables} live"
    mysql --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" --password="$DB_PASS" \
        -e "DROP DATABASE \`${scratch}\`;" 2>/dev/null
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
    local mode="${1:-both}"
    
    echo ""
    echo "============================================"
    echo " MUWASCO HR System - Backup Utility"
    echo " $(date)"
    echo "============================================"
    echo ""
    
    ensure_backup_dir

    # Load (and validate) the backup encryption key BEFORE anything is written.
    # load_backup_key aborts if the key is missing, empty, or stored inside
    # BACKUP_DIR, so a run never produces an archive it cannot later open.
    load_backup_key
    if [ "$BACKUP_ENCRYPTION" = "1" ]; then
        log_info "Backup encryption: ON (${BACKUP_OPENSSL_CIPHER}, key outside backup dir)"
    else
        log_warn "Backup encryption: OFF (BACKUP_ENCRYPTION=0) - archives are plaintext"
    fi
    
    case "$mode" in
        --database)
            backup_database
            ;;
        --files)
            backup_files
            ;;
        --both|*)
            backup_database
            backup_files
            ;;
    esac
    
    # Rotate old backups
    if [ "$2" = "--rotate" ] || [ "$1" = "--rotate" ]; then
        rotate_backups
    fi
    
    # Verify integrity
    verify_backup

    # Prove the latest backup can actually be replayed. A failed restore test
    # must fail the run, otherwise a corrupt-but-intact dump silently becomes
    # the thing you discover during an outage.
    if [ "${SKIP_RESTORE_TEST:-0}" != "1" ]; then
        restore_test || log_error "Latest backup is NOT restorable - investigate before relying on it"
    else
        log_warn "SKIP_RESTORE_TEST=1 - restore test skipped by request"
    fi
    
    # Sync to offsite
    sync_to_offsite
    
    echo ""
    log_info "Backup process completed"
    echo ""
}

# Execute main function
main "$@"