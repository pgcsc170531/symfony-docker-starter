# Subscription reminders — how to schedule them

The command exists; something has to call it.

```bash
docker compose exec -T php php bin/console app:subscription:reminders --dry-run   # inspect
docker compose exec -T php php bin/console app:subscription:reminders             # send
```

It sends:

> **Is it actually wired?** `php bin/console app:billing:readiness` warns when a
> subscription renews within 14 days and **no reminder has ever been sent** —
> the signature of a command nothing calls. Full launch checklist:
> `docs/operations/GO-LIVE-CHECKLIST.md`.

| Trigger | Email |
|---|---|
| subscription ends in **14 / 7 / 1** days | "renews soon" |
| subscription already ended | "has ended" |
| invoice `PENDING`/`VERIFYING` older than **3 days** | "invoice still open" |

It is **idempotent per day**: `lastReminderSentAt` is stamped on the subscription
or invoice, so two runs (or two cron entries) cannot email a school twice.

---

## Cron or date-based?

Both, and they are not alternatives:

- **Date-based** is *what* the command does — it looks at `end_date` and
  `created_at` and decides who is due. That logic is already inside the command.
- **Cron** is *when* it looks. Without a scheduler the command only runs when a
  human types it.

So: keep the date logic, add a schedule.

---

## Option A — host cron (recommended)

Simplest, no extra container, and it survives application deploys.

On the server, `crontab -e` for the deploy user:

```cron
# Edus subscription reminders — 08:00 server time, every day
0 8 * * * cd /srv/edus && docker compose exec -T php php bin/console app:subscription:reminders >> /var/log/edus-reminders.log 2>&1
```

Why `-T`: no TTY in cron. Why the `cd`: `docker compose` needs the project path.

Verify once by hand with `--dry-run`, then watch the log for a week.

## Option B — a scheduler container

If you would rather not touch the host, add a tiny scheduler service to
`compose.yaml` (Ofelia or a `crond` image) that runs the same command on a
schedule. Cost: one more container to monitor, and the schedule lives in the
repo instead of the server.

```yaml
  scheduler:
    image: mcuadros/ofelia:latest
    depends_on: [php]
    command: daemon --docker
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock:ro
    labels:
      ofelia.job-run.edus-reminders.schedule: "0 8 * * *"
      ofelia.job-run.edus-reminders.container: "edus-php-1"
      ofelia.job-run.edus-reminders.command: "php bin/console app:subscription:reminders"
```

## Option C — Symfony Scheduler (later)

`symfony/scheduler` would let the command be declared in code and run by a worker.
Good when there are several periodic jobs; overkill for one daily command today.

---

## Recommendation

**Option A now.** One line in the host crontab, log to a file, revisit if the
number of scheduled jobs grows. Whatever the option, the command must run against
the **landlord** database — it does by default — and needs `MAILER_DSN` set, or
it will send nothing while reporting success.
