# Fee report emails — cron setup

> Companion to `docs/payments/FEES-REPORTING-DESIGN.md` (Phase 4).
> Two console commands send the school-facing reports. They loop every active
> tenant themselves — one cron entry per command, not one per school.

---

## 1. What gets sent

| Command | When | Recipients | Contents |
|---|---|---|---|
| `app:fees:send-term-summaries` | Daily, but only terms whose `end_date` has passed and `summary_sent_at` is null | `ROLE_ADMIN` users, falling back to the school's email | **Term Fee Summary PDF** attached (A4, QR verification) |
| `app:fees:send-aging-reminders` | Weekly | `ROLE_ADMIN` users, falling back to the school's email | Outstanding total, number of families, top 8 balances |

Neither command ever messages a parent. Guardian reminders are **drafted** on the
outstanding-fees screen and exported as CSV; a human sends them.

Both honour the school's master **email notifications** switch
(`School::isEmailNotificationsEnabled()`). A mail failure is logged and never rolls
back the report or the `summary_sent_at` timestamp.

---

## 2. Cron entries

Run from the project directory (the compose project must be up).

```cron
# End-of-term summaries — daily at 06:00
0 6 * * *  cd /home/ibrahim/projects/symfony-docker-starter && docker compose exec -T php php bin/console app:fees:send-term-summaries >> var/log/fee-term-summaries.log 2>&1

# Weekly fee position — Monday 07:00
0 7 * * 1  cd /home/ibrahim/projects/symfony-docker-starter && docker compose exec -T php php bin/console app:fees:send-aging-reminders >> var/log/fee-aging.log 2>&1
```

> The daily term command is idempotent: `summary_sent_at` prevents a second send.
> `--force` re-sends and rebuilds the PDF from the figures at that moment.

---

## 3. Options

```bash
# See what would happen, send nothing
docker compose exec -T php php bin/console app:fees:send-term-summaries --dry-run

# One school, one term, send to a test address
docker compose exec -T php php bin/console app:fees:send-term-summaries \
    --school=demo --term=1 --force --to=qa@example.com

# Weekly position for one school
docker compose exec -T php php bin/console app:fees:send-aging-reminders \
    --school=demo --dry-run
```

| Option | Commands | Meaning |
|---|---|---|
| `--school=SUBDOMAIN` | both | Only this tenant |
| `--term=ID` | both | Term to report on (term command: skip the "ended" filter) |
| `--since=DAYS` | term | How far back to look for ended terms (default 60) |
| `--to=EMAIL` | both | Override recipient (testing) |
| `--force` | term | Re-send even if `summary_sent_at` is set |
| `--dry-run` | both | List, do not send |

---

## 4. Testing against Mailpit

The dev `.env.local` may point `MAILER_DSN` at Brevo. Override it for a test run so
mail lands in Mailpit at `http://localhost:8025` instead of a real inbox:

```bash
docker compose exec -T -e MAILER_DSN="smtp://mailer:1025" \
    php php bin/console app:fees:send-term-summaries \
    --school=demo --term=1 --force --to=qa@example.com
```

Check the message and its attachment in the Mailpit API:

```bash
curl -s http://localhost:8025/api/v1/messages?limit=1
```

If the run was only a test, clear the guard so the real send still happens:

```sql
UPDATE term SET summary_sent_at = NULL WHERE id = 1;
```

---

## 5. Failure modes

| Symptom | Cause |
|---|---|
| `No ended term is due` | `summary_sent_at` is already set, or `end_date` is in the future |
| `Mailer disabled, or no usable recipient` | The school switched email notifications off, or has no admin user/school email |
| Nothing in Mailpit, no error | `MAILER_DSN` is pointing somewhere else — check `.env.local` and the container env |
| Term summary has no figures | The term has no start/end dates, so application fees and expenses are excluded (the PDF says so) |
