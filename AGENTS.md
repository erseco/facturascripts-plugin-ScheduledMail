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
