# Email delivery — can this system mail a real Gmail inbox?

**The question this document answers:** *"Our Docker stack has its own mail
server. Can it send to a real provider like Gmail? Can we use our own domain as
the sender? How hard is that really — should we build it or buy it?"*

**Short answer:** No, the built-in mail server cannot, and should not be made
to. Use a relay (an "API provider") — **Brevo** is already wired up and its
domain authentication is already published. Self-hosting outbound mail is a
different product, not a configuration option: it is roughly *two weeks of work
plus an ongoing deliverability job*, and it is the single most reliable way to
have a school's fee reminders silently binned.

Everything below explains *why*, so the decision can be defended rather than
assumed.

---

## 1. What is running today

| Piece | What it actually is | Can it deliver to Gmail? |
|---|---|---|
| `mailer` container (**Mailpit**) | A **development** SMTP sink and web viewer on `localhost:1025` / `:8025`. It accepts mail and shows it. | ❌ **Never.** It does not attempt delivery at all — mail is displayed and dropped. |
| `MAILER_DSN=null://null` (`.env`) | Symfony's `/dev/null` transport: accepts and discards | ❌ Intentionally discards |
| `MAILER_DSN=smtp://mailer:1025` (`compose.yaml`) | Points at Mailpit for local development | ❌ Same |
| `MAILER_FROM="Edus Dev <no-reply@edus.local>"` | `.local` is a reserved TLD — no public DNS, no recipient will accept it | ❌ |

So today **no parent receives any email** outside your machine. That is by
design and it is correct for development: you get to read every message, and
you cannot accidentally email real parents from a test box.

The system already sends real messages through this pipe — verified on a live
run against a demo school:

1. *Confirm your email address* (parent registration)
2. *We received your application* (reference `APP/<year>/0001`)
3. *Congratulations — a place has been offered* (admission number, after the
   bursar approves)
4. *Payment received — `PAY-<year>-XXXXXX`* (fee receipt)

Four emails, four correct recipients — all four sitting in Mailpit, none
delivered. Which is exactly the shape of the problem: **the application side is
done; only the last hop is missing.**

---

## 2. Why you cannot simply point Mailpit at Gmail

"Just configure the container to send to Gmail" fails for four independent
reasons, and fixing any three of them changes nothing.

### 2.1 Gmail is not a relay

Gmail's SMTP endpoint requires you to **authenticate as an existing Gmail
account** and it sends only as that account. It is designed for a person's mail
client, not for a server sending on behalf of many schools. It also caps
sending (roughly a few hundred recipients a day on a free account) and rewrites
`From` to the authenticated address — so a school's branded sender is
impossible.

### 2.2 The school's domain must be authorised, not just typed into `From`

Mail claiming to be `no-reply@greenfield.sch.ng` is *unauthenticated* unless the
domain's DNS says the sender is allowed to send. Gmail/Outlook/Yahoo now check
three records:

| Record | Answers | If missing |
|---|---|---|
| **SPF** (TXT on the domain) | "Which servers may send for this domain?" | Mail is marked suspicious |
| **DKIM** (TXT at `selector._domainkey`) | "Is this message signed by the domain, and intact?" | Mail lands in spam; forwarded mail breaks |
| **DMARC** (TXT at `_dmarc`) | "What should I do when SPF/DKIM fail?" | No protection, no reporting, and Gmail/Yahoo bulk-sender rules are not met |

DMARC passes only when SPF or DKIM **aligns** with the visible `From` domain.
This is why "we set the From address" is not the same as "we authenticate the
domain".

### 2.3 A residential IP cannot send

Port **25 outbound** is blocked by essentially every ISP and by most cloud
providers (AWS, GCP, Azure all block it until you request unblocking, and
DigitalOcean/Hetzner commonly refuse). Without port 25 you cannot deliver
directly to a recipient's mail server at all.

### 2.4 Reputation, which is the actual hard part

