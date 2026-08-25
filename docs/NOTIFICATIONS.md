# Attendance Notification System (Web Push + SMS)

Production-ready attendance clock-in reminders for the MUWASCO HR System.
One eligibility decision drives both channels:

```
cron/attendance_reminders.php  (every 5 min, idempotent)
        │
AttendanceReminderEligibilityService   ← THE single business decision
        │
NotificationRouter ──► WebPushChannel ──► employee browsers (Service Worker)
                   └──► SmsChannel ──► httpSMS gateway ──► Android phone ──► SMS
```

## 1. How it decides (eligibility)

An employee is reminded only when ALL are true (server-side, always):

1. `employees.employee_status = 'active'` (NULL treated as active, mirroring
   existing roster logic) **and** has a linked user account
2. Today is a working day (Sat/Sun excluded — same rule as LeaveCalculationService)
3. Not a public holiday (`holidays` table, incl. recurring month/day matches)
4. Not on approved leave (`leave_applications.status='approved'`, start ≤ today ≤ end)
5. No attendance row for today (`DATE(clock_in) = today` in Africa/Nairobi)

Reason codes (`ELIGIBLE`, `EMPLOYEE_INACTIVE`, `NOT_WORKING_DAY`, `PUBLIC_HOLIDAY`,
`ON_LEAVE`, `ALREADY_CLOCKED_IN`) are stored so HR can audit exactly why someone
did or did not receive a message.

**Midnight safety:** attendance checks use `DATE(clock_in)` = *today*, so an open
yesterday session never blocks today's reminder (the auto-clockout job closes it).

## 2. Delivery stages & policy (all env-configurable)

| Stage | When | Channel |
|---|---|---|
| `reminder_1` | `ATTENDANCE_REMINDER_TIME` (default 08:00) | Web Push |
| `sms_fallback` | reminder + `ATTENDANCE_SMS_FALLBACK_DELAY_MINUTES` (default 15) | SMS |

* SMS policy `ATTENDANCE_SMS_POLICY=fallback|always` — fallback sends SMS only
  when push was not delivered (no subscription / disabled / failed).
* Before **every** SMS send, eligibility is re-evaluated fresh — an employee who
  clocks in after the push can never receive the SMS.
* Cost caps: `ATTENDANCE_MAX_SMS_PER_DAY` (2), retry budget per message
  `ATTENDANCE_MAX_SMS_ATTEMPTS` (3, temporary failures only).
* Optional second push: `ATTENDANCE_SECOND_REMINDER_ENABLED=false` by default.
* Duplicate-proofing: `notification_logs` UNIQUE(user_id, business_date,
  notification_type, channel, stage) — double cron ticks cannot double-send.
* Invalid push endpoints (HTTP 404/410) are revoked automatically and purged
  after 30 days. One failing recipient never aborts the batch.

## 3. Scheduler setup

The cron is designed to run every 5 minutes; duplicate ticks are harmless no-ops.

Windows Task Scheduler → New Task:
* Program: `C:\xampp\php\php.exe`
* Arguments: `C:\xampp\htdocs\hrdemo\backend\cron\attendance_reminders.php`
* Start in: `C:\xampp\htdocs\hrdemo\backend\cron`
* Trigger: Daily, start 00:00, repeat every 5 minutes indefinitely

Manual commands:

```bash
php backend/cron/attendance_reminders.php                # process due stages
php backend/cron/attendance_reminders.php --dry-run      # report only
php backend/cron/attendance_reminders.php --employee=12  # single employee
```

There is no separate queue server; the database *is* the queue (claims via the
unique key; `reapStalePending()` fails rows stuck mid-send by a crashed process).

## 4. Web Push configuration

* Library: `minishlink/web-push` (composer). PHP needs `ext-gmp` (enabled in
  XAMPP php.ini; on Linux `apt install php-gmp`) plus `ext-curl`, `ext-openssl`.
* Keys live in `.env`: `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT`.
  The private key NEVER reaches the browser; only the public key is exposed via
  `GET /api/push/vapid-public-key`.
* Regenerate a pair (dev box needed OPENSSL_CONF for keygen):
  `set OPENSSL_CONF=C:\xampp\php\extras\ssl\openssl.cnf && php -r "require 'vendor/autoload.php'; print_r(Minishlink\WebPush\VAPID::createVapidKeys());"`
* Service worker: `frontend/public/sw.js` → deployed to the SPA origin root.
  Handles `push` and `notificationclick` (focuses/opens `/attendance`).
* Employees opt in at **Settings → Notifications** ("Enable on this device").
  Permission states handled: default / granted / denied (never re-prompted) /
  unsupported. Multiple devices per employee; each removable individually.
