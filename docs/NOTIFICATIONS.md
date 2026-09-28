# Notification System

Two subsystems share one queue table. Read the part you need:

* **§A–§J (below)** — the original **attendance** clock-in reminders (push + SMS).
* **§K** — the **event** notification platform (leave, appraisal, meetings,
  financial year, leave roster): in-app + email, drained by a worker.

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

---

# §K. Event notification platform (leave, appraisal, meetings, FY, roster)

Transactional notifications for the rest of the system. The database is still
the queue, but the shape of the problem is different from attendance, and three
things changed because of it.

```
LeaveApprovalService / AppraisalWorkflowService / MeetingService / FY + roster cron
        │  (must return fast — the employee is waiting on an HTTP response)
        ▼
NotificationDispatcher::dispatch()   ← resolve recipient, apply policy, claim
        │                               a dedupe row. NEVER sends.
        ▼
notification_logs  (user_id, dedupe_key, payload, status)
        │  written by `dispatch()`; drained every minute by the worker
        ▼
cron/notification_worker.php  ──► EmailChannel ──► SMTP
                             └─► in-app (written inline at dispatch instead)
```

## K.1 Why dispatching and sending are separate

Attendance reminders are scheduled and nobody is waiting. Event notifications
are the opposite: the employee is staring at a spinner while their leave
request saves. An SMTP server that takes 30 seconds to time out would add 30
seconds to that request, so `dispatch()` only writes rows. The worker sends.

`NotificationDispatcher::dispatch()` returns immediately after a handful of
INSERTs, and the in-app notification is written inline at the same time so the
bell is instant rather than waiting on the worker's next tick.

## K.2 Dedupe keys (migration 094)

Migration 025 deduplicated scheduled reminders on
`UNIQUE (user_id, business_date, notification_type, channel, stage)`. That tuple
cannot express an *event*: two leave approvals for one person on one day are
the same tuple, so the second would be silently swallowed as a "duplicate" and
never sent.

`dedupe_key` replaces it as the identity. Callers pass an explicit key:

```php
"leave:{$applicationId}:applied"
"leave:{$applicationId}:approved"
"leave:{$applicationId}:rejected"
"appraisal:{$appraisalId}:{$status}:{$level}"
"meeting:{$meetingId}:{$recipientUserId}"
```

The channel is appended internally (`{$key}|{$channel}`), so re-running a
trigger never re-queues one channel but re-enables another.

Rows written before the migration were backfilled to
`{date}|{type}|{stage}`, which is exactly the old tuple — so attendance
notifications written before and after 094 still dedupe against each other.
`NotificationLogRepository::scheduledDedupeKey()` reproduces that format and
`claim()` routes through it, so the attendance path is unchanged.

## K.3 `payload` (migration 095)

A queue row records *who* and *what type*, not *what it said*. The payload is
rendered at dispatch time, where the business context exists (the leave service
knows the dates; the worker does not) and stored as JSON. Re-deriving the text
in the worker is exactly the drift this prevents.

## K.4 Recipient resolution

`users.employee_id` is a varchar that may hold **either** `employees.id` **or** a
staff number, depending on how the row was created. Passing an `employees.id`
straight into `sendInApp()` (which expects a `users.id`) is the bug that
`NotificationDispatcher::resolveRecipientByEmployeeId()` exists to prevent: it
tries the id linkage, then the staff-number linkage, then matches on email, and
only then gives up. Use it from every new call site.

## K.5 Preferences

`notification_preferences` is optional per user. **No row means enabled** — an
absent row means the employee never expressed a choice, which is not the same as
opting out. Only an explicit `0` blocks a channel. This matches the intent
recorded in migration 024.

A blocked channel is recorded as `status = 'skipped'`, never `pending`: a
pending row would be picked up by the worker and delivered, silently reversing
the policy decision the dispatcher just made.

## K.6 Worker

```
php backend/cron/notification_worker.php              # drain due rows
php backend/cron/notification_worker.php --dry-run    # report, send nothing
php backend/cron/notification_worker.php --type=leave_applied
php backend/cron/notification_worker.php --reap       # stale rows only
```

Windows Task Scheduler: `C:\xampp\php\php.exe`, argument
`C:\xampp\htdocs\hrdemo\backend\cron\notification_worker.php`, start in
`C:\xampp\htdocs\hrdemo\backend\cron`, triggered every 1 minute indefinitely.

Guarantees:

* **Idempotent enqueue** — the unique `(user_id, dedupe_key)` index.
* **Exclusive claim** — `tryClaimPending()` is a conditional UPDATE, so two
  overlapping runs cannot both deliver the same row. This is what makes running
  the worker on a 1-minute schedule safe.
