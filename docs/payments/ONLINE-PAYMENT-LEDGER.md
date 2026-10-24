# The online-payment ledger — design (discussion, not build)

**Status:** proposal for review. Nothing here is implemented yet.
**Owner decisions requested:** §13 (D1–D12).
**Related:** `VIRTUAL-ACCOUNTS-DESIGN.md` (the account itself), `PAYMENT-ARCHITECTURE.md` (who holds the money), `docs/payments/` for fee schedules.

---

## 0. The short answer

We can already take a parent's money. We cannot yet **prove where each naira went**, in one place, for one payment, on one screen — and that is the thing a school, an auditor or a bank dispute will ask for.

A ledger is not another table of payments. It is a **journal**: every movement between named accounts, written once, never edited, always balancing to zero. Payments (what a parent did) and postings (what that did to the books) are two different facts, and today we only record the first.

**Recommendation:** an append-only, double-entry ledger per tenant database, with a small roll-up on the landlord side for Edus's own revenue. Built in three phases, §15.

---

## 1. What exists today, and what each table can answer

| Table | Written when | Can answer | **Cannot** answer |
|---|---|---|---|
| `invoice` | Bursar bills a pupil | What was billed, what is still due | Where the money went, what it cost to collect |
| `invoice_item` | Same | What the bill was for | — |
| `payment` | Money is recorded against a bill (cash, transfer, gateway) | "A payment of ₦X arrived for invoice Y on date Z", method, who confirmed it | Whether that money is in **our** Paystack balance, already paid to the school, or owed to it |
| `payment_intent` | Parent taps Pay | The parent's attempt: amount, charge, channel, provider reference, raw payload, state | Anything about settlement, split, payout or cost |
| `account_credit` | A transfer lands on a family account | Money arrived and which bill it was matched to (or that it is unassigned) | The charge, the gateway cost, the school's share |
| `virtual_account` | Parent opts in | The family's NUBAN and its state | Money — by design, it holds no balance |
| `student_advance` | Overpayment | Money held in credit for a pupil | Where the physical naira is |

So today the answer to *"the parent paid ₦63,250 on 23 October — where is that money now?"* requires reading **four** tables and knowing Paystack's rules by heart. Nobody in a bursary can do that, and no auditor will accept it.

## 2. The five questions we cannot answer today

1. **Per payment:** of ₦63,250, how much belongs to the school, how much is Edus's charge, how much went to Paystack, how much is VAT?
2. **Per school:** how much of that school's money are we **holding** at this moment — not yet transferred to their bank?
3. **Per period:** what did this school collect online this term, and what did it cost them to collect it?
4. **Platform:** what has Edus earned from handling charges, net of gateway cost, month by month?
5. **Dispute:** a parent says they paid; the school says nothing arrived. Which leg failed — the transfer, the split, or the payout?

Every one of these is a **balance between named destinations**, which is precisely what double entry is for.

## 3. Why a "reporting view" is not enough

The cheap option is a SQL view or a report that re-derives these numbers on demand from `payment_intent` + `account_credit` + `payment`. It fails for four reasons:

- **It changes its mind.** Fix a rule later (say, how VAT is treated) and every historical report silently restates. The school's March statement no longer matches the March statement you emailed them.
- **It cannot express "we owe the school ₦1.2m right now"** without recomputing the whole history every time.
- **It cannot record a fact that has no payment behind it** — a refund, a bank charge correction, a transfer to the school, a bad debt.
- **It cannot prove anything.** A view is a calculation; a ledger is an assertion made at the time, with a reference to what caused it.

A view tells you what you *think* happened. A ledger is the record of what you *decided* at the time — which is what survives an argument.

## 4. The model

**Append-only, double-entry, per tenant.**

- Every event posts **two or more lines**; the sum of debits equals the sum of credits. If a posting does not balance, it is not written.
- **NO UPDATE. NO DELETE.** A mistake is corrected by posting a reversing entry that references the original. The history keeps both.
- **Amounts are integers in kobo.** `₦63,250.00` is `6325000`. No floats, ever — a kobo that goes missing in a school's books is a support call.
- **Every entry carries its cause:** `source_type` (`credit`, `payout`, `refund`, `gateway_fee`, `manual`, `reversal`) and `source_id` (the `account_credit.id`, the Paystack reference…), with a **unique constraint** so a replayed webhook cannot double-post.
- **Currency is NGN only**, stored on the entry. Multi-currency is a non-goal (§12).
- **One ledger per school database** — it belongs to that school's books, it cannot be lost by a landlord schema change, and it can be exported for one school without touching the others.

