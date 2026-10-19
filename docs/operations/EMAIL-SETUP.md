# Edus — Email setup & troubleshooting

> Covers everything that sends mail: parent self-registration verification links,
> sign-in/recovery links, and every future notification template.
> Related: `docs/authentication/VERIFICATION-AND-2FA.md`

## 1. Where mail goes

| Environment | Transport | Where to read it |
|---|---|---|
| Local dev | `smtp://mailer:1025` (Mailpit container) | **http://localhost:8025** |
| Production (VPS) | Brevo SMTP relay | The parent's real inbox |

`docker compose up -d` starts Mailpit. Its ports are published explicitly in
`compose.override.yaml` (`1025:1025`, `8025:8025`) — the Symfony recipe's default
`- "1025"` only exposes them to other containers, which made the UI unreachable.

## 2. One command to prove mail works

```bash
# develop
docker compose exec -T php bin/console app:mail:test you@example.com

# production
docker compose exec -e APP_ENV=prod -T php bin/console app:mail:test you@example.com
```

It prints the **transport that is really configured** (password masked) and the
raw SMTP error when sending fails — no more guessing.

## 3. Brevo configuration

1. **Verify a sender** (Senders, Domains & Dedicated IPs → Senders). The address in
   `MAILER_FROM` must be verified, or Brevo rejects every message with a 550.
   Verifying the **domain** (`edus.ng`) is better than a single address — then any
   `*@edus.ng` sender works and DMARC/SPF align.
2. **Generate an SMTP key**: SMTP & API → **SMTP** tab → *Generate a new SMTP key*.
   It starts with `xsmtpsib-`. ⚠️ This is **not** the API key (`xkeysib-`) used for
   the payments/webhook work — the two are different products.
3. **Copy the SMTP login from that same tab** — it looks like
   `9xxxxxx001@smtp-brevo.com`. It is **not** your Brevo account email address.
4. **Authorise the server IP** (SMTP & API → SMTP → authorised IPs). If your laptop
   or the VPS IP is not listed, authentication fails with `535 5.7.8`.
5. Set the values — locally in `.env.local`, on the server in the compose file:

```yaml
environment:
  MAILER_DSN: "smtp://9xxxxxx001@smtp-brevo.com:xsmtpsib-xxxxxxxx@smtp-relay.brevo.com:587"
  MAILER_FROM: "Edus <no-reply@edus.ng>"    # must be verified in Brevo
  MAILER_REPLY_TO: "support@edus.ng"
```

> `.env` supports variable interpolation, so you can instead set
> `BREVO_SMTP_LOGIN` + `BREVO_SMTP_KEY` and uncomment the composed `MAILER_DSN`.
> **Never commit the key.** `smtpkey*`, `*.key`, `*.pem` and `.env.local` are gitignored.

6. Apply it: `docker compose up -d php && docker compose exec -e APP_ENV=prod -T php bin/console cache:clear`

## 4. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `535 5.7.8 Authentication failed` | Wrong SMTP **login** or an **old key**. The login is NOT your account email — it looks like `bb0544001@smtp-brevo.com` and sits on the SMTP tab next to the key. If you regenerated the key, the old one stops working immediately | Re-copy login **and** key from SMTP & API → SMTP |
| `525 5.7.1 Unauthorized IP address` | Credentials are **correct** — this machine's public IP is not in Brevo's allowed list. This is the error you get when only the VPS is whitelisted | Add the sending machine's IP in Brevo → Security → Authorized IPs, *or* send from the VPS (recommended for production) |
| `535` on the VPS but `525` elsewhere | Same as above: the VPS IP is the one that is authorised | Leave the SMTP key working from the VPS only; do local sandbox tests against Mailpit |
| `550` / "sender not verified" | `MAILER_FROM` address/domain not verified in Brevo | Verify sender or domain, or align `MAILER_FROM` with a verified one |
| Nothing arrives, no error, `null://null` | The dev default discards mail | Start Mailpit (`compose.yaml` sets the DSN) or set a real DSN |
| Parent sees *"We could not send the email just now"* | `ApplicationMailer` caught a transport error | Check `var/log/*.log` for `Access link email failed`, then run `app:mail:test` |
| Link lands but says invalid/expired | Link older than 2 hours, or the URL host scheme changed (http↔https) — `UriSigner` signs the whole URL | Re-request a link; keep `trusted_proxies` correct behind the VPS proxy |
| Mail goes to spam | Domain not verified / no SPF+DKIM | Verify the domain in Brevo and publish the DKIM/SPF records |

