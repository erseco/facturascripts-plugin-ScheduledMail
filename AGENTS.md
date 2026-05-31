# AGENTS.md — Instructions for coding agents

This is the authoritative instruction file for any coding agent working in this repository.
Other agent files (`CLAUDE.md`, `GEMINI.md`, `.github/copilot-instructions.md`) should defer
to this one.

## Project overview

**ScheduledMail** is a FacturaScripts plugin that adds optional scheduled email delivery to
the standard `SendMail` form. An empty schedule field keeps the current immediate-send
behaviour; a future date/time persists the email and delivers it later through the native
FacturaScripts work queue (cron).

Compatibility: **FacturaScripts 2025+**, **PHP 8.1+**, **PSR-12**.

## Golden rules

- **PHP code, comments and docblocks must be in English.** JavaScript must be in English.
- **Reuse FacturaScripts APIs** — do not reinvent email sending (`NewMail`), the work queue
  (`WorkQueue`), cron, document rendering or attachment handling.
- **Never modify core/vendor files.** This is a plugin. Use plugin extension points:
  controller override via the Dinamic class system, include views
  (`Extension/View/<Parent>_<position>_<order>.html.twig`), models + `Table/*.xml`, workers,
  and JSON translations.
- **Keep it compatible with the plugin template** structure and conventions used across the
  author's other FacturaScripts plugins (QuickCreate, CommandPalette, PdfFileNamer, AiScan).
- Prefer **small, maintainable changes**. Avoid overengineering and unnecessary dependencies.
- **Translations**: every user-facing string goes through `trans()` / `Tools::lang()->trans()`
  with keys defined in `Translation/en_EN.json` and `Translation/es_ES.json`. Do not hardcode
  Spanish-only text.
- Keep the **GitHub Actions** workflows consistent with the other repositories. Do not invent
  a different CI system.
- Use clear commit/PR descriptions in **English Markdown**.

## Architecture (how the pieces fit)

- `Controller/SendMail.php` — overrides core `Core\Controller\SendMail` (same class name, so
  FacturaScripts loads it via the Dinamic system). Intercepts the `send` action only when the
  `email-scheduled-at` field holds a valid future date; otherwise delegates to the parent.
- `Extension/View/SendMail_beforeEnd_100.html.twig` — injects the date/time field and the
  button-transform JavaScript into the core form (no template override).
- `Model/ScheduledMail.php` + `Table/scheduled_mails.xml` — persistence of pending/sent
  emails. This is **not** the core `emails_sent` history table.
- `Worker/SendScheduledMailWorker.php` — registered in `Init.php` via
  `WorkQueue::addWorker()`; rebuilds and sends the email with `NewMail` when the queue runs.
- `Controller/ListScheduledMail.php` + `XMLView/ListScheduledMail.xml` — management UI with a
  cancel action.

### Known limitation / possible upstream improvement

Core `SendMail::send()` calls `$this->pipe('send')` but ignores its return value before
`return $this->newMail->send();`, so a plugin extension hook cannot cancel the immediate
send. That is why this plugin overrides the controller. A cleaner upstream fix would be:

```php
$this->setAttachment();
if ($this->pipeFalse('send')) {
    return $this->newMail->send();
}
return false;
```

so a plugin could cancel the send through a hook instead of overriding the whole controller.

## Before changing code

Inspect these files first:

| File | Purpose |
|---|---|
| `facturascripts.ini` | Plugin name, version, min FacturaScripts version, min PHP |
| `phpcs.xml` / `.php-cs-fixer.php` | Code-style rulesets |
| `Makefile` | All development commands |
| `.github/workflows/ci.yml` | CI pipeline |
| `Init.php` | Worker registration |

## Validation workflow

Run these (Docker required, `make upd` starts the container automatically):

```bash
make format   # PHP CS Fixer – auto-fix style
make lint     # PHPCS – must be clean
make test     # PHPUnit – all tests must pass
```

Unit tests live in `Test/main/`. Keep the scheduling rules covered by
`ScheduleValidatorTest` (the 30-day window / past / future logic lives in the
pure `Lib/ScheduleValidator` class precisely so it can be unit-tested without a
running FacturaScripts).

## End-to-end validation (scheduled invoice email with attachment)

The unit tests cannot exercise the controller → work queue → SMTP path. To
validate the full flow manually (this is exactly how it was verified), the dev
`docker-compose.yml` already bundles **Mailpit** (a local SMTP sink) and enables
cron:

1. `make up` — starts FacturaScripts (`:8080`), MariaDB and Mailpit (`:8025`).
2. Log in at <http://localhost:8080> (admin/admin) and enable **ScheduledMail**
   in **Admin → Plugins** (then **Reconstruir** if the SendMail field does not
   appear yet). Quick alternative from the host:
   `docker compose exec -T facturascripts php -r '...Plugins::enable("ScheduledMail")...'`.
3. **Admin → Email** (`/ConfigEmail`): set `host=mailpit`, `port=1025`, a
   `from` address, no encryption, no user/password. Use the **Test** button or
   send any email and confirm it appears in Mailpit at <http://localhost:8025>.
4. Create a sales invoice (customer + a line) and open it. From the **Imprimir**
   dropdown choose **Email** — FacturaScripts generates the PDF and opens the
   SendMail form with the PDF attached and the customer pre-filled.
5. Pick a near-future date/time in **Programar envío** and submit. Expect
   "Email programado correctamente" (it must NOT send immediately).
6. Verify the pending state:
   - `scheduled_mails` row is `pending` with `model_class_name=FacturaCliente`,
     the right `model_code` and an `attachments_json` entry.
   - the PDF was copied to `MyFiles/ScheduledMail/<id>/`.
   - the invoice `femail` is still NULL.
7. Run the work queue once: `make cron` (the container also runs it hourly).
8. Verify delivery:
   - Mailpit shows the email **with 1 attachment** (the invoice PDF).
   - the `scheduled_mails` row is `sent` with `sent_at` set.
   - the invoice `femail` is now set (marked emailed only after delivery).
   - `MyFiles/ScheduledMail/<id>/` was removed.

Useful host-side checks during validation:

```bash
make cron                                              # process the queue now
curl -s http://localhost:8025/api/v1/messages          # Mailpit inbox (JSON)
docker compose exec -T mariadb mariadb -ufacturascripts -pfacturascripts \
  facturascripts -e "SELECT id,status,sent_at FROM scheduled_mails;"
```