* **Retry with backoff** — a transient provider failure reschedules at 1, 2 then
  4 minutes and gives up after 3 attempts. `attempts` is the counter
  `findRetryable()` compares against; it is incremented by the terminal
  `markSent()`/`markFailed()` transition, **not** separately at send time, or one
  delivery would cost two of the retry budget.
* **Crash recovery** — `reapStalePending()` fails pending rows older than N
  minutes. This used to carry an `attempts > 0` filter which, combined with
  `attempts` never being incremented, meant stranded rows were *never* reaped.
  Age alone is the signal now.

## K.7 Verification

```
php tools/verify-notification-queue.php
php tools/verify-attendance-notifications-unchanged.php
```

The first covers enqueue → dedupe → payload → policy → claim → attempts →
backoff → reap, and is repeatable and self-cleaning. The second is a regression
guard proving the 025-era `claim()` still behaves identically.

---

## K.8 What is wired up

| Event | Type | Triggered by | Channels |
|---|---|---|---|
| Leave applied | `leave_applied` | `LeaveApplicationService::submitApplication` (post-commit) | in-app, email |
| Leave forwarded | `leave_stage_advanced` | `LeaveApprovalService::approve` (post-commit) | in-app, email |
| Leave approved | `leave_approved` | `approve`, `submitApplication` (auto-approve) | in-app, email |
| Leave rejected | `leave_rejected` | `LeaveApprovalService::reject` (post-commit) | in-app, email |
| Leave cancelled / invalidated | `leave_stage_advanced` / `leave_rejected` | `cancel`, `invalidate` | in-app, email |
| Appraisal scored | `appraisal_status` | `AppraisalWorkflowService::saveScores` | in-app, email |
| Appraisal feedback | `appraisal_status` | `submitEmployeeFeedback` | in-app, email (+SMS when escalated) |
| Appraisal decision | `appraisal_status` | `decide` | in-app, email |
| Meeting invited | `meeting_invitation` | `MeetingService::createMeeting` | in-app, email |
| Meeting RSVP | `meeting_invitation` | `confirmAttendance` / `declineAttendance` | in-app, email |
| Meeting cancelled | `meeting_invitation` | `cancelMeeting` | in-app, email |
| Financial year closing | `financial_year_closing` | `cron/scheduled_notifications.php` | in-app, email |
| Monthly roster reminder | `leave_roster_monthly` | `cron/scheduled_notifications.php` | in-app, email |

**Every** trigger is dispatched AFTER the business transaction commits, and each
notification routine swallows its own failures: a leave application that saves
correctly but fails to email the approver is far preferable to one that rolls
back because a mail server hiccuped.

## K.9 Scheduled jobs

```
php backend/cron/scheduled_notifications.php                 # both jobs
php backend/cron/scheduled_notifications.php --task=fy       # FY closing only
php backend/cron/scheduled_notifications.php --task=roster   # roster only
php backend/cron/scheduled_notifications.php --dry-run       # report, write nothing
php backend/cron/scheduled_notifications.php --month=2026-10 # override the month
```

`--dry-run` is genuinely read-only: it is threaded into the service and every
send path returns early. An earlier version only suppressed the printed counts
while still writing queue rows and consuming dedupe keys, which silently
suppressed the subsequent real run for that period.

Roster reminders cover BOTH groups, with different wording: employees who
already have approved leave in the window get a "confirm with your delegate"
note; employees who are only on the roster (a plan, never a booking — see
`LeaveRosterService`) get a "no application yet" note. Reminding only the
approved group would skip the people most likely to have forgotten to apply.

`leave_roster.scheduled_year` is NULL for every row in the current dataset, so
the reminder derives the target month from the financial year plus the
July→June rule rather than trusting that column.

## K.10 Verification scripts

```
php tools/verify-leave-notifications.php
php tools/verify-leave-e2e.php
php tools/verify-appraisal-notifications.php
php tools/verify-meeting-notifications.php
php tools/verify-scheduled-notifications.php
```

`verify-leave-e2e.php` submits a REAL leave application through the real
service, proves the approver was notified, and removes everything it created
(bounded by the pre-test maximum id). The scheduled script inserts a temporary
financial year inside the warning window, then removes it.

> **Run the verification scripts one at a time.** They share the same tables
> (`notifications`, `notification_logs`, `financial_years`) and each snapshots
> and restores state around its own fixture. Two running concurrently will
> delete each other's fixtures and report spurious failures.

## K.11 The in-app inbox