> **Diagnose by error code, not by guesswork:** `535` = credentials, `525` = IP.
> Credentials confirmed working against `smtp-relay.brevo.com:587` with a 2048-bit DKIM
> record live on `edus.ng` — sending is a matter of running from an authorised IP.

## 5. Which emails the system sends today

| Trigger | Template | Sender |
|---|---|---|
| Parent registers (new family) | `emails/parent/access_link.html.twig` (`verify`) | `MAILER_FROM` |
| Parent already had an account | same template (`recover`) | `MAILER_FROM` |
| Application submitted / decision / enrolled | *(next build phase)* | `MAILER_FROM` |

All of them extend `templates/emails/layout.html.twig`, which applies the school's
name, motto, colour, address and phone — so a parent always sees their school, not
"Edus".

## 6. Rules for new email templates

- Inline CSS only, table layout (email clients are not browsers) — **no Tailwind**.
- Always extend `emails/layout.html.twig` and pass `brand`, `school_name`, `headline`,
  `intro`, `button_label`, `url`.
- Keep the plain-text fallback sensible (Symfony renders one automatically from HTML,
  but short transactional mail should also set `->text()` when it is added).
- Every send must be wrapped so a mail outage can never lose a parent's data
  (`ApplicationMailer` already logs and flashes instead of throwing).

---

## 7. Domain authentication in Cloudflare (`edus.ng`)

Sending through Brevo works without this, but mail lands in **spam** on Gmail/Outlook
without DKIM. Brevo → *Senders, Domains & Dedicated IPs* → **Domains** → *Authenticate
this domain* gives you the records to add. State of the zone when this was written:

| Record | Exists today? | Action |
|---|---|---|
| `edus.ng` **A** → `172.67.207.162`, `104.21.85.161` | ✅ Cloudflare **proxy** IPs | Leave alone |
| `edus.ng` **MX** → `mx1/mx3-hosting.jellyfish.systems` | ✅ (mailboxes work) | Leave alone |
| `edus.ng` **TXT** → `brevo-code:ddf3f8f…` | ✅ added | Leave alone |
| `edus.ng` **TXT (SPF)** → `v=spf1 +a +mx +ip4:69.57.162.133 include:spf.web-hosting.com ~all` | ✅ but **no Brevo** | ➕ append `include:spf.brevo.com` |
| `_dmarc.edus.ng` **TXT** → `v=DMARC1; p=none; rua=mailto:rua@dmarc.brevo.com` | ✅ Brevo tracking address | Leave alone |
| `mail.edus.ng` **CNAME** → `mail-edus-ng.brand.brevosend.com` | ✅ Brevo sending subdomain | Leave alone |
| `mail.edus.ng` **TXT (SPF)** → `v=spf1 include:amazonses.com ~all` | ✅ (Brevo sends via SES) | Leave alone |
| `mail.edus.ng` **MX** → `feedback-smtp.eu-north-1.amazonses.com` | ✅ bounce/feedback | Leave alone |
| `mail._domainkey.edus.ng` **TXT (DKIM)** | ❌ **STILL MISSING** | ➕ **add (this is the one that matters)** |

### ✅ DKIM — added and verified (selector `default`)

Brevo/SES publishes the key at **`default._domainkey.edus.ng`** — *not* at
`mail._domainkey`, which is why an earlier check wrongly reported it missing.
Verified live:

```
default._domainkey.edus.ng TXT = "v=DKIM1; k=rsa; p=MIIBIjANBgkqhkiG9w0BAQ…
                                   …AQAB;"      (414 chars, 2048-bit RSA key)
```

- The value arriving split into two quoted strings is **normal** — that is how DNS carries
  strings longer than 255 bytes, and receivers concatenate them. Don't "fix" it, and never
  split it into two separate records.
- Check it with: `dig +short TXT default._domainkey.edus.ng`
- If you regenerate keys in Brevo, this record changes — always re-copy from Brevo.

**Why the root SPF didn't need the Brevo include:** the visible sender is
`no-reply@edus.ng` and the DKIM signature is also for `edus.ng`, so **DKIM alignment alone
satisfies DMARC** (the strongest case — it survives forwarding). The envelope runs through
the custom MAIL FROM domain `mail.edus.ng`, whose own SPF (`include:amazonses.com`) covers
the SPF half. Adding `include:spf.brevo.com` to the root record is optional now.

### Which sender address to use

