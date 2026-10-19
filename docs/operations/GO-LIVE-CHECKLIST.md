# Go-live checklist — can Edus take money yet?

Everything here has failed once in this project. Work top to bottom; the command
at the end checks all of it.

```bash
docker compose exec -T php php bin/console app:billing:readiness
```

`FAIL` exits non-zero. `WARN` does not block launch but should be understood.
Run it before launch, after any env change, and whenever billing "behaves oddly".

---

## 1. Environment

| Variable | Value | Why |
|---|---|---|
| `APP_ENV` | `prod` | `dev` serves exception pages with stack traces to schools. |
| `APP_DEBUG` | `0` | Same, plus it slows every request. |
| `BASE_DOMAIN` | `edus.ng` | Every emailed link is built from this. `localhost` here means a school receives a link that only works on our laptop. |

## 2. Taking money

| Variable | Value | Why |
|---|---|---|
| `PAYSTACK_SECRET_KEY` | `sk_live_…` | A `sk_test_` key is valid but moves no real money. Empty hides online payment entirely (transfers only). |
| `EDUS_BANK_NAME` | e.g. `Guaranty Trust Bank` | Printed on the pay page, the invoice PDF and the invoice email. |
| `EDUS_BANK_ACCOUNT` | the collection account | **If empty, the pay page and every invoice say "contact us"** — every transfer school then needs a human reply. |
| `EDUS_BANK_ACCOUNT_NAME` | account holder | Printed beside the number. |

After changing any of these:

```bash
docker compose exec -T php php -d memory_limit=512M bin/console cache:clear
docker compose exec -T php php bin/console app:billing:readiness
```

### Paystack dashboard

1. **Webhook URL** → `https://edus.ng/subscription/payment/webhook`
   (the route is public; the signature is what authenticates it).
2. Send a test event from the dashboard and confirm the response is `200`.
3. One real ₦100 payment on a live key, then check:
   - the invoice flips to `APPROVED`,
   - the subscription end date extends,
   - a receipt PDF is available.

> ⚠️ A live webhook that is not registered means every online payment sits in
> `PENDING` until somebody notices. The readiness command cannot see the Paystack
> dashboard — this step is manual, every deploy that changes the domain.

## 3. Mail

| Variable | Value | Why |
|---|---|---|
| `MAILER_DSN` | provider DSN | Reminders, invites and invoices all go through it. |
| `MAILER_FROM` | `Edus <no-reply@edus.ng>` | Providers reject a blank or unverified sender. |
| `MAILER_REPLY_TO` | a monitored inbox | Schools reply to invoices; nobody reads `no-reply@`. |

Verify with one real send (`app:subscription:reminders --dry-run` first, then a
real invite to your own address).

## 4. The scheduler

```cron
# 08:00 server time, daily
0 8 * * * cd /srv/edus && docker compose exec -T php php bin/console app:subscription:reminders >> /var/log/edus-reminders.log 2>&1
```

Details and the container alternative: `docs/operations/SUBSCRIPTION-REMINDERS-CRON.md`.

Without it: **no renewal reminder and no overdue-invoice email is ever sent.**
The readiness command reports `WARN Reminder scheduler` when a subscription
renews within 14 days and nothing has ever been sent.

## 5. Data hygiene

- [ ] Delete test accounts (`qa-billing@edus.test` and friends).
- [ ] Delete the test payments on the demo school, so its statement and invoice
      history show real money only.
- [ ] `bin/generate-og-image.php` output committed if the wordmark changed.
- [ ] `public/` contains no scratch pages (`_*.html`).

## 6. What "working" looks like

Run these once by hand on the live domain and confirm each document opens in a
real browser (not an embedded viewer — those cannot render PDFs at all):

| Document | URL |
|---|---|
| Statement | `https://<school>.edus.ng/subscription/statement.pdf` |
| Invoice | `https://<school>.edus.ng/subscription/invoice/<id>.pdf` |
| Receipt | `https://<school>.edus.ng/subscription/receipt/<id>.pdf` |

Each must be A4 (`MediaBox 595.28 × 841.89`), show the QR footer, and the QR must
resolve to a page that shows **no amounts**.