| Endpoint | Purpose |
|---|---|
| `GET /api/notifications` | Paginated inbox. `page`, `per_page` (max 50), `filter=all\|unread\|read`, `category` |
| `GET /api/notifications/unread` | Small unread-only payload for the bell (`limit`, default 5) |
| `POST /api/notifications/{id}/read` | Mark one read. 404 for a row that is not yours |
| `POST /api/notifications/read-all` | Mark every unread read; returns the new count |

The response envelope keeps its original `notifications` + `unread_count` keys
so existing clients keep working, and adds `total`/`page`/`pages`/`categories`.
What changed is the content: the previous implementation returned only the 10
newest **unread** rows, so a user who had read everything saw an empty list
rather than their history, and there was no way to page.

Every query is scoped to a single `user_id`. A notification row belongs to
exactly one account, so there is no case where a broader scope is legitimate;
`markAsRead()` reports "not found" rather than "forbidden" so a guessed id
cannot be used to probe for other users' notifications.

### Frontend

- `frontend/src/components/NotificationBell.jsx` — live badge + dropdown.
  Polls `/notifications/unread` every 60s, not the full inbox, so the header
  never pulls a large payload. Disabled entirely while signed out.
- `frontend/src/pages/Notifications.jsx` — the full inbox, with filters,
  pagination and mark-as-read.
- Both are lazy-loaded; the route is `/notifications` and is available to every
  authenticated user with no permission gate, because the data is their own.

## K.12 Channel configuration

```bash
php tools/verify-notification-channels.php              # status of every channel
php tools/verify-notification-channels.php --probe-email # send a real email
php tools/verify-notification-channels.php --probe-sms   # send a real SMS
php tools/generate-vapid-keys.php                       # new, self-verified VAPID pair
```

The diagnostics probe the transports rather than trusting that a config key
exists. Current state on this machine:

| Channel | State | Notes |
|---|---|---|
| Email (SMTP) | working | `smtp.gmail.com:587` TLS, verified by live send |
| Email links | working | `FRONTEND_URL` set; the "Open MUWASCO HR" button resolves |
| Web push | working | Valid VAPID pair, `sw.js` present, 1 browser subscribed |
| SMS (httpSMS) | **not configured** | `HTTPSMS_API_KEY` and `HTTPSMS_SENDER_PHONE` are empty |
| Queue delivery | working | Windows scheduled tasks installed (see below) |

### Scheduled tasks (the delivery half)

Queueing is only half the system. Without a scheduler draining the queue,
notifications sit in `notification_logs` as `pending` forever and **look**
configured while nothing is ever sent. Four tasks are installed:

| Task | Schedule | Script |
|---|---|---|
| `MUWASCO HR - Notification Worker` | every 1 minute | `cron/notification_worker.php` |
| `MUWASCO HR - Attendance Reminders` | every 5 minutes | `cron/attendance_reminders.php` |
| `MUWASCO HR - Scheduled Notifications` | daily 07:00 | `cron/scheduled_notifications.php` |
| `MUWASCO HR - Auto Clockout` | daily 18:30 | `cron/auto_clockout.php` |

```powershell
schtasks /query /tn "MUWASCO HR - Notification Worker" /v /fo LIST
schtasks /run  /tn "MUWASCO HR - Notification Worker"   # force a drain now
```

The tasks run as the logged-on user and need no elevation to create. If they
are missing, nothing is delivered regardless of how correct `.env` is.

### httpSMS

httpSMS uses an **Android phone as the SMS gateway**, so it needs two values
from <https://httpsms.com> and that phone must be online with the app signed in:

```
HTTPSMS_API_KEY=...          # from the httpSMS dashboard
HTTPSMS_SENDER_PHONE=+2547…  # the registered number, E.164
```

Without both, `HttpSmsProvider::sendSms()` refuses before it reaches the
network and reports `httpSMS not configured`. Because the phone is the gateway,
sends can still fail at run time if that device is offline — the httpSMS app must
be running and signed in on a phone with credit and signal.

### Gmail sender address

`MAIL_FROM_ADDRESS` must match `MAIL_USERNAME`, unless that mailbox has the
From domain verified as a "send as" alias. A mismatch is the classic cause of
"it sends but nothing arrives": the provider accepts the message, rewrites the
envelope sender, and the result fails SPF/DKIM and lands in spam.

### OpenSSL on Windows

This XAMPP build cannot find an OpenSSL config file: even RSA key generation
fails with `error:02001003:system library:fopen:No such process`, and EC key
generation fails unless a config path is passed explicitly. SMTP is unaffected
(PHPMailer uses stream sockets, not `openssl_pkey_new`). Any future code that
generates keys must pass `'config' => 'C:\xampp\php\extras\ssl\openssl.cnf'`.




