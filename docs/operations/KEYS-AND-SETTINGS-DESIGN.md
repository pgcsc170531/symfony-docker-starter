# Where do the Paystack keys and the mail password live?

> **Status:** 🟡 **DISCUSSION — nothing implemented.** This file records the
> options, my recommendation and the decisions only you can make. Nothing here is
> in the code yet.
>
> **Raised:** 23 Oct 2026, after the Paystack account was approved.
> **Read alongside:** `docs/payments/PAYMENT-ARCHITECTURE.md` (account structure),
> `docs/payments/ONLINE-PAYMENT-AND-APPLICATION-FEE.md` (§2.6 the ₦500 charge),
> `docs/operations/EMAIL-SETUP.md` (how mail is configured today),
> `docs/payments/AUDIT-DESIGN.md` (who may see money).

---

## 0. The short answer

**Neither hard-coding nor “env only”.** Use a **hybrid**, with one rule:

> **Environment variables bootstrap the platform. The landlord database operates it.**

| What | Where | Editable in the admin? |
|---|---|---|
| `APP_SECRET`, `APP_ENCRYPTION_KEY`, DB credentials, `BASE_DOMAIN` | **Environment** (server) | No — these are the keys to the building |
| Paystack secret key, mail host/user/password, sender identity, Edus's bank details, per-school Paystack subaccount code | **Landlord database, encrypted at rest**, with env as fallback | **Yes — change it any time, no deploy** |

Why the split rather than “put everything in the database”:

- A secret needed to *boot* the app (DB password, the key that decrypts the rest)
  cannot live in the database it unlocks. That is circular.
- Everything else is operational: the Paystack key is rotated when Paystack asks,
  the SMTP password when Brevo rotates it, the bank account when the account
  changes. None of those should need a code change, a rebuild and a deploy at
  11pm.

---

## 1. What we have today (verified against the code)

| Thing | Today | Consequence |
|---|---|---|
| Paystack secret | `%paystack_secret_key%` ← env `PAYSTACK_SECRET_KEY` (empty fallback) | The key is baked into the **compiled container**. Changing it needs `cache:clear` / a restart — i.e. a deploy. |
| Gateway choice | `GatewayFactory`: `$paystack->isConfigured() ? paystack : fake` | Empty key = the local simulator. Good default, keep it. |
| Mail | `MAILER_DSN`, `MAILER_FROM`, `MAILER_REPLY_TO` — env only, read via `%env(...)%` | Changing the Brevo password needs a deploy. |
| Global settings | `global_settings` table, `setting_key` / `setting_value` **varchar(255)** / `description`, with an `/admin/settings` screen; today holds `sms_price` and `online_payments_enabled` | A place to put settings already exists — but it is **plain text**, `varchar(255)`, and the screen shows values in full. |
| Per-school Paystack | `school_settings.paystack_subaccount_code` (tenant DB) | Not a secret, but it has **no screen** — it is set by hand in SQL. |
| Platform switch | `PlatformPaymentSwitch` → `online_payments_enabled` | The pattern to copy: a small service that reads a global setting. |

**This repository is public** (`github.com/pgcsc170531/symfony-docker-starter`).
That single fact rules out hard-coding: a live key committed once is public
forever, even if deleted in the next commit — git keeps history, and GitHub keeps
the object.

---

## 2. The options, scored

| | **A. Hard-code in PHP** | **B. Environment only (today)** | **C. Landlord DB, plain text** | **D. Hybrid: env + encrypted DB** |
|---|---|---|---|---|
| Rotate a key without a deploy | ❌ | ❌ (rebuild/restart) | ✅ | ✅ |
| Key can reach a public repo | ⚠️ very likely — **disqualifying** | ✅ no | ⚠️ via DB dumps (`mysqldump` in `var/backups`, support hand-offs) | ✅ encrypted, useless without `APP_ENCRYPTION_KEY` |
| Survives a stolen database dump | — | ✅ | ❌ | ✅ |
| Who can change it | anyone with write access to code | whoever holds the server | **any Super Admin login** (too wide?) | Super Admin, and it is audited |
| Disaster recovery on a fresh server | ✅ | ✅ (env is in the deploy) | ❌ DB restore needed | ✅ env still boots; DB restores the rest |
| Visible on screen by accident | ❌ | ❌ | **⚠️ the settings screen prints the value** | ✅ masked, write-only |
| Cost to build | none | none | low | ~1–2 days |

**C is one step from disaster**: `global_settings` values are rendered in full on
`/admin/settings`, and those values would end up in every database dump, in
phpMyAdmin, and on someone's screen during a demo.

