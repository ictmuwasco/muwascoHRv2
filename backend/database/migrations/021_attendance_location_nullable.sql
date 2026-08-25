-- 021_attendance_location_nullable.sql
-- ------------------------------------------------------------------
-- Device-inclusive attendance: allow records WITHOUT a device GPS fix.
--
-- Desktop PCs and wired laptops usually cannot produce a browser
-- Geolocation fix (no GPS chip; Wi-Fi triangulation needs an internet
-- route to location services, which isolated LANs do not have).
-- Such clock-ins are recorded with NULL lat/lng so HR reporting can
-- identify them as "location not verified" (lat IS NULL).
--
-- Nullable columns are backwards compatible: existing rows and all
-- existing GPS-based writes are unaffected.
--
-- Apply (one time):
--   mysql -u root -p muwasco < backend/database/migrations/021_attendance_location_nullable.sql
-- ------------------------------------------------------------------

ALTER TABLE attendance
  MODIFY lat DECIMAL(10,8) NULL,
  MODIFY lng DECIMAL(11,8) NULL;