* HTTPS is REQUIRED in production for push (localhost allowed for development).

## 5. SMS configuration (httpSMS)

Driver: `App\Services\Notification\Sms\HttpSmsProvider` behind
`SmsProviderInterface` (swap providers without touching attendance logic).

1. Install the httpSMS Android app on the sending phone and sign in.
2. Create an API key at <https://httpsms.com/settings>.
3. `.env`: SMS_PROVIDER=httpsms, HTTPSMS_BASE_URL=https://api.httpsms.com,
   HTTPSMS_API_KEY=<key>, HTTPSMS_SENDER_PHONE=+2547XXXXXXXX (the sender phone).
* Recipients come ONLY from `employees.phone` (server-side), normalised to
  E.164 by PhoneNormalizer (`07…`, `01…`, `254…`, `+254…`; invalid ⇒ skipped).
* Every send carries `request_id = att-<user>-<date>-<stage>` as a
  provider-side idempotency echo.
* Template env-overridable via ATTENDANCE_SMS_BODY with variables
  {employee_name} {date} {organization_name}.

## 6. API endpoints

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/api/notification-preferences` | own session | effective prefs + masked phone |
| PUT | `/api/notification-preferences` | own session | save push/sms toggles |
| GET | `/api/push/vapid-public-key` | public | application server key |
| GET | `/api/push/subscriptions` | own session | list own devices |
| POST | `/api/push/subscribe` | session + 20/h limit | register device |
| DELETE | `/api/push/subscribe` | session | remove device |
| GET | `/api/admin/notifications/stats` | `notifications.view` | daily ops dashboard data |
| GET | `/api/admin/notifications/audit/{employeeId}` | `notifications.view` | why did X get/not get notified |
| POST | `/api/admin/notifications/test-send` | `notifications.manage` + 5/h | controlled test (audited; SMS may cost) |

Rate limiting uses a file-backed counter
(`backend/storage/cache/rate-limits/`) because API requests run after
`session_write_close()` (the session limiter in SecurityMiddleware cannot persist).

## 7. Database

Migrations (idempotent — apply via
`php backend/database/apply_notification_migrations.php`):
023_push_subscriptions.sql · 024_notification_preferences.sql ·
025_notification_logs.sql (dedup unique key + indexes, FK users.id CASCADE).

## 8. Troubleshooting / audit

"Why didn't John get an SMS at 08:15?" →
`GET /api/admin/notifications/audit/{employeeId}` returns the eligibility
reason, day context (weekend/holiday/leave), attendance record and every log
row with status + failure reason (e.g. `Eligibility changed: ALREADY_CLOCKED_IN`).
All sends/skips/failures live in `notification_logs`; admin test-sends also land
in `audit_logs` (module *Notifications*).

## 9. Production deployment checklist

1. HTTPS enabled (Web Push hard requirement).
2. `.env`: VAPID trio + httpSMS credentials + policy knobs (§4/§5).
3. Run migration applier (§7).
4. Task Scheduler entry (§3) created; verified with `--dry-run`.
5. Frontend rebuilt (`npm run build`); `dist/*` deployed to SPA root INCLUDING
   `sw.js`, `manifest.webmanifest`, `favicon.ico`.
6. Real-device push test: Settings → Notifications → Enable on this device →
   Admin test-send (push) → notification received → tap opens Attendance page.
7. Real-SMS test: Admin test-send (sms); verify charges acceptable and
   `notification_logs.provider_message_id` recorded.

## 10. Known limitations

* Employees without a linked user account are not candidates (logs FK to users).
* Working days = Mon–Fri org-wide (matches existing rules; no shift rosters yet).
* httpSMS delivered/failed callbacks can be added later as a webhook endpoint
  updating `notification_logs` by provider message id (send status is stored now).
* Legacy PHPUnit suites referencing `admin_hrdemo_test` need that DB provisioned
  to run (pre-existing; unrelated to this feature).
* Benign CLI warnings from `vendor/thecodingmachine/safe`: two compile-time
  notices ("resource"/"integer" pseudo-types) appear whenever its generated
  wrappers load on PHP 8.0. They originate in `web-token/jwt-util-ecc` v2
  (required for VAPID signing/payload encryption), which pins safe `^0.1.14`,
  and are loaded eagerly via ~87 composer `files` entries. Purely cosmetic -
  wrappers function correctly, web output is unaffected (display_errors off),
  and bootstrap suppresses them around autoload. NEVER patch files under
  `vendor/`; upgrading requires a coordinated web-token bump (risk not
  justified by cosmetics). The `generator/` subfolder inside the package is an
  inert dev-tool manifest that the v0.1.16 dist ships accidentally.