---

## 3. The design I recommend (D)

### 3.1 Storage

Reuse `global_settings` rather than inventing a second table, but make it fit:

| Change | Why |
|---|---|
| `setting_value` → **TEXT** | A DSN/SMTP password plus encryption overhead does not fit 255, and truncation of a secret fails in the worst possible way — silently, at 3am, on a payment. |
| add `is_secret` BOOLEAN | Tells the UI “never print this”, tells exports “omit this”, tells the logger “redact”. |
| add `updated_at`, `updated_by` | “Who changed the live key, and when?” is the first question in any incident. |

Value format for secrets: `enc:v1:<base64(nonce ‖ ciphertext)>` — libsodium
`secretbox`, key derived from a **new** `APP_ENCRYPTION_KEY` env var (base64, 32
bytes, generated once).

> Why a separate key and not `APP_SECRET`? `APP_SECRET` is also the session/CSRF
> secret, and rotating it (which you should do after any suspected leak) would
> then silently decrypt every stored secret into garbage. Two keys, two purposes.

A value with no `enc:v1:` prefix is treated as **not encrypted and refused** for
`is_secret` rows — so a half-migrated row fails loudly instead of being stored in
the clear.

### 3.2 Reading it — `PlatformSettings`

One service, the only way the app ever reads platform config:

```php
$settings->paystackSecretKey();   // decrypted, or env fallback, or null
$settings->mailConfig();          // host, port, user, pass, from, reply-to
$settings->bankDetails();         // name, account, account name
```

- **Precedence:** DB (if set) → env → built-in default. So a fresh install with an
  empty DB behaves exactly as today, and a restored DB behaves like production.
- **Cached** in `cache.app` under a version counter that every save bumps — no DB
  read per request, no stale key after a change.
- **`PaystackGateway` reads it at call time, not in the constructor.** Today the key
  arrives as an autowired parameter, which is why changing it needs a container
  rebuild. That is the actual implementation change: one line in the constructor
  signature, one line in `authorize()/initialize()`.
- **`#[SensitiveParameter]`** on anything that receives a secret, so it cannot appear
  in a stack trace.

### 3.3 Writing it — two screens under `/admin/settings`

**Payments** — Paystack
- Secret key: shown as `sk_live_…3f9c` (last 4 only) + **Replace** / **Clear**.
- Big honest badge: **LIVE — real money moves** vs **TEST — no real money**,
  derived from the key prefix, not from a checkbox someone forgot to switch.
- **“Check the key”** button → `GET https://api.paystack.co/balance`; shows the
  account name and balance, proving the key works before you close the ticket.
- Webhook URL to paste into the Paystack dashboard (generated, shown, copyable).
- Note under it: *the platform-wide **online payments** switch still exists and
  overrides this* — a working key does not turn payments on by itself.

**Email**
- Host, port, encryption, username, password (masked, replace-only), From name +
  address, Reply-to.
- **“Send a test email to me”** — with the same transport the app will use.
- Shows whether the effective config comes from the **database** or the
  **environment**, because during an incident that is the difference between
  “change it here” and “ask the server admin”.

Both: `ROLE_SUPER_ADMIN` only, POST + CSRF, secrets never rendered back into the
HTML (so no “value in view-source”, no browser autofill of an old key).

### 3.4 The audit trail

New table `platform_setting_history`: `setting_key`, `action` (`set`/`cleared`),
`actor`, `at`, and **no value**. It answers “who rotated the key and when” without
creating a second copy of the secret.

### 3.5 What Paystack actually needs (the “related keys”)

| Item | Secret? | Where |
|---|---|---|
| `sk_live_…` / `sk_test_…` secret key | **Yes** | landlord DB, encrypted |
| Public key `pk_…` | No | Only needed if we ever use inline JS checkout; today we use hosted checkout, so it is optional. Store it anyway, plainly. |
| Webhook signature secret | No — Paystack signs with the **same secret key** (HMAC-SHA512) | nothing extra to store |
| Per-school **subaccount code** (`ACCT_…`) | No, but must be right | tenant DB (`school_settings.paystack_subaccount_code`) — **give it a field in the school's settings screen**; setting it by SQL is how a school silently collects into Edus's account instead of its own |
| Edus bank details (for manual transfers) | No | landlord DB (env fallback) — already `EDUS_BANK_*` |
| Paystack Plan codes (only if we move subscriptions to auto-recurring) | No | landlord DB, later phase |

---

