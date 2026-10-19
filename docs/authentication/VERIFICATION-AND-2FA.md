# Edus — Verification, OTP & 2FA design

> **Status:** 🟡 DRAFT for discussion.
> **Question this answers:** SMS OTP is expensive — what should we use to verify parents and protect
> accounts? Email? TOTP (Google Authenticator)? SMS?
> **Related:** `docs/parent-application/DESIGN.md` §10–11, `docs/payments/PAYMENT-COLLECTION.md`

---

## 1. Current state of authentication

| Thing | Today |
|---|---|
| Tenant login | `app_login` — form login, email + password, CSRF (`security.yaml`) |
| Roles | `ROLE_PARENT`, `ROLE_TEACHER`, `ROLE_BURSAR`, `ROLE_STORE`, `ROLE_ADMIN`, `ROLE_HOD`, `ROLE_SUPER_ADMIN`, `ROLE_AGENT` |
| Parent accounts created by staff | password set to a **hard-coded default** (`AdmissionController` hashes `'parent'`) → parents never change it |
| Bulk import parents | default password `12345678` (hashed once and reused — see bulk-import notes) |
| 2FA / OTP | **none** — no second factor anywhere, including `ROLE_SUPER_ADMIN` |
| Email | `symfony/mailer` installed but **`MAILER_DSN=null://null`** → every email is silently discarded |
| SMS | `NotificationService::sendSms()` → Termii, deducted from the school's SMS wallet |
| TOTP/QR libraries | **none installed** |

Two consequences worth stating plainly:

1. **Right now we cannot send email at all.** Any "email verification" feature is blocked on
   configuring a real transport (`MAILER_DSN`) in production.
2. Every parent account created by staff today has a **guessable shared password**. That is a bigger
   security problem than OTP cost, and this module is the natural place to fix it.

---

## 2. Decision: use three channels, each for the job it is good at

| Job | Channel | Why |
|---|---|---|
| Prove a parent owns their email | **Email link** (free, signed, single-use) | Zero marginal cost, works on any phone, no SIM dependency |
| Parent signs in | **Email + password**, or **magic link** (email) | Simple; combined with email verification it proves identity |
| Second factor for staff/admin/super-admin | **TOTP** (Google Authenticator / Authy / Microsoft Authenticator) | **₦0 per use**, works offline, no delivery failures, 2G-friendly |
| Second factor for parents (opt-in, recommended) | **TOTP** | Same reasons; one QR scan at registration |
| Account recovery when there is no email or authenticator | **SMS as a fallback**, paid from the school wallet | Rare path, so the cost is bounded |
| Day-to-day notifications (fee received, results ready) | **SMS** (existing) | Not an auth secret; convenience only |

**Recommendation: replace SMS OTP with email verification + TOTP. Keep SMS for notifications and as
a last-resort recovery channel.** SMS OTP has all three drawbacks at once — it costs money per
message, needs network at the exact moment the parent is trying to sign in, and delivery failures are
common — while TOTP costs nothing and never fails to deliver.

### 2.1 Cost shape (why this matters)

SMS OTP is charged per message, and a single signup can consume 2–3 messages (send, resend, retry).
For a 500-application season that is 1,000–1,500 messages **deducted from each school's wallet** —
money the school would rather spend on parent notices. Email and TOTP have **no per-use cost**.
(Confirm current Termii per-message pricing per bundle before quoting a figure to schools — I have
not verified it here.)

### 2.2 Honest trade-offs

| | Email link | TOTP | SMS OTP |
|---|---|---|---|
| Cost per use | ₦0 | ₦0 | Per message |
| Works offline / no network | ✗ (needs to receive mail) | ✓ | ✗ |
| Needs a smartphone app | ✗ | ✓ (authenticator app) | ✗ |
| Delivery reliability | Good (if transport configured) | Perfect (local math) | Variable |
| Usability for a first-time, low-literacy parent | Best | Needs a QR scan + explanation | Familiar |
| Can be phished | Yes | No (code is bound to the site + time) | Yes |

That last row is why TOTP is the right second factor for **staff and admins** — a bursar account can
move money and fees; a phished parent account cannot.

---

## 3. The duplicate-account case (your question: OTP or email?)