## 5. Chart of accounts

Not a full GAAP chart — six accounts per school carry all of it, plus three platform accounts.

| Code | Account | Type | Meaning |
|---|---|---|---|
| `1000` | Cash at Paystack | Asset | Money in **our** Paystack balance, not yet paid out |
| `1100` | Cash at bank | Asset | Only used when a payout clears (optional, §13 D4) |
| `2000` | School payable | Liability | Money we hold **on behalf of this school** |
| `2100` | Unallocated credits | Liability | Money received that matches no bill yet |
| `4000` | School fees collected | Income (school) | What the school has actually earned from parents paying |
| `5000` | Gateway cost | Expense (school or Edus, §13 D2) | Paystack's fee |
| `5010` | VAT on gateway cost | Expense | 7.5% on that fee |
| `4100` | Handling-charge revenue | Income (**Edus**) | The ₦650 |
| `4900` | Application-fee revenue | Income (**Edus**) | The admission form fee (no school involved) |
| `9000` | Suspense | — | Anything unmapped; must trend to zero, and its balance is an alarm |

Per-school accounts are scoped by `school_id` on the entry; Edus's own accounts are the same codes with the tenant's platform rows. (How Edus's books are aggregated across tenants is §13 D3.)

## 6. Posting rules — every event, in order

Worked with the live example: **invoice ₦62,600, parent transfers ₦63,250 (fee + ₦650 charge), Paystack takes ₦322.50 (1% capped ₦300 + 7.5% VAT).**

### 6.1 Parent taps Pay → intent created
**No posting.** An intention is not money. The ledger must never contain a line for a payment that has not happened, or every abandoned checkout becomes a phantom entry.

### 6.2 The transfer lands on the family account (webhook `charge.success`, `dedicated_nuban`)

| Account | Debit | Credit |
|---|---|---|
| `1000` Cash at Paystack | ₦63,250.00 | |
| `2000` School payable | | ₦62,600.00 |
| `4100` Handling charge (Edus) | | ₦650.00 |

Read it aloud: *the cash arrived; of it, ₦62,600 belongs to the school and ₦650 is ours.* The ₦650 is **not** the school's money at any moment — which is exactly what `percentage_charge: 0` gets wrong today (§7).

### 6.3 It is matched to the invoice

| Account | Debit | Credit |
|---|---|---|
| `2000` School payable | ₦62,600.00 | |
| `4000` School fees collected | | ₦62,600.00 |

Now the school has "earned" it and we still hold the cash. The invoice's `paid_amount` and this posting must agree — a nightly check enforces it (§9).

### 6.4 It does not match a bill (unassigned)
Same as 6.2, but `2000` becomes **`2100` Unallocated credits**. When the bursar assigns it later, post 6.3 out of `2100`. This is why the unassigned inbox is a *balance*, not a list of loose rows — and why an ageing report on `2100` is trivial once the ledger exists.

### 6.5 Paystack's fee (from the settlement report, not guessed)

| Account | Debit | Credit |
|---|---|---|
| `5000` Gateway cost | ₦300.00 | |
| `5010` VAT on gateway cost | ₦22.50 | |
| `1000` Cash at Paystack | | ₦322.50 |

Who bears it is a **policy decision**, not a posting decision (§13 D2). Today the docs say Edus bears it out of the ₦650 — which leaves ₦327.50 of real revenue per payment, and the ledger makes that visible for the first time.

### 6.6 Paying the school (payout)

| Account | Debit | Credit |
|---|---|---|
| `2000` School payable | ₦1,200,000.00 | |
| `1000` Cash at Paystack | | ₦1,200,000.00 |

The payout is *driven by* the `2000` balance — "pay the school what we hold for them" — and cannot be posted for more than that balance. That single constraint is what stops the classic collection-platform failure of paying a school money that came from another school.

### 6.7 Refund to a parent

Never delete 6.2. Post the mirror, referencing the original credit:

| Account | Debit | Credit |
|---|---|---|
| `4000` School fees collected | ₦62,600.00 | |
| `4100` Handling charge (Edus) | ₦650.00 | |
| `1000` Cash at Paystack | | ₦63,250.00 |

(The charge may or may not be refunded; that is a policy question, §13 D6.)

### 6.8 Application fee (admission form)
No school money at all: `1000` / `4900` in 6.2's place, plus 6.3 skipped. The pupil is not yet a debtor, so `2000` and `4000` never appear.

## 7. The ₦650 problem — the ledger is what settles it

Today the school's subaccount is created with `percentage_charge: 0`, and Paystack's own documentation says *"20% goes to the main account and the rest goes to the subaccount"*. At 0% the school receives **all of ₦63,250**, including our ₦650, and Edus receives nothing while still bearing the gateway fee.

That is not a display bug; it is a contradiction between two decisions:

- **The promise to parents:** "the school receives its fee in full; the charge on top covers the bank and our API cost."
- **The instrument:** a percentage split, which cannot hold a **flat** ₦650 constant as invoices vary.

Two models, and the ledger is written the same way in both — only 6.2/6.5/6.6 differ:

| | **Model A — settle to Edus, pay schools out** | **Model B — percentage split** |
|---|---|---|
| Who receives the transfer | Edus's Paystack balance | School's subaccount directly |
| Naira to the school | exactly ₦62,600 (a payout) | 97–99% of ₦63,250, rounded |
| Edus's ₦650 | exact, always | ✗ varies with the invoice |
| Cost to run | payout fee per school per batch | none |
| School's experience | a settlement in their bank, weekly | a credit per parent |
| Ledger work | 6.6 payout job + reconciliation | less |

**Model A is the only one that honours what the parent was told.** The ledger makes the choice unavoidable rather than discovering it in a bank statement.

## 8. Data model (sketch)

```
ledger_entry
  id                 bigint pk
  school_id          int          -- tenant school (or null on the landlord book)
  occurred_at        datetime     -- when the money moved, not when we heard
  recorded_at        datetime     -- when we wrote it
  currency           char(3)      -- 'NGN'
  source_type        varchar(24)  -- credit | payout | refund | gateway_fee | manual | reversal
  source_type_id     bigint       -- account_credit.id, payment.id, payout.id …
  source_ref         varchar(120) -- Paystack reference / provider id
  memo               varchar(255) -- human sentence, shown to the bursar
  created_by         varchar(80)  -- 'webhook', 'system:payout', 'user:12'
  reverses_entry_id  bigint null  -- set on reversals
  UNIQUE (school_id, source_type, source_type_id)   -- idempotency

ledger_line
  id                 bigint pk
  entry_id           bigint fk -> ledger_entry (onDelete: RESTRICT)
  account_code       varchar(8)   -- 1000, 2000, 4100 …
  direction          char(1)      -- 'D' | 'C'
  amount             bigint       -- kobo, always positive
  school_id          int null     -- the school an account belongs to
```

Rules enforced in **one** service, `LedgerPoster`:

1. Lines in an entry must sum to zero (debits = credits) or it throws.
2. `amount > 0`; direction carries the sign, never the number.
3. A reversal may not exceed the original entry it references.
4. `2000` may not go negative — you cannot pay a school money you do not hold.
5. Nothing is ever updated or deleted; the entity has no setters for `amount`, `direction`, `account_code`.

## 9. Reconciliation — the part that makes it trustworthy

1. **Daily, against Paystack.** Pull the settlement/transaction list for the day; every line must map to a `ledger_entry` with `source_type='credit'`. Three possible outcomes: matched, **in Paystack but not in the ledger** (a missed webhook — the alarm that matters), or in the ledger but not in Paystack (a bug — impossible if posting only happens on verified webhooks).
2. **Balance check per school:** `2000 + 2100` must equal *"what we owe this school"* — and must match the sum of credits minus payouts, computed independently. Two methods, one number.
3. **Invoice agreement:** `invoice.paid_amount` must equal the sum of `4000` postings for that invoice. A nightly command reports any invoice where they differ, with both numbers.
4. **Suspense (`9000`)** must be zero. Any balance is a mapping gap, and appears on the operator dashboard rather than in a log file.
5. **Payout file** is generated *from* the `2000` balance and its result posted back — so the payout and the ledger can never disagree about what was sent.

## 10. What the school and the operator see

| Screen | Built from | Answers |
|---|---|---|
| Bursar → Finance → **Online payments** (new) | `ledger_entry` for this school, filterable by term/date/method | Every online payment: parent, pupil, invoice, amount, charge, cost, payout batch |
| Bursar → **What we hold** (a number, not a table) | `2000` + `2100` balance | "₦1,204,600 received online, ₦1,200,000 paid out, ₦4,600 pending match" |
| Statement / receipt PDFs | ledger balances, not recomputation | A document that cannot be restated by a later code change |
| Operator → **Revenue** | `4100 + 4900 − 5000 − 5010` | Edus's true take: charges earned, gateway cost, net per month |
| Operator → **Float held** | sum of `2000` across schools | Our real liability — the number that must appear on the dashboard before anyone asks |

## 11. What the ledger is **not**

- Not a second source of truth for *bills* — invoices stay exactly as they are; the ledger records their consequences.
- Not a bank feed — we never post from a CSV a human typed; every posting has a system source or a named operator.
- Not full accounting software: no journals-by-hand for arbitrary accounts, no period close, no multi-currency, no consolidation.
- Not a replacement for Paystack's dashboard — it is the *interpretation* of it, for our users.

## 12. Non-goals (for now)

Multi-currency · inter-school transfers · partial allocation of one credit across several invoices beyond what `AccountCredit` already does · tax reporting beyond VAT on gateway cost · automated statutory reports.

## 13. Decisions needed

| # | Question | Options | Recommendation |
|---|---|---|---|
| **D1** | Double entry, or an event log with running balances? | (a) true double entry (b) single-sided events | **(a)** — balances then cannot drift, and paying a school more than we hold becomes impossible by construction |
| **D2** | Who bears Paystack's fee? | (a) Edus, out of ₦650 (b) the school, out of its fee | **(a)** — matches the parent-facing promise; the ledger then shows ₦327.50 real revenue per payment |
| **D3** | Where does Edus's own revenue live? | (a) landlord ledger (b) tenant rows tagged Edus (c) nightly roll-up to landlord | **(c)** roll-up for dashboards, tenant rows as source of truth |
| **D4** | Track `1100` Cash at bank after payout clears? | (a) yes, per payout (b) no, stop at `1000` | **(b)** now — one less reconciliation |
| **D5** | Model A (payout) or Model B (percentage split)? | see §7 | **A** — it is the only model where ₦650 is exact |
| **D6** | Is the ₦650 refunded when a payment is refunded? | (a) always (b) never (c) case by case | **(a)** — a parent did not get the service they paid for |
| **D7** | Amounts | (a) integers in kobo (b) decimal strings like today | **(a)** — no rounding drift |
| **D8** | Who may post manual entries? | (a) nobody (b) `ROLE_SUPER_ADMIN` only, with a reason string | **(b)**, audited |
| **D9** | Do payouts wait for a threshold? | (a) per payment (b) daily (c) weekly or above ₦X | **(c)**, per school, minimum ₦50,000 |
| **D10** | Backfill historical payments? | (a) yes (b) start clean at go-live | **(b)**, with opening balances entered as one dated entry per school |
| **D11** | Does the ledger live in the tenant DB or the landlord DB? | (a) tenant (b) landlord (c) both | **(a)** tenant, per §4 |
| **D12** | Ledger for landlord subscription payments too? | (a) same engine, landlord book (b) later | **(a)** later, same engine — the code should not be written twice |

## 14. Failure modes and their owners

| Failure | Today | With the ledger |
|---|---|---|
| Webhook lost | Money invisible in the app | Daily reconciliation reports "in Paystack, not in ledger" — with the reference |
| Webhook replayed | Guarded ad hoc in `AccountCredit` | Unique `(source_type, source_type_id)` — structural |
| Parent pays wrong school's account | Manual move, no record | Reversal entry + a new posting, both visible in the statement |
| School paid more than collected | Possible | Impossible: `2000` cannot go negative |
| Charge silently goes to the school (`percentage_charge: 0`) | Invisible until someone audits a bank statement | 6.2 splits it explicitly; a mismatch with reality shows up in reconciliation the next day |
| Currency rounding over thousands of payments | Possible with decimal strings | Kobo integers |

## 15. Phases

**Phase 1 — the journal and one flow (smallest thing that is true).**
`ledger_entry` + `ledger_line` + `LedgerPoster`; post 6.2, 6.3, 6.4 from the existing credit/settlement code; balances `1000 / 2000 / 2100 / 4100`; a read-only bursar screen showing one school's online payments and "what we hold". No payouts yet, no fee postings.

**Phase 2 — cost and payout.**
Post 6.5 from the settlement report; the payout job driven by the `2000` balance (D9); reconciliation command; the operator revenue screen.

**Phase 3 — the edges.**
Refunds (6.7), application fees (6.8), opening balances, landlord-book reuse (D12), the nightly invoice-agreement check as an alert rather than a log line.

**Definition of done for Phase 1:** for one real school, every naira received online can be walked from a parent's transfer to an invoice line and a school balance, and the balance equals what an independent recomputation says.

## 16. Open questions for Paystack / the bank

1. Does the settlement report expose the **fee and VAT per transaction**, or only per settlement day? (Phase 2 depends on it — otherwise 6.5 is estimated per day, which is weaker.)
2. On a subaccount with `percentage_charge: 0`, is the gateway fee deducted from the **main** account's share (i.e. from Edus) as the docs imply? (Confirm before deciding D2.)
3. Are payouts to a school's **third-party** bank account allowed, or must the settlement account match the business name? (Decides whether 6.6 is even possible as designed.)
4. Is there a per-transaction way to take a **flat** share into the main account on a dedicated-account credit? (If yes, Model B becomes viable and D5 changes.)

---

## 17. Running the payout — what is built, what is not

**Built and working today:**

| Piece | Where | State |
|---|---|---|
| What we owe each school | `LedgerPoster::schoolPayable()` | ✅ reads the journal, not a recomputation |
| Payout row + reference | `App\Entity\Tenant\Payout` (`simulated` flag) | ✅ |
| Refusal to overpay | `PayoutService::run()` **and** `LedgerPoster::payout()` | ✅ two independent gates |
| Posting the payout | Dr `2000` / Cr `1000` | ✅ balanced, idempotent by payout id |
| Command | `app:payments:payout [--school=] [--min=] [--simulate]` | ✅ |
| **The actual bank transfer** | `PayoutService::run()` with `$simulate = false` | ⛔ **refuses**: *"Real payouts are not enabled yet."* |

The provider call is deliberately absent until `transferrecipient` + `transfer` are exercised against a test school. What has to be added:

1. `PaystackClient::createTransferRecipient($name, $bankCode, $accountNumber)` — cache the returned `RCP_…` on the school row (one per school, not per payout).
2. `PaystackClient::initiateTransfer($recipientCode, $amountKobo, $reason, $reference)`.
3. Use `Payout::getReference()` as Paystack's transfer reference, so a retry after a timeout cannot transfer twice.
4. Webhooks `transfer.success` / `transfer.failed` → `markSuccess()` / `markFailed()`. A failure must **not** reverse the ledger entry optimistically — the money left our balance either way; it is re-credited only when Paystack confirms the bounce.
5. Only then flip the refusal off, behind a per-school switch, and pilot on one school with a ₦1 invoice.

**Scheduling.** Nothing in the repository schedules commands — the host's cron does (see `FEE-REPORT-EMAILS.md`). One entry, once a day:

```cron
# Pay schools what the ledger holds for them — daily at 00:30
30 0 * * *  cd /home/ibrahim/projects/symfony-docker-starter && docker compose exec -T php php bin/console app:payments:payout --min=1000 >> var/log/payouts.log 2>&1
```

- `--min=1000` stops small balances consuming a ₦25 transfer fee.
- Safe to run twice: the second run finds `2000` at zero and skips.
- Run it **after** Paystack has settled the day's transfers into the balance.
- `--simulate` is for proving the cycle, never for production.

**What the school sees** (`Finance → Online Payments`): *Collected for the school · Held for the school · Paid to your bank*, and a statement of exactly those movements. The payer's charge and the gateway cost are **not** shown — they are never taken from the school's fee, and printing them invites a dispute about a charge the school does not pay.