## 4. Failure behaviour (decided here, so it is not decided at 3am)

| Situation | Behaviour |
|---|---|
| No key at all | `isConfigured()` false → the **fake gateway** (today's behaviour). Parents see transfers only; admin sees “online payment is off — no key”. |
| Wrong/revoked key | Paystack returns 401 → **log it, show the parent a plain “we could not reach the payment provider, please use a transfer”**, never a raw API error. Admin banner stays until a “Check the key” passes. |
| **Test key on production** | Red banner on every payment screen + `app:billing:readiness` exits non-zero. This is the mistake that looks fine until a parent's real ₦68,600 goes nowhere. |
| SMTP password wrong | The payment/receipt is still recorded; the email is marked **not sent** and surfaced to the bursar. We never pretend an email went out. |
| Encryption key missing/rotated | Secrets refuse to decrypt → treated as “not configured”, loud error in the log and on the settings screen. Never fall back to plaintext silently. |
| DB restored from a dump without `APP_ENCRYPTION_KEY` | Same as above: the app runs, payments fall back to transfers, and the operator is told to restore the key rather than being silently broken. |

---

## 5. Rotating a key (the runbook this design buys you)

**Paystack, zero downtime:** create the new key in Paystack → paste into
`/admin/settings/payments` → press **Check the key** → it passes → revoke the old
key in Paystack. No deploy, no restart, no window where payments fail.

**SMTP:** paste the new password → **Send a test email** → only then delete the old
credential at Brevo.

Today both are: edit `.env.local` → `cache:clear` → restart the container → hope.

---

## 6. Implementation plan (when you say go)

| Phase | Work | Done when |
|---|---|---|
| 1 | `APP_ENCRYPTION_KEY` in env, `SecretCipher`, `GlobalSetting` gains `is_secret`/`updated_at`/`updated_by`, `setting_value` → TEXT, `platform_setting_history` | a secret round-trips through encrypt→decrypt; a plaintext `is_secret` row is refused |
| 2 | `PlatformSettings` service (DB → env → default, cached, version-bumped) | a value changed in SQL is picked up after a save; nothing reads `%env(...)%` for these keys any more |
| 3 | `PaystackGateway` reads the service at call time | changing the key takes effect **without** `cache:clear` — prove it |
| 4 | `/admin/settings/payments` + `/admin/settings/email` (masked, write-only, test buttons) | a non-Super-Admin gets 403; the key never appears in the HTML |
| 5 | Reachability: `app:billing:readiness` reads effective values, says **DB or env**, test/live | command fails on a test key in prod |

Tenant work (subaccount field on the school settings screen) is independent and
can ship with phase 4.

---

## 7. Decisions I need from you

1. **Do you accept the split (env bootstraps / DB operates), or do you want
   *everything* in the admin?** I recommend the split; the DB password and the
   encryption key must stay in the environment.
2. **Who may change the Paystack key?** My recommendation: `ROLE_SUPER_ADMIN`
   only, never a school's own admin. A key is platform money.
3. **Live key now, or test key first?** If live, I will make the “LIVE — real
   money” badge impossible to miss and have `app:billing:readiness` insist on a
   real bank account and a real sender address before it passes.
4. **Mail sender identity:** keep the single platform sender
   `Edus <no-reply@edus.ng>` with `reply-to` = the school's own address (what we
   do now, and what Brevo can actually deliver), or invest in per-school domains?
   Per-school needs SPF/DKIM/DMARC set up for every school's domain — I would not
   do that before 20 schools.
5. **Should a *school* be able to set its own SMTP** (so mail comes from its own
   domain) — same answer as 4, but worth saying out loud, because schools will ask.
6. **Do we still allow env overrides in production?** I recommend yes, as a
   break-glass: if the DB is being restored, `PAYSTACK_SECRET_KEY` in the server
   environment still boots a working app.
7. **Rotation policy:** shall I add a “last rotated” date and a nudge in
   `app:billing:readiness` if the live key is older than, say, 12 months?

---

## 8. What this design refuses to do

- **Never** commit a key, a password or a DSN to this repository — it is public.
- **Never** store a Paystack secret in a tenant database. One key is platform
  money; a school admin must not be able to see or change it.
- **Never** render a secret back to the browser, not even masked-but-full, not in
  a CSV, not in a PDF, not in an email, not in a flash message.
- **Never** log it — including in exception context, which is why
  `#[SensitiveParameter]` is part of the plan and not an afterthought.
- **Never** fall back to plaintext when decryption fails.
- **Never** let a test key look like a live one on screen.