An IP with no sending history is *unknown*, and unknown is treated as guilty.
Deliverability depends on: a matching **rDNS/PTR** record, not being on a
spam blocklist, complaint rate under ~0.1%, bounce rate under ~2%, a warm-up
ramp of weeks, and consistent volume. One bad campaign from a shared server can
poison the whole IP. This is a permanent operations duty, not a setup task.

---

## 3. Using your own domain as the sender — what it takes

This is a **DNS job**, and it is the same job whether you self-host or use a
provider. On `edus.ng` it has already been done and verified
(`docs/operations/EMAIL-SETUP.md` §7):

| Record | Purpose | State |
|---|---|---|
| `default._domainkey.edus.ng` TXT (2048-bit RSA) | DKIM signature | ✅ present, verified |
| `edus.ng` TXT (SPF) | Authorised senders | ✅ present — *one record only* |
| `_dmarc.edus.ng` TXT | Policy + reports (`p=none`) | ✅ present |
| `mail.edus.ng` CNAME + SPF + MX | Custom MAIL FROM / bounce handling | ✅ present |
| Cloudflare proxy on the DKIM name | Would break DKIM if proxied | ✅ not proxied (TXT cannot be) |

**The key insight, worth stating plainly:** *you do not need to run a mail server
to send as your own domain.* You publish three DNS records, and a relay sends on
your behalf. Sending as `no-reply@yourdomain` is a **DNS and provider
configuration**, not an infrastructure project.

Two traps that cost people days:

- **Only one SPF record per name.** A second makes *both* invalid. Always edit
  the existing one.
- **Long DKIM values arrive as two quoted strings.** That is DNS splitting a
  value over 255 bytes. It is correct — never "fix" it into two records.

### Where to go from one school to many

Today `edus.ng` is the sender for everything, with the school's name in the
body and its reply-to. That is the right first step: one authenticated domain,
many schools, no per-school DNS work.

The per-school sender (`no-reply@greenfield.sch.ng`) is only worth doing once a
school asks for it — and it requires that school to publish the same three
records. Design for it, don't build it yet: the sender identity lives in
`MAILER_FROM` plus the per-school settings, so it is a configuration split, not
a rewrite.

---

## 4. The decision: self-host vs relay

| | **Self-host an MTA** (Postfix/Docker Mailserver) | **Relay** (Brevo / Resend / SES / Postmark) |
|---|---|---|
| Time to first delivered email | Days to weeks | **Under an hour** (DNS propagation is the wait) |
| Port 25 | Must be unblocked by the host; often refused | Not needed — you connect on 587/443 |
| PTR / rDNS | You must set it, and providers must respect it | Handled by the provider |
| IP reputation | Yours to build and defend, forever | Provider's — shared, but monitored |
| DKIM/SPF/DMARC | You generate and rotate keys | Provider generates; you paste records |
| Bounce & complaint handling | You build it (and must honour unsubscribe) | Built in |
| Deliverability to Gmail | Pray | Designed for |
| Cost | "Free" + your time + a dedicated IP (~$5–15/mo) once volume grows | Free tier, then ~$9–25/mo at these volumes |
| Failure mode | **Silent spam-foldering.** Nobody tells you. | Dashboard shows bounces, complaints, blocks |
| Who fixes it at 2am | You | The provider |

**Verdict: relay.** For a school platform whose emails are *fee reminders*,
*admission offers* and *receipts* — transactional mail that must arrive and has
no marketing upside to justify risk — self-hosting buys nothing and costs
deliverability.

### Which relay

| Provider | Fit | Notes |
|---|---|---|
| **Brevo** | ✅ **Already configured here** | SMTP or API; free tier ≈ 300/day; the domain is authenticated already |
| Resend | Good | Developer-friendly; very clean API; smaller free tier |
| Amazon SES | Cheapest at volume | Most work: production-access request, per-region setup, reputation is more on you |
| Postmark | Best pure transactional deliverability | Paid only; worth it if email *is* the product |

