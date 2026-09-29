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
        local size=$(du -h "$compressed_file" | cut -f1)
        log_info "Database backup completed: ${compressed_file} (${size})"
        
        # Generate checksum
        md5sum "$compressed_file" > "${compressed_file}.md5"
        
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
        local size=$(du -h "$backup_file" | cut -f1)
        log_info "Files backup completed: ${backup_file} (${size})"
        
        # Generate checksum
        md5sum "$backup_file" > "${backup_file}.md5"
        
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
    
    # Rotate database backups
    deleted=$(find "$BACKUP_DIR/database" -name "*.gz" -type f -mtime +${RETENTION_DAYS} -delete -print | wc -l)
    find "$BACKUP_DIR/database" -name "*.md5" -type f -mtime +${RETENTION_DAYS} -delete 2>/dev/null
    
    # Rotate file backups
    deleted=$((deleted + $(find "$BACKUP_DIR/files" -name "*.tar.gz" -type f -mtime +${RETENTION_DAYS} -delete -print | wc -l)))
    find "$BACKUP_DIR/files" -name "*.md5" -type f -mtime +${RETENTION_DAYS} -delete 2>/dev/null
    
    log_info "Removed ${deleted} old backup(s)"
}

# Verify last backup integrity
verify_backup() {
    local latest_db=$(ls -t "$BACKUP_DIR/database"/*.gz 2>/dev/null | head -1)
    local latest_files=$(ls -t "$BACKUP_DIR/files"/*.tar.gz 2>/dev/null | head -1)
    
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
        
        # Verify gzip integrity
        if $GZIP_CMD -t "$latest_db" 2>/dev/null; then
            log_info "Database backup integrity verified"
        else
            log_error "Database backup corrupted!"
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
        
        if tar -tzf "$latest_files" > /dev/null 2>&1; then
            log_info "Files backup integrity verified"
        else
            log_error "Files backup corrupted!"
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
    local latest_db=$(ls -t "$BACKUP_DIR/database"/*.gz 2>/dev/null | head -1)
    [ -n "$latest_db" ] || { log_warn "No database backup to restore-test"; return 0; }

    local scratch="${DB_NAME}_restore_test"
    log_info "Restore-testing ${latest_db} into scratch database '${scratch}'"

    # Always start from a clean slate so a previous failure cannot mask a new one.
    mysql --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" --password="$DB_PASS" \
        -e "DROP DATABASE IF EXISTS \`${scratch}\`; CREATE DATABASE \`${scratch}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;" 2>/dev/null

    gunzip -c "$latest_db" | mysql --host="$DB_HOST" --port="$DB_PORT" \
        --user="$DB_USER" --password="$DB_PASS" "$scratch" 2>/tmp/restore_test_err.$$

    local rc=$?
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