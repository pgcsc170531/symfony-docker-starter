# Edus — the audit trail

> **Status:** ⬜ **DESIGN ONLY.** Nothing described below is implemented yet
> beyond §2.1 (which shipped on 21 Oct 2026 with advance payments). This file
> records what an auditor needs, what the system already answers, and the
> decisions the owner has to make before any of it is built.
>
> **Last written:** 21 Oct 2026.
> **Read alongside:** `PAYMENT-ARCHITECTURE.md` (how money is recorded),
> `ONLINE-PAYMENT-AND-APPLICATION-FEE.md` (§2.6 the ₦500 charge, §9 part
> payments), `FEES-REPORTING-DESIGN.md` (the reports that already exist).

---

## 1. Why this document exists

Every school eventually has to answer one question from somebody who is not
staff: **"show me where this money went."** A proprietor, a visiting auditor, an
LGA inspector, a parent who is disputing a bill, or a bursar handing over to a
successor.

Today Edus can *print* the money — the Payment Register, the Daily Collections,
the Reconciliation page, the debtor list. What it cannot yet do is **prove** the
money: it does not lock a verified payment, it does not record who changed a
price after the fact, and it has no single "everything that happened to this
pupil's account" page.

This document decides what "prove" means in Edus, before any of it is coded.

---

## 2. What the system already answers

### 2.1 The payment trail (built, 21 Oct 2026)

| The auditor asks | Where the answer is |
|---|---|
| What was received, when, how much? | `payment` rows, one per receipt, `created_at` + `paid_at` |
| How did the money arrive? | `payment.method` — `CASH`, `TRANSFER`, `POS`, `CHEQUE`, `ONLINE`, `ADVANCE` |
| Who took it in? | `payment.confirmed_by` (the officer's login) and `confirmed_at` |
| Which bill did it settle? | `payment.invoice_id` |
| Is it verified or still claimed? | `payment.status` — `PENDING` / `CONFIRMED` / `DECLINED` / `CANCELLED` |
| Was it paid ahead? | `payment.advance_id` → `student_advance` — the credit, who recorded it, what it covers |
| **Why** was it paid that way? | `payment.note` — the free-text remark, printed on the register and on every draw-down |
| What did the school waive? | `invoice.discount_applied` + `discount_label`, kept separate from the total |
| What was refunded? | **Nowhere yet** — see §4.1 |
| What was voided, and by whom? | **Nowhere yet** — see §4.2 |

The advance module is the first part of the system where the remark was designed
in rather than bolted on: a credit is written once with `remark`, `covers_note`,
`method`, `received_on` and `created_by`, and **every invoice it pays inherits a
sentence** naming the advance, the date, the method and the amount. That sentence
is what lets a physical audit match one bank credit to four terms of receipts.

### 2.2 The reports that exist

`/finance/reports/payments` (register, CSV), `/finance/reports/collections`,
`/finance/reports/reconciliation`, `/finance/reports/debtors`,
`/finance/reports/discounts`, `/finance/reports/application-fees`, and the
Income & Expenditure statement. Each is a *view*, re-computed on every request —
which is right for operations and wrong for evidence (§4.4).

### 2.3 The gap, in one line

> Nothing in the system is **immutable**, nothing records **who edited what**,
> and there is no page an auditor can be handed and told "this is the account".

---

## 3. What an auditor actually needs

Written from the chair of a visiting auditor with a bank statement in one hand
and a term's fee schedule in the other.

1. **Completeness** — every naira in the bank statement should appear in the
   ledger, and nothing else should. Anything in the ledger that is *not* in the
   bank must be cash (and so must be traceable to a person and a handover).
2. **Occurrence** — a receipt is only real if it is backed by money. A
   `CONFIRMED` payment with no proof attachment and no bank reference is a
   question the auditor will ask.
3. **Accuracy** — the amount on the receipt equals the amount in the bank, and
   the fee charged equals the fee schedule for that class and term.
4. **Cut-off** — money received on 31 March belongs to March, even if the receipt
   was keyed on 2 April.
5. **Classification** — tuition is not the same as a levy; a discount is not the
   same as a shortfall; an advance is not the same as a payment for this term.
6. **Authorship** — who keyed it, who approved it, who changed it afterwards.
7. **Consistency across terms** — an arrear carried from last term still explains
   itself this term.

An audit section that only lists payments answers (1) badly and (2)–(7) not at
all.

---

## 4. What is missing, and what each gap costs

### 4.1 Refunds

There is no refund record anywhere. A family pays three years ahead and leaves in
year one; the school hands back cash; nothing is written down except by hand.

**Proposal:** a `payment_refund` table (or a negative `payment` row with
`type = REFUND`). Recommended: `payment_refund`, because a negative payment
breaks every `SUM(amount)` in the reports today.

Required fields: `original_payment_id`, `amount`, `reason`, `method`,
`refunded_on`, `approved_by`, `received_by` (the person who handed the cash over),
`note`. A refund must reference what it refunds — an unattached refund is how
money leaves a school unnoticed.

### 4.2 Voiding a verified payment

Today a wrong payment can be edited or deleted, and nothing remains. That is the
single biggest audit hole in the system.

**Proposal:** a payment that has been `CONFIRMED` may never be deleted or
re-valued. Instead: `POST /finance/payment/{id}/void` with a mandatory reason →
`status = CANCELLED`, `voided_at`, `voided_by`, `void_reason`. The invoice's
`paid_amount` is recomputed from the surviving `CONFIRMED` rows. The row stays on
every report with a "Cancelled" badge, because its absence would be the lie.

### 4.3 Who changed a bill

An invoice's `total_amount` can change after it is raised (re-pricing, a fee
schedule correction, `DiscountRepricer`). Nobody can tell afterwards that it
changed, or from what.