When a parent registers with an email/phone we already have, we must let them in **without**
confirming that an account exists (that would leak the school's parent list).

**Recommended flow — email first:**

```
Parent submits: "an account may already exist for this contact"
        │
        ├── We have an email on file → send a signed one-time link ("sign in / set a new password")
        │      → identical response text no matter what, so nothing is leaked
        │
        ├── No email on file (phone only) → offer SMS fallback (school wallet pays)
        │
        └── Neither works → "Please visit the school office with a valid ID"
```

The magic link doubles as **email ownership verification** and as **password reset**, so we build the
flow once. Symfony supports it natively via `framework.login_link` (`LoginLinkHandler`) — signed
URLs, no extra table, no new package. `symfony/mailer` is already installed, so the only missing
piece is a **real transport** (§5, Phase 1).

---

## 4. What is already in the database

Added with the parent-application work (already applied to all tenant databases):

| Entity | Fields | Purpose |
|---|---|---|
| `User` (tenant) | `totp_secret`, `totp_enabled`, `totp_enabled_at`, `totp_recovery_codes` | TOTP for **any** user — parents, teachers, bursars, admins |
| `Guardian` | `is_verified`, `verified_at`, `email_verified_at` | "This parent proved who they are" — gates application submission |

Recovery codes are stored as a **JSON array of hashes** (`User::setTotpRecoveryCodes()`), never plain
text. The secret must also be encrypted at rest (§5) — it is the second factor's private key.

---

## 5. Rollout plan

### Phase 1 — Make email real (blocking prerequisite)
- [ ] Configure a real transport: `MAILER_DSN` (Brevo / Resend / Amazon SES / Postmark) on the VPS
  and locally; verify with a test command.
- [ ] `from` address per school (school name + reply-to the school's email).
- [ ] Enable `framework.login_link`; add `/login/link` route + "email me a sign-in link" button on
  the tenant login page.
- [ ] Email templates: verify email, magic link, password set/reset, application received,
  application decision, admission welcome.
- [ ] Parent registration: **verify by email link**; mark `Guardian.emailVerifiedAt`.

### Phase 2 — TOTP
- [ ] Install `schematron/otp-bundle` (+ `endroid/qr-code` for the QR) — Symfony-native
  (`TwoFactorInterface`, TOTP authenticator, QR generation). *Alternative:* `spomky-labs/otphp` +
  `bacon/bacon-qr-code` if we prefer to own the implementation.
- [ ] Encrypt `totp_secret` at rest (`ENCRYPTION_KEY` / sodium) and never expose it in any API.
- [ ] Enrolment screen: QR + manual key + "enter the current code to confirm" + **show 8 recovery
  codes once**.
- [ ] Enforcement matrix:

| Role | TOTP |
|---|---|
| `ROLE_SUPER_ADMIN`, `ROLE_ADMIN`, `ROLE_BURSAR` | **Required** |
| `ROLE_STORE`, `ROLE_TEACHER`, `ROLE_HOD` | Required after first login (grace period) |
| `ROLE_PARENT` | **Optional but offered** at registration and in profile |
| `ROLE_AGENT` | Required |

- [ ] Recovery: recovery code **or** SMS fallback (school wallet) **or** super-admin reset.
- [ ] Reset path for a lost phone: verify by email link → re-enrol.

### Phase 3 — Policy
- [ ] Kill the shared default passwords: force a password set on first login for staff-created and
  imported parent accounts (see §1).
- [ ] Log auth events (new factor enrolled, recovery code used, admin reset) into the existing
  `NotificationLog`/audit pattern and SMS the account owner when something changes.
- [ ] Optional: allow a school to *require* TOTP for its parents.

### Phase 4 — Mobile app (your longer-term idea)
Because we adopt **standard TOTP secrets**, the same secret can later power our own app's
"generate a code" screen (and, if we ever want it, push-approval on top). Nothing we build now
locks us out of that; the secret, not the app, is the credential.

---

## 6. Consequences for the Parent Application module

| Was (design v1) | Now |
|---|---|
| SMS OTP before the first application can be submitted | **Email verification link** (`Guardian.emailVerifiedAt`); TOTP offered |
| `Q1` = "require SMS OTP?" | Answered: **no SMS OTP** — email verification, TOTP optional |
| `Q3` = where do codes live? | No OTP table needed. `User.totp_*` (added) + `Guardian.email_verified_at` (added) |
| Duplicate-account edge case | Email magic link → SMS fallback → "visit the school office" |
| SMS from the application flow | Only **notifications** (submitted / decision / enrolled), never codes |

---

## 7. Open questions

| # | Question | Options | My recommendation |
|---|---|---|---|
| A1 | Which email provider? | Brevo · Resend · SES · Postmark | **Resend or Brevo** for low volume with good deliverability; SES if volume grows |
| A2 | Show the password field to parents at all? | (a) email + password (b) magic link only | **(a)** with magic link available — parents expect a password, and the portal is used repeatedly |
| A3 | TOTP for parents | (a) optional (b) required by school setting | **(a)** now, (b) as a per-school toggle later |
| A4 | SMS fallback allowed at all? | (a) yes, wallet-funded (b) no | **(a)** — bounded, rare, and better than locking a parent out |
| A5 | Who pays for staff SMS fallback? | school wallet · platform | **school wallet** (it is their staff) |
| A6 | Migration for existing parents with default passwords | (a) force reset at next login (b) leave them | **(a)** for accounts created by staff/bulk import |
