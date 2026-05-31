# ScheduledMail for FacturaScripts

Schedule email delivery from the standard FacturaScripts **SendMail** form. Pick an
optional future date/time and the email is queued and delivered later by the native
FacturaScripts work queue (cron) instead of being sent immediately.

If you leave the schedule field empty, nothing changes: the email is sent right away
through the usual FacturaScripts flow.

## Features

- Adds an optional **Schedule send** date/time field to the existing SendMail form.
- Empty field → immediate send (unchanged behaviour).
- Future date/time → the email is persisted and delivered by the work queue.
- Hard limit of **30 days**, enforced **server-side** (and client-side for convenience).
- The send button turns into a differently-coloured **Schedule send** button with a clock
  icon when a valid future date is selected, making the scheduled mode obvious.
- Attachments (the generated document PDF and any uploaded files) are copied to a
  plugin-owned folder so they survive until delivery.
- A management page (**Scheduled emails**) lists every scheduled email with its status and
  lets you cancel pending ones.
- Reuses the core `NewMail`, `WorkQueue` and cron infrastructure — no custom mailer or
  cron runner.

## Requirements

- FacturaScripts **2025** or later (uses `WorkQueue::sendFuture()`).
- PHP **8.1** or later.
- **Cron / work queue must be configured and running.** Scheduled emails are delivered by
  the FacturaScripts work queue, which is processed by the cron task. If cron is not
  running, scheduled emails stay `pending` and are never sent.
  See the FacturaScripts documentation on how to configure the cron:
  typically a system cron entry that calls `Cron` periodically, e.g.

  ```
  */5 * * * * cd /path/to/facturascripts && php cron.php
  ```

## Installation

1. Download the plugin ZIP (from the releases page or build it with `make package VERSION=1`).
2. In FacturaScripts go to **Admin Panel → Plugins** and upload/enable **ScheduledMail**.
3. FacturaScripts creates the `scheduled_mails` table automatically.
4. Make sure your **SMTP settings** are configured (Admin → Email). The plugin uses the
   same email configuration as the normal SendMail form.

## Usage

1. Open any document (e.g. an invoice) and click **Send email**.
2. Write/review the email as usual.
3. Optionally pick a date/time in **Schedule send**:
   - Leave it empty to send immediately.
   - Choose a future date/time (up to 30 days ahead) to schedule it. The send button turns
     into **Schedule send**.
4. Submit. A scheduled email shows a confirmation and is **not** sent immediately.
5. When the scheduled moment arrives and the work queue runs, the email is delivered and
   the related document is marked as emailed (`femail`).

You can review and cancel scheduled emails in **Admin Panel → Scheduled emails**.

## Timezone

The date/time you pick is interpreted in the **application/server timezone**
(`FS_TIMEZONE`), which is what FacturaScripts uses internally (`Tools::dateTime()`).
The 30-day window and the "must be in the future" check are validated server-side against
the server clock. If your browser and server are in different timezones, the picker value
is treated as server time — keep that in mind when scheduling close to "now".

## Attachments

Email attachments may be generated as temporary files that FacturaScripts prunes after a
while. To make scheduling safe for up to 30 days, this plugin **copies** the document PDF
and any uploaded files into `MyFiles/ScheduledMail/<id>/` when the email is scheduled. The
folder is deleted automatically after a successful send or when the email is cancelled.

## How it works

1. The plugin overrides the core `SendMail` controller (via the FacturaScripts Dinamic
   class system — core files are **not** modified) and injects the date/time field into the
   form through the core "include views" mechanism.
2. When a valid future date/time is submitted, the email data is stored in a
   `ScheduledMail` record, attachments are persisted, and a delayed event is registered with
   `WorkQueue::sendFuture()`.
3. The `SendScheduledMailWorker` worker picks up the event when the work queue runs at/after
   the scheduled time, rebuilds the email with `NewMail` and sends it.
4. On success the record is marked `sent`, attachments are removed and the document's
   `femail` flag is set. On failure the record is marked `failed` and the error is stored
   and logged.

## Troubleshooting

- **Scheduled emails are never sent** → the work queue/cron is not running. Configure cron
  (see Requirements). Pending emails are delivered as soon as the queue runs past their time.
- **A scheduled email is marked `failed`** → open **Scheduled emails**, the `error` column
  shows the reason (usually SMTP configuration). Fix the SMTP settings and reschedule.
- **Attachment missing at send time** → check that `MyFiles/ScheduledMail/<id>/` exists and
  the files were copied. Files are only deleted after a successful send.
- **SMTP** → scheduled sending uses the **same** FacturaScripts email settings as normal
  sending. If immediate sending works, scheduled sending uses the same configuration.

## Limitations

- Only one enabled plugin can override the `SendMail` controller at a time (FacturaScripts
  loads a single Dinamic controller per name).
- There is no automatic retry: a failed scheduled email is marked `failed` and must be
  rescheduled manually.
- A cleaner long-term solution would be a small upstream change so plugins can cancel the
  send through a hook instead of overriding the controller (see `AGENTS.md`).

## Manual test plan

1. **No date** → behaves exactly like FacturaScripts today (immediate send, `femail` set).
2. **+5 minutes** → not sent immediately; a `pending` row appears; after the work queue runs
   past the time it becomes `sent` and the email is delivered.
3. **Past date** → validation error, nothing scheduled.
4. **More than 30 days ahead** → validation error (also blocked by the input `max`).
5. **With attachment** → file copied to `MyFiles/ScheduledMail/<id>/`, still attached at
   send time, deleted after success.
6. **Cron not running** → email stays `pending` (documented behaviour).
7. **SMTP failure** → record becomes `failed` and the error is stored/logged.
8. **Related document** → marked as emailed only after a successful delivery.

## Development

- `make upd` — start the Docker dev environment
- `make lint` — PHP CodeSniffer
- `make format` — PHP CS Fixer
- `make test` — PHPUnit
- `make package VERSION=1` — build the distributable ZIP

## License

LGPL-3.0. See [LICENSE](LICENSE) for details.