**Proposal:** a small `ledger_event` table — append-only, one row per change:
`entity` (`invoice`/`payment`/`advance`), `entity_id`, `event`
(`created`/`repriced`/`discounted`/`voided`/`settled`), `from_value`,
`to_value`, `actor`, `at`, `note`. Written from the few services that already
own each transition (`DiscountRepricer`, `AdvancePaymentService`,
`SettlementService`) rather than from entity listeners — an invisible listener is
the wrong place for the audit trail.

### 4.4 Tamper-evidence

Every report is recomputed on demand. If a row is edited in the database, the
report simply shows the edited value; nobody can tell.

**Proposal (cheap version, recommended):** a **Monthly Close** — on the last day
of a month the bursar presses "Close the month", and Edus writes one immutable
`financial_period` row holding: total received, total refunded, total waived,
count of receipts, first and last receipt number, and a SHA-256 hash of the
canonical CSV of that month's payments. Later differences are then provable
without keeping every version of every row. A printed page with a hash and a
signature line is something an auditor can hold.

**Proposal (expensive version):** hash-chain every `ledger_event` row to its
predecessor. Better, but it must be built once, before the ledger has history
worth protecting, and it makes every write slower.

### 4.5 A per-pupil statement that stands alone

There is an "Outstanding Fees / Statement" page, but it is a debtors view. An
auditor wants **the account**: every invoice raised, every payment received, every
advance drawn, every discount given, every refund, opening and closing balance —
one printable page per pupil, QR-verified.

**Proposal:** `FinanceStatementRenderer` on the existing `A4PdfRenderer`, with a
`fee-statement` verification code from `FeeReportVerificationService` (the kind
already exists) and a QR in the footer. Print it at any time; it is the document
handed to a parent in a dispute, and to an auditor per pupil.

### 4.6 A reconciliation that closes

`/finance/reports/reconciliation` shows what is unverified. It does not let the
bursar *state* a bank balance and prove the difference is made of named items.

**Proposal:** a `bank_reconciliation` row per school account per month: statement
opening/closing balance, ledger total, the list of reconciling items (each with a
reference), the person who reconciled, and the date. Once reconciled, that month
is locked for edits without a supervisor.

### 4.7 Who may see money

Already decided and should stay: **teachers never see amounts** — a form teacher
sees who has cleared and who has not (the `/my-class/fees` screen). Money is the
bursary's, admissions belong to the principal and the admission officer.

**Proposal:** an `ROLE_AUDITOR` — read-only across every finance screen and
report, no write anywhere, no ability to see other tenants' books, and its logins
listed on the System page so the proprietor can see who has an auditor's account.
An auditor's account should be time-boxed: created for a visit, disabled after.

---

## 5. What the screens would look like

Design intent only; nothing is built.

| Screen | Who | Contains |
|---|---|---|
| **Finance → Audit trail** | `ROLE_AUDITOR`, `ROLE_ADMIN`, `ROLE_BURSAR` | Filter by date, method, pupil, officer, status. Every row shows the remark. Export CSV + PDF. Read-only. |
| **Pupil account statement** | any finance role, and the parent (own child) | The whole account, as in §4.5, printable with QR. |
| **Monthly close** | `ROLE_BURSAR` to prepare, `ROLE_ADMIN` to sign off | The figures, the hash, the signature line, a list of anything unverified. |
| **Void / refund** | `ROLE_BURSAR` requests, `ROLE_ADMIN` approves | Reason is mandatory; the original row stays visible. |

**Mobile first, as always.** The audit trail on a phone is a list of receipts
with the remark under each — not a 12-column table. The table is the desktop
view.

---

## 6. Open questions for the owner

Numbered so they can be answered in a message.

1. **Refund vs credit.** When a family leaves with money still on an advance,
   should the remainder be *refunded* (money out, a refund record) or left as
   *credit* until they ask? — Recommendation: leave it as credit, and refund
   only on request, recorded as a refund.
2. **Who may void.** Should a bursar be able to void a confirmed payment alone, or
   must the principal approve every void? — Recommendation: approve. A void is
   how money disappears.
3. **Back-dating.** Should a receipt be allowed with a date in a closed month?
   — Recommendation: allowed, but flagged, and it reopens the month for approval.
4. **Does the school reconcile to a bank statement?** If the school only ever sees
   cash, §4.6 changes shape entirely.
5. **How long is history kept?** Five sessions? Forever? It decides whether the
   hash-chain (§4.4) is worth paying for.
6. **Do auditors need their own login?** — Recommendation: yes, read-only, and
   time-boxed.

---

## 7. Suggested order of work

Each step is useful on its own; none needs the next one to ship.

1. **Void, don't delete** (§4.2) — closes the biggest hole for the least work.
2. **Pupil account statement PDF** (§4.5) — reuses the renderer and the QR that
   already exist; instantly useful in a dispute.
3. **Refunds** (§4.1) — needed the moment a family leaves.
4. **`ledger_event`** (§4.3) — start writing it before there is history worth
   keeping.
5. **Monthly close + hash** (§4.4) — the tamper-evidence story.
6. **`ROLE_AUDITOR`** (§4.7) — small, and it makes the section real.
7. **Bank reconciliation** (§4.6) — only once question 4 is answered.

---

## 8. What this document does **not** change

- The **₦500 online charge and its per-school switch** stay as they are
  (`ONLINE-PAYMENT-AND-APPLICATION-FEE.md` §2.6). An audit does not make a
  payment channel free.
- **Discounts stay tuition-only.** A discount reduces what is billed; it never
  becomes a payment.
- **An advance stays a payment, never a discount.** Its draw-downs are real
  ledger rows with a remark, which is the whole point.
