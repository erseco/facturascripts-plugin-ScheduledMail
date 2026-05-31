# Quick Start Guide

## 1. Start FacturaScripts

```bash
make up
```

Wait for the containers to start (about 30 seconds).

## 2. Access FacturaScripts

Open your browser and go to:
```
http://localhost:8080
```

Login with:
- **Username:** `admin`
- **Password:** `admin`

## 3. Enable the Plugin

Go to **Admin Panel → Plugins** and enable **ScheduledMail**.

Or run:
```bash
make enable-plugin
```

## 4. Configure email (Mailpit)

The dev environment includes **Mailpit**, a local SMTP server that catches every
email FacturaScripts sends.

- Mailpit web UI: <http://localhost:8025>
- In FacturaScripts go to **Admin Panel → Email** (`/ConfigEmail`) and set:
  - **From / email:** `demo@example.com` (any address)
  - **Host:** `mailpit`
  - **Port:** `1025`
  - **User / Password:** leave empty (Mailpit accepts anything)
  - **Encryption:** none

Now open any invoice → **Send email**, pick a future date in **Schedule send**, and
submit. Cron is enabled (`RUN_CRON_TASKS: "true"`), so when the time arrives the work
queue delivers the email and it appears in Mailpit. Review scheduled emails in
**Admin Panel → Scheduled emails**.

## 5. Make Changes

Edit any file in the plugin directory. Changes are reflected immediately.

After changing models or controllers, rebuild:
```bash
make rebuild
```

## 6. Stop FacturaScripts

```bash
make down
```

## Need Help?

Run `make help` to see all available commands.

Check the full [README.md](README.md) for detailed documentation.