| Brevo authenticates | Set `MAILER_FROM` to | Why |
|---|---|---|
| `edus.ng` (current setup) | `Edus <no-reply@edus.ng>` | Sender = signed domain → strict DMARC alignment |
| a sending subdomain only | `Edus <no-reply@mail.edus.ng>` | Keeps sender and signature domain identical |

### Note on `mail.edus.ng`

The CNAME/A at `mail.` now point at Brevo's sending host (94.143.16.132). If that name was
previously used for a **webmail portal**, that portal is no longer reachable there — move it
to `webmail.edus.ng`. Inbound mail is unaffected (the root MX at Jellyfish is untouched).

### The three records to add

| Type | Name (Cloudflare) | Value |
|---|---|---|
| TXT | `mail._domainkey` | copy **exactly** from Brevo (`k=rsa; t=s; p=MIGfMA0…`) |
| TXT | `@` (root) | `brevo-code:<hash from Brevo>` |
| TXT | `_dmarc` | *(already present)* — keep `v=DMARC1; p=none;` |

**Edit the SPF record** — never create a second one (only one SPF record is allowed per
name, and a second makes both invalid). Insert the Brevo include before the final
qualifier:

```
v=spf1 +a +mx +ip4:69.57.162.133 include:spf.web-hosting.com include:spf.brevo.com ~all
```

### Cloudflare gotchas

- Paste TXT values **without surrounding quotes** — Cloudflare adds them. Pasting `"k=rsa…"`
  stores the quotes and DKIM fails.
- If Brevo gives you a **CNAME** for DKIM instead of a TXT, set Proxy status to
  **DNS only** (grey cloud). TXT records have no proxy toggle.
- Adding a second TXT at the root (`brevo-code`) is fine — it sits *beside* the SPF TXT.
- Don't touch the A/AAAA (website) or MX (mailbox) records; Cloudflare's proxy has
  nothing to do with SMTP.
- Propagation is usually a few minutes; Brevo then flips the domain to *Authenticated*.

### Verify

```bash
dig +short TXT mail._domainkey.edus.ng     # must show the DKIM key
dig +short TXT _dmarc.edus.ng
dig +short TXT edus.ng                     # SPF must contain include:spf.brevo.com
```

## 8. Brevo "authorised IPs" — which IP?

Brevo's allowed-IP list is for the machine that **connects out** to
`smtp-relay.brevo.com:587`. That is the **VPS's own public IP**:

```bash
curl -4 ifconfig.me        # run this ON THE VPS, not on your laptop
```

⚠️ Do **not** enter the IPs from your A records (`172.67.x` / `104.21.x`) — those are
Cloudflare's **proxy** addresses, not your server. Cloudflare only proxies HTTP(S); it
never carries SMTP, which is also why nothing has to change there for sending.

If you prefer to skip IP restrictions entirely, Brevo can authenticate the SMTP key
alone — then add the login + key to the env and skip this step.

## 9. Mail is queued — the worker must be running

Every email is now written to a queue and sent by a background worker, because a
real relay takes seconds per message (measured: **1.35 s** to open the connection,
**6.95 s** for one message). Sending inline meant a class release with 67 families
exhausted PHP's 30 s limit and returned a 500 — after the result had already been
released.

**One queue, one worker, every school.** The queue table lives in the **landlord**
database (`MESSENGER_TRANSPORT_DSN=doctrine://landlord`), so a single worker
serves all tenants.

```bash
# prepare the table (every tenant + the landlord database)
php bin/console app:tenant:update-schema

# the worker — run it under supervisor/systemd, and restart it on deploy
php bin/console messenger:consume async --time-limit=3600 --memory-limit=256M

# housekeeping, daily (acknowledged rows stay in the table until pruned)
php bin/console messenger:prune --until="7 days ago"
```

A ready supervisor unit:

```ini
[program:edus-worker]
command=php /var/www/current/bin/console messenger:consume async --time-limit=3600 --memory-limit=256M
directory=/var/www/current
autostart=true
autorestart=true
numprocs=1
stopasgroup=true
killasgroup=true
```

**If the worker is not running**, releases and payments still work — the mail
simply waits in the table and is sent when the worker starts. Nothing is lost, and
no request blocks on the relay again.

**Symptoms to recognise:**

| You see | Means |
|---|---|
| Release returns instantly, parents get nothing | Worker is not running — start it |
| `Could not queue` warning under a release | The queue table is missing — run `app:tenant:update-schema` |
| Mail is very slow in the worker (5–7 s each) | Normal for a remote relay; it is no longer in anyone's browser |
