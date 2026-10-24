# Secrets and deploys — how keys reach production

**Short version:** never commit them, never put them in `composer.json`, and set them
**once** — either in the landlord admin screen (stored encrypted in the database) or
in `.env.local` on the server. GitHub Secrets are the way to *push* the environment
ones, not a place the app reads from.

---

## 1. Why `.env.local` being gitignored is correct

`.gitignore` covers `.env.local`, `.env.local.php` and `smtpkey*` on purpose. A key
in git is a key in every clone, every fork, every backup and every screenshot — it
has to be rotated the moment it is committed. **Do not "solve" this by committing
the file or by moving keys into `composer.json`**: Composer is for packages, it is
committed, and it has no mechanism for secrets. There is nothing to update there.

---

## 2. Where a key can live, and which wins

`App\Service\Settings\PlatformSettings` resolves each setting in this order:

| Order | Source | Where you set it | Good for |
|---|---|---|---|
| 1 | Landlord `GlobalSetting` row (encrypted) | Landlord → Settings → Payments / Email | Keys you want to change without a deploy |
| 2 | Real environment variable | `compose.yaml` `environment:` or the server's `.env.local` | Keys you want to keep out of the database |
| 3 | Default in code | — | Nothing secret |

Because the compose file mounts `./:/app`, **`/root/projects/.env.local` on the VPS
is the app's `.env.local`** — Symfony reads it exactly as it does locally. Write it,
restart the container, done. No rebuild, no image tag change.

> ⚠️ A real environment variable in `compose.yaml` **beats** `.env.local` (Symfony's
> dotenv never overrides the process environment). That is why the mail variables
> were deliberately removed from `compose.yaml`'s `environment:` block — leaving
> them there made the Brevo DSN impossible to set.

---

## 3. The Paystack key specifically

Two code paths used to read it from **different places** — this was a real bug:

| Code | Used to read | Now reads |
|---|---|---|
| `PaystackGateway` (card checkout, webhook signature) | `PlatformSettings` (DB → env) | unchanged |
| `PaystackClient` (dedicated accounts, subaccounts, transfers, payouts) | `%paystack_secret_key%` — **env only** | `PlatformSettings` (DB → env) |

The effect of the old split: pasting the live key into the admin screen made card
payments work while family accounts and payouts kept using the old environment key —
or none at all. Both now read the same source, so **setting the key once is enough**:

- **Preferred:** Landlord → Settings → Payments → paste `sk_live_…`. Stored encrypted
  (`APP_ENCRYPTION_KEY`), survives deploys, no server access needed, and the screen
  shows a masked hint plus whether it is `live` or `test`.
- **Alternative:** keep it in the server's `.env.local` as `PAYSTACK_SECRET_KEY=` and
  leave the admin field empty.

`APP_ENCRYPTION_KEY` itself must be in the environment — it is the key that decrypts
the database-held secrets. **Never rotate it while secrets are stored**, or those
rows become unreadable.

---

## 4. Shipping environment secrets with GitHub Actions

`compose.yaml` already takes `TERMII_API_KEY` from the shell, and the deploy workflow
now writes the rest into `/root/projects/.env.local` **before** recreating the
container:

```yaml
set_kv PAYSTACK_SECRET_KEY '${{ secrets.PAYSTACK_SECRET_KEY }}'
set_kv MAILER_DSN         '${{ secrets.MAILER_DSN }}'
# …
```

`set_kv` upserts one `KEY="value"` line, skipping empty secrets (so an unset secret
leaves whatever the server already has) and re-writing the file as `chmod 600`
without `sed` escaping, because a DSN can legitimately contain `|`, `&` or `#`.

Add these in **GitHub → repo → Settings → Secrets and variables → Actions**:

| Secret | Needed for |
|---|---|
| `PAYSTACK_SECRET_KEY` / `PAYSTACK_PUBLIC_KEY` | online payments (optional if set in the admin screen) |
| `MAILER_DSN` or `BREVO_SMTP_LOGIN` + `BREVO_SMTP_KEY` | parent emails, receipts, reminders |
| `MAILER_FROM`, `MAILER_REPLY_TO` | sender identity |
| `APP_ENCRYPTION_KEY` | decrypting DB-stored secrets — **write-once** |
| `APP_SECRET` | signing sessions/CSRF; **production refuses to boot without it** |
| `TERMII_API_KEY`, `TERMII_SENDER_ID` | SMS (already wired) |

Rotation is then: change the secret in GitHub → re-run the workflow. The container is
recreated, so nothing is left holding the old value in memory.

---

## 5. Checklist for a fresh server

```bash
# on the VPS, once
cd /root/projects
printf 'APP_ENCRYPTION_KEY="%s"\n' "$(docker compose exec -T php php bin/console app:platform:make-key | tail -1)" >> .env.local
chmod 600 .env.local
docker compose up -d --force-recreate
```

1. `.env.local` exists, `chmod 600`, contains `APP_ENCRYPTION_KEY`.
2. Deploy has run at least once (so the workflow's `set_kv` calls have written what
   the repo's secrets hold).
3. Landlord → Settings → Payments shows the key as `live` (or the key is in
   `.env.local`), and the webhook URL points at the public domain.
4. Landlord → Settings → Email sends a test message.

**Never** paste a key into a chat, an issue, a screenshot or a commit — if it has
been, rotate it at the provider first and update the secret.