**Use Brevo.** It is already wired into `.env` (empty `BREVO_SMTP_LOGIN` /
`BREVO_SMTP_KEY`), the DNS records already exist, and switching is a
commented-out DSN.

---

## 5. How to switch it on

Nothing in the application changes. This is environment configuration only.

```dotenv
# .env (or the server's real environment) — SMTP relay
MAILER_DSN="smtp://${BREVO_SMTP_LOGIN}:${BREVO_SMTP_KEY}@smtp-relay.brevo.com:587"
MAILER_FROM="Edus <no-reply@edus.ng>"
MAILER_REPLY_TO="support@edus.ng"
```

```dotenv
# or the API transport, which is faster and avoids SMTP entirely
# MAILER_DSN="brevo+api://${BREVO_API_KEY}@default"
```

```dotenv
# Amazon SES alternative
# MAILER_DSN="ses+smtp://ACCESS_KEY:SECRET_KEY@default?region=eu-west-1"
```

Then:

1. **Remove the development override.** `compose.yaml` sets
   `MAILER_DSN: smtp://mailer:1025` for the local stack; a production deploy must
   not inherit it, or every message goes back into Mailpit.
2. **Prove it, don't assume it.** `bin/mail-check.sh` sends a real message to a
   real address you control.
3. **Read the headers of the message that arrives.** `SPF: pass`,
   `DKIM: pass`, `DMARC: pass`. If any says *none* or *fail*, the DNS is not
   right yet — fix it before switching parents onto it.
4. **Warm up.** Start with your own addresses, then staff, then a few parents.
   Do not open the floodgates on day one.

Full step-by-step, including the Cloudflare gotchas and the "authorised IPs"
confusion: **`docs/operations/EMAIL-SETUP.md`**.

---

## 6. What "done" looks like

- [ ] A test message from the app arrives in a **Gmail** inbox, not spam
- [ ] Its headers show `SPF: pass`, `DKIM: pass`, `DMARC: pass`
- [ ] The **From** name is the school's, the **From** address is on your domain
- [ ] Replies to the message land in `support@` (or the school's own inbox)
- [ ] The four application emails (registration, application received, place
      offered, payment receipt) all arrive
- [ ] A deliberately invalid address produces a **bounce** you can see in the
      provider's dashboard — proof that failures are visible
- [ ] `compose.yaml` no longer forces `MAILER_DSN` to Mailpit in production
- [ ] Somebody owns the "our mail is going to spam" pager

---

## 7. Effort and risk, stated plainly

| Option | Effort | Risk |
|---|---|---|
| Relay via Brevo (+ existing DNS) | **~1 hour** of configuration, plus DNS propagation | Low. Deliverability handled by the provider |
| Relay + per-school sender domains | A few days, plus a DNS checklist per school | Low, but operationally fiddly |
| Self-hosted MTA on one VPS | 1–2 weeks to first reliable delivery, then ongoing | **High.** Silent spam-foldering, blocklist risk, no visibility |
| Self-hosted MTA at real volume | Weeks, plus a dedicated IP and warm-up | Very high |

The one thing that should *not* be done is leaving the current state in
production: Mailpit accepts everything and delivers nothing, so the system
*appears* to send email while no parent ever receives one. That failure is
completely silent — there is no error to notice, because from the application's
point of view the message was accepted.

---

## Related reading

| Topic | Document |
|---|---|
| Step-by-step setup, DNS records, troubleshooting | `docs/operations/EMAIL-SETUP.md` |
| Which emails the system sends | `docs/operations/EMAIL-SETUP.md` §5 |
| Rules for adding a new email template | `docs/operations/EMAIL-SETUP.md` §6 |
| Proving the whole flow end to end | `docs/testing/END-TO-END-ACCEPTANCE-TEST.md` |
