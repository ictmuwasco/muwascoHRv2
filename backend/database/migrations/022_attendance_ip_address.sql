-- 022_attendance_ip_address.sql
-- ------------------------------------------------------------------
-- Always-on origin evidence for attendance records.
--
-- Even when a device cannot provide GPS coordinates (desktop PCs on
-- isolated networks), we still record the client's IP address. Office
-- workstations share the office public IP, so HR retains a strong
-- network-origin signal for every record - verified or not.
--
-- VARCHAR(45) accommodates IPv6. Nullable: rows written before this
-- migration keep NULL.
--
-- Apply (one time):
--   mysql -u root -p muwasco < backend/database/migrations/022_attendance_ip_address.sql
-- ------------------------------------------------------------------

ALTER TABLE attendance
  ADD COLUMN ip_address VARCHAR(45) NULL AFTER accuracy;
