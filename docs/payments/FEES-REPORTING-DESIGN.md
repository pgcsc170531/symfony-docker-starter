# Edus — School Fees: reporting, reconciliation & outstanding fees

> **Status:** draft for discussion — 19 Oct 2026
> **Scope:** the *school* side of money (the tenant portal). Landlord subscription billing
> is a separate subject (`docs/payments/SUBSCRIPTION-AND-TERM-REGISTRATION.md`).
> **Companion reading:** `docs/payments/PAYMENT-COLLECTION.md` (options),
> `docs/payments/PAYMENT-ARCHITECTURE.md` (accounts and settlement),
> `docs/testing/TENANT-ACCEPTANCE-TEST.md` §7 (the money walkthrough).

---

## 1. The problem, in one paragraph

Edus can already **take** money — cash at the terminal, bank-transfer slips with a proof
upload, online payment, the store POS — and it records every naira in `payment`. What a
bursar or proprietor cannot do is **read that money back as a report**: how much was
expected this term, how much came in, who has paid in full, who has paid part, who has
paid nothing, and how old the debt is. The only "report" today is a profit-and-loss
total and a flat list of every payment ever taken. That is a bookkeeping log, not a
reporting section.

This document proposes the reporting section, the exact definitions behind every number,
and the order to build it in. It is written to be argued with — §11 lists the decisions
that need an owner's answer before code is written.

---

## 2. What exists today (verified in the code)

| Screen | Route | What it actually does | Why it is not a fees report |
|---|---|---|---|
| Payment History | `app_tenant_payment_history` → `/finance/payment/history` | `PaymentRepository`-less `findBy([], ['createdAt' => 'DESC'])` — **every** payment, no pagination | No date range, no class, no method, no totals, no export. A term with 2,000 payments is one endless page |
| Income & Expenditure | `app_tenant_report_profit_loss` → `/finance/report/profit-loss` | Sums **CONFIRMED** payments by invoice type over a date range, minus expenses | One number per income type. No student, no class, no debt, no aging |
| Student directory | `app_tenant_student_index` | Has `?finance_status=debtor`, and the CSV export includes the academic/store debt split | Filter only. No amount owed column per term, no guardian contact, no print |
| Academic / Store invoices | `app_tenant_invoice_index`, `app_tenant_invoice_store` | Status and search filters per ledger | Invoice-centric, not collection-centric; no "expected vs collected" |
| Dashboard | `app_tenant_dashboard` | Collection and debt tiles | A headline, not a schedule |
| Payment terminal | `app_tenant_payment_index` | Search a student → invoice → take payment | Operational, correct place to *take* money — no reporting |
| Expenses | `app_tenant_expense_index` → `/finance/expenses` | Flat list: title, category, date, amount | No payee, method, receipt or approval; no category totals or period report |
| Application fees | `application.fee_*` columns | Stores `fee_amount`, `fee_status`, `fee_channel`, `fee_reference`, `fee_paid_at` | **No confirmation step:** `FEE_PAID` is never set, `fee_payment_id` never populated, no queue for proofs |
| Income statement | — | Only the P&L total above | No expense categories, no prior term, no accrual-vs-cash view, no board PDF |

**The gap in one line:** there is no view that joins **what was billed** to **what was
collected** to **who still owes**, scoped by term and class — and no statement that puts
that income next to categorised expenses.

---

## 3. Vocabulary — define the numbers before drawing the screens

Two reports that disagree destroy trust faster than a missing report. These definitions
must live in one service (`FeeReportService`) and be used by every screen, CSV and PDF.

| Term | Meaning | Formula |
|---|---|---|
| **Billed (gross)** | The full value of the academic invoice lines | `SUM(invoice_item.amount)` |
| **Discount** | Scholarship / staff-child reduction recorded on the invoice | `invoice.discount_applied` |
| **Net billable** | What the school is actually entitled to collect | `billed − discount` |
| **Collected** | Money actually received and confirmed | `SUM(payment.amount) WHERE status = 'CONFIRMED'` |
| **Unverified** | A parent's claimed transfer that staff have not confirmed | `SUM(payment.amount) WHERE status = 'PENDING'` |
| **Outstanding** | Still owed on the scope | `max(net billable − collected, 0)` |
| **Credit / overpaid** | Money received above the net billable | `max(collected − net billable, 0)` |
| **Paid in full** | Invoice settled | `collected >= net billable − 0.01` |
| **Part-payer** | Something paid, not settled | `0 < collected < net billable − 0.01` |
| **No payment** | Nothing confirmed | `collected = 0` |
| **Owing ("defaulter" internally)** | Outstanding > 0 on the scope | — |
| **Cleared** | Outstanding = 0 | — |
| **Application fee** | The form fee paid before a child becomes a pupil | `application.fee_amount` where `fee_status = 'paid'` |
| **Income statement** | Income by source less expenses by category for a period | R12 — two bases, §5 |

### 3.1 The rules that keep the numbers honest

1. **Only `CONFIRMED` money is collected.** `PENDING` is "claimed, not verified" and is
   shown in its own column — never added to collected totals. `DECLINED` and `CANCELLED`
   are ignored everywhere.
2. **Store invoices are excluded by default.** Reports default to `invoice.type = 'ACADEMIC'`,
   with an explicit toggle for store debt. Mixing a parent's textbook purchase into the
   fee default rate answers the wrong question.
3. **Walk-in store invoices have no student** (`invoice.student_id` nullable). They must
   never appear in a per-student report and must be counted separately in cash reports.
4. **A term is a scope, not a filter on `created_at`.** A parent paying in October for a
   September invoice still belongs to the September/First-Term report. Reports scope on
   `invoice.term_id`, never on the payment date, except the **cash book** (R1) which is
   explicitly a date-range cash view.
5. **Never say "defaulter" to a parent.** Internal reports may use the word; parent-facing
   statements say **"Outstanding fees"** and state the amount and the deadline.
6. **Zero is a claim.** A class with no invoices yet shows *"No invoices generated for
   this term"*, never `₦0.00 collected`.

### 3.2 ⚠️ Prerequisite — invoice arithmetic is currently inconsistent

Before any expected-vs-collected report can be trusted, invoice totals must mean one
thing. This was the state **before Phase 0** (kept here so the fix can be audited):

| Path | `total_amount` | `discount_applied` | Net billable |
|---|---|---|---|
| `InvoiceController` (academic ledger) | gross **minus** discount | set | `total_amount` |
| `AdmissionService` / `EnrollmentController` | **gross** | set | `total_amount − discount_applied` |
| `PaymentController::generateInvoice` (terminal) | gross | **not calculated at all** | over-bills a discounted student |

**Implemented (Phase 0, 19 Oct 2026):** `total_amount` is **net of `discount_applied`** —
the invoice screen, the payment terminal and the parent portal all compare payments
against it. Gross is `total_amount + discount_applied` (`Invoice::getGrossBilled()`).
`InvoiceController`, `AdmissionService`, `EnrollmentController`, `PaymentController` and
`QuickAdmissionController` now all use `DiscountCalculator` and store the net amount.
The audit found **zero discounted invoices** in the dev tenants, so no backfill was
needed; re-run the audit query before deploying anywhere with real discount data.

Also add a **service** (`FeeReportService`) and real repositories
(`PaymentRepository`, `InvoiceRepository`) — aggregate queries do not belong in
controllers, and `PaymentController::history()` currently has none.

### 3.2a ⚠️ Prerequisite — the application fee has no settlement step

The application fee is stored on `application` (`fee_amount`, `fee_status`,
`fee_channel`, `fee_reference`, `fee_paid_at`, `fee_payment_id`), **not** in `payment`.
Only three transitions exist in the code today:

| Transition | Where | Result |
|---|---|---|
| `unpaid` → `awaiting_confirmation` | `ParentApplicationController::feeProof()` | The parent uploaded a transfer proof; **nobody can confirm it** |
| `unpaid` → `waived` | `ParentApplicationController::submit()` when the fee is ₦0 | OK |
| `awaiting_confirmation` → `paid` | **does not exist** | `FEE_PAID` is declared but never set; `fee_payment_id` is never populated |

The comment in `ParentApplicationController` says *"the bursar confirms it in Phase 4"*.
That screen was never built. Consequences for reporting:

1. R10 cannot show "collected" until a bursar confirmation exists.
2. If a `Payment` row is later created for a fee without linking `fee_payment_id`, the
   income statement will either miss the money or count it twice.
3. The bursar currently has no queue for applications waiting on a fee proof.

**Phase 0 therefore includes:** an **Application fees** queue (confirm / reject a proof,
with the same CSRF and permission rules as payment verification), setting `fee_status`,
`fee_paid_at` and `fee_channel`; and an explicit rule that an application fee appears in
**one** ledger only. `fee_payment_id` stays null: `payment` requires an invoice, and an
application fee has no invoice until the child is admitted. The `application.fee_*`
columns **are** its ledger.

> ✅ **Built 19 Oct 2026.** `ApplicationReviewController` now has
> `fee/confirm`, `fee/reject` and `fee/waive`; confirming moves an
> `awaiting_payment` application into the review queue. While building it we found
> `fee_status` was `VARCHAR(20)` but `awaiting_confirmation` is 21 characters — the
> parent's offline proof upload would fail with *"Data too long"*. The column is now
> `VARCHAR(30)` on every tenant.

---

## 4. The report suite at a glance

| # | Report | Question it answers | Audience | Priority |
|---|---|---|---|---|
| **R1** | Daily collections (cashier day book) | What did we collect today, by method and by cashier? | Bursar, Proprietor | **P0** |
| **R2** | Fee collection by class (term) | Expected vs collected vs outstanding per class | Proprietor, Bursar | **P0** |
| **R3** | Outstanding fees (debtors **by guardian**) | Which families owe, how much in total, how old, how to reach them | Bursar, Proprietor | **P0** |
| **R4** | Student fee statement | This child's whole ledger, printable for the parent | Bursar, Parent (own child) | **P0** |
| **R5** | Payment register | Every confirmed payment, filtered and exportable | Bursar, Auditor | **P1** |
| **R6** | Class fee-status matrix | One grid: every pupil × PAID / PARTIAL / UNPAID | Form teacher, Bursar | **P1** |
| **R7** | Discounts & scholarships | What we waived, and to whom | Proprietor | **P2** |
| **R8** | Reconciliation by method | Cash vs transfer vs POS vs cheque; verified vs pending | Bursar | **P1** |
| **R9** | Term summary one-pager | The board/inspector view of the term | Proprietor | **P2** |
| **R10** | Application fees | What was collected, waived and still owed on `/apply` | Proprietor, Bursar | **P1** |
| **R11** | Expense register | Every naira that left the school, by category, date and payee | Bursar, Proprietor | **P1** |
| **R12** | Income statement (term / period) | Income by source, expenses by category, and the surplus | Proprietor, Board | **P1** |

Navigation: one new sidebar section **Finance → Reports** with R1–R3, R5–R8,
R10–R12; R4 is reached from a student (and the parent portal); R9 is a PDF action on
R2.

**Built 19 Oct 2026 (Phase 1):** `/finance/reports` (overview), `/finance/reports/collections`
(R1), `/finance/reports/classes` (R2), `/finance/reports/debtors` (R3) and
`/finance/reports/statement/{id}` (R4) — all permission-gated to bursar/admin, all tested
at 360px. The statement printed through the browser's A4 dialog until Phase 5 gave it a
proper issued PDF (below).

**Built 19 Oct 2026 (Phase 2):** `/finance/reports/payments` + `.csv` (R5),
`/finance/reports/reconciliation` (R8), `/finance/reports/matrix` (R6) and
`/finance/reports/expenses` + `.csv` (R11). The old `/finance/payment/history` URL now
redirects to R5.

**Built 19 Oct 2026 (Phase 3):** `/finance/reports/income` (R12, accrual + cash with a
previous-term column), `/finance/reports/discounts` (R7),
`/finance/reports/application-fees` (R10) and
`/finance/reports/summary.pdf` (R9, A4 through `A4PdfRenderer`). The PDF carries a QR
that resolves to `/verify/fee-report/{code}` — a public page that confirms issuer, term
and issue date and shows **no amounts**. `?format=html` renders the identical context.
The `fee_report_verification` table is created by `app:tenant:update-schema`.

**Built 19 Oct 2026 (Phase 4):** `app:fees:send-term-summaries` emails the R9 PDF once
per term to the school's `ROLE_ADMIN` users (recorded on `term.summary_sent_at`), and
`app:fees:send-aging-reminders` emails the weekly position. The debtors screen now
exports a **reminder CSV** (guardian, phone, children, amount, prefilled message) —
nothing is ever sent to a parent automatically. Cron setup:
`docs/operations/FEE-REPORT-EMAILS.md`.

**Built 19 Oct 2026 (Phase 5):** the statement became an **issued document**.
`StudentStatementPdfRenderer` + `templates/tenant/report/fees/statement.pdf.twig` render
R4 as an A4 PDF with a masthead, running balance and a QR verification footer, reachable
two ways:

- **Parent:** `GET /parent/student/{id}/statement.pdf` (button on
  `/parent/student/{id}/finance`), own children only, default period *all terms*, store
  purchases included (`?store=0` to exclude).
- **Bursar:** `GET /finance/reports/statement/{id}.pdf` (“Official PDF” on the statement
  screen), honouring the same term/store filters as the screen.

Verification reference is `STMT-{pupil}-{term|ALL}` with `kind = fee-statement`, so a
reprint keeps its code and the public page says *Statement of account* instead of *Term
fee summary* — still no amounts. `?format=html` renders the identical context.

> **Also fixed with Phase 5:** `/finance/invoice/{id}` was reachable by any signed-in
> user, so a parent could read another family's invoice by changing the id. It now
> requires bursar/admin/store, or that the invoice's pupil belongs to the caller.

> ⚠️ **Data normalisation run with Phase 2.** 360 invoices in the demo/gak tenants
> were still tagged `type = 'TUITION'` by the old bulk importer, which made them
> invisible to every academic report. They were updated to `ACADEMIC` across all tenant
> databases. The importer now writes `ACADEMIC` directly (Phase 0).

> **Decided 19 Oct 2026:** no cashier session (a daily report is enough); part payments
> are tracked at **invoice level** (no per-fee-item allocation); debtors are grouped
> **by guardian**; **application fees are in scope**; the term summary is **sent to the
> proprietor at the end of each term**. See §11.

---

## 5. Report specifications

### R1 — Daily collections (cashier's day book) — P0

> *A bursar must be able to close the till and prove the cash box.*

- **Filters:** date (default: today), method, cashier (`confirmed_by`), include PENDING.
- **Columns:** time · reference · student/admission no. · class · invoice no. · type
  (Academic/Store) · method · amount · confirmed by · receipt link.
- **Totals:** by method, by cashier, grand total; **PENDING** shown in a separate block
  labelled *"Claimed, not verified"* with one-click verify.
- **Actions:** print day book (A4), export CSV.
- **Rule:** uses `payment.confirmed_at` (fall back to `created_at` for legacy rows), not
  `invoice.created_at`.
- **Empty state:** "No payments recorded on this date." (not `₦0`).

### R2 — Fee collection by class (term) — P0

> *How is this term going, class by class?*

- **Filters:** term (default active), session, class, include-store toggle.
- **Columns per class:** pupils enrolled · invoiced (net billable) · collected · outstanding
  · collection rate % · pupils fully paid · part-payers · no payment.
- **Totals row** across the selected scope, plus a school-wide summary card
  (Expected / Collected / Outstanding / Rate).
- **Drill-down:** click a class → R3 filtered to that class, or R6 grid.
- **Print/PDF:** R9 one-pager built from the same context.
- **Definition guard:** if an enrolled pupil has **no invoice** for the term, show them in
  a separate *"Not billed"* count — never silently treat them as owing or paid. This is
  how a school discovers it forgot to bill a class.

### R3 — Outstanding fees / debtors, grouped **by guardian** — P0

> *The list a bursar actually works from on a Monday morning — one phone call per family.*

- **Filters:** term (default active), class, session, minimum amount, aging band,
  "has phone" / "no phone", paid-since (e.g. paid something in the last 30 days).
- **One row per guardian (family), not per pupil:** guardian name · phone · children
  (names + classes, shown on a second line) · invoiced · paid · **outstanding** ·
  last payment date · oldest debt · days overdue.
- **Expandable child rows:** tapping a family reveals each child's own invoiced / paid /
  outstanding and a link to that child's statement (R4).
- **Grouping rule:** group by `guardian_id`. A pupil with no guardian is listed in an
  **"No guardian assigned"** group with a warning — never silently dropped or split from
  a family.
- **Aging:** `0–30`, `31–60`, `61–90`, `90+` days since invoice issue date, with an
  aging summary above the table.
- **Sorting:** largest family balance first (default), oldest debt, alphabetical.
- **Actions per row:**
  - open the family's children and each child's statement (R4)
  - record a payment (terminal, pre-filtered to the child's invoice)
  - copy the guardian's phone / open WhatsApp with a **prefilled, editable** reminder
    that names the children and the total
  - print/export the list
- **Bulk actions:** export CSV; "copy phone numbers for SMS" (one number per family).
- **Never include:** families with outstanding = 0, walk-ins, store-only balances
  (unless the store toggle is on).
- **Message template (draft):** *"Dear [Guardian], our records show ₦[total] outstanding
  for [Child 1] ([Class]) and [Child 2] ([Class]) for [Term]. Kindly settle by [date].
  — [School]"* — the bursar edits before sending; nothing automatic.

### R4 — Student fee statement — P0

> *One child, one page, no argument.*

- **Reached from:** student profile, payment terminal, R3 row.
- **Content:** school header, pupil + guardian, term/session, class; a chronological
  ledger (invoice issued, each confirmed payment with method/reference, each discount);
  running balance; **Outstanding** or **Paid in full** badge.
- **Term selector**; default = active term, with an "all terms" option for a full history.
- **Print:** A4 portrait through the shared PDF pipeline (`A4PdfRenderer` + QR
  verification footer — see `.github/skills/pdf-documents`). Screen and PDF must share
  one context array.
- **Parent view:** the same content inside the parent portal, restricted to that parent's
  own children (`app_parent_finance_view` already guards ownership).

### R5 — Payment register — P1 (replaces `Payment History`)

- **Filters:** date range (default: this term), class, student search, method, status,
  cashier, invoice type.
- **Pagination:** server-side, 50/page.
- **Totals:** confirmed total, pending total, split by method.
- **Columns:** date · pupil · class · invoice · type · method · reference · amount ·
  status · confirmed by · receipt/proof.
- **Export:** CSV (filtered, not the whole table) and print.

### R6 — Class fee-status matrix — P1

- **Shape:** pupils down the side, fee items (tuition, PTA, books…) across the top, cells
  PAID / PARTIAL / UNPAID.
- **Why:** a form teacher can answer a parent in seconds, and the bursar can see which
  *fee item* is the one everyone ignores.
- **Mobile:** the grid is a table → wrap in `overflow-x-auto` with `min-w-[640px]`; on a
  phone offer a per-pupil card list as the primary view.

### R7 — Discounts & scholarships — P2

- Every `student_discount` with its type, mode (percentage/fixed), value, the amount
  actually waived, and the term it affected.
- Totals: total waived this term, count of beneficiaries, distribution by discount type.
- Flags a discount assigned to a pupil with no invoice in the term (likely a mistake).

### R8 — Reconciliation by method — P1

- Rows: method (CASH, TRANSFER, POS, CHEQUE, online) × status (CONFIRMED, PENDING,
  DECLINED, CANCELLED).
- Columns: count, amount, % of confirmed total.
- A *"pending older than 48 hours"* callout — unverified transfers are the most common
  source of a parent argument.
- Links into R5 filtered by that method/status.

### R9 — Term summary one-pager — P2

- A4 portrait PDF: enrolments, billed, collected, outstanding, collection rate, top five
  families by balance, method split, discounts waived, and a signature block.
- Generated from R2's context through `A4PdfRenderer`; no second calculation.
- **Automated delivery (decided):** at term end the same pack is emailed to the
  proprietor (and optionally the principal) as a scheduled job — see Phase 4. It is a
  recorded artefact, not a live page that changes after the term closes.

### R10 — Application fees — P1

> *The money taken before a child is a student. It must not vanish between admissions and finance.*

- **Source of truth today:** `application.fee_amount`, `fee_status`, `fee_channel`,
  `fee_reference`, `fee_paid_at`. Application payments are **not** yet rows in `payment`.
- **Columns:** reference (`APP/2026/0001`) · applicant · guardian · phone · fee · status
  (unpaid / awaiting confirmation / paid / waived) · channel (online / offline / cash) ·
  reference · paid date · days outstanding.
- **Totals:** collected, waived, awaiting confirmation, still unpaid; split by channel.
- **Filters:** term/session (by application date), status, channel, class applied for.
- **Actions:** confirm an offline proof (this is the missing step — see §3.2a), open the
  application, export CSV.
- **Rule:** an application fee is **income once**, on the date it is confirmed. If a
  school later credits it against tuition, that credit is a separate line — never count
  the same naira as both application income and fee income.

### R11 — Expense register — P1

> *The other half of the income statement. Today expenses are a flat list with a category and a total.*

- **Filters:** date range / term, category, minimum amount, search in title/note.
- **Columns:** date · category · title · note · amount · payee · method · receipt ·
  recorded by.
- **Totals:** by category, by month, grand total; count of entries.
- **Actions:** add/edit/delete, CSV export, A4 print (for the bursar's file).
- **Required fields to add to `Expense` (migration):** `payee` (vendor/staff), `method`
  (CASH/TRANSFER/POS/CHEQUE), `receipt_filename`, `recorded_by`, and an optional
  `approved_by` / `approved_at` for schools that want a second signature.

### R12 — Income statement (term or date range) — P1

> *The proprietor's real question: did the school make money this term, and where did it go?*

Two labelled columns, because they answer different questions:

| Line | Accrual (what the term earned) | Cash (what actually arrived) |
|---|---|---|
| Academic fees (net of discounts) | net billable | confirmed payments |
| Store sales | store invoices issued | confirmed store payments |
| Application fees | confirmed + waived fees | confirmed fees only (waived = 0) |
| **Total income** | sum | sum |
| Expenses (by category) | expenses dated in the period | same |
| **Net surplus / (deficit)** | income − expenses | income − expenses |

- Also shows: collection rate, outstanding fees, discounts waived, and the **previous
  term** in a comparison column.
- **Do not label the accrual net as cash.** A school can be "profitable" on paper and
  unable to pay salaries; the cash column is the one that pays them.
- Expense categories are the statement's rows — an uncategorised expense is a defect,
  not an "Other" bucket.
- A4 PDF through `A4PdfRenderer`; CSV export; both share one context array.

---

## 6. Data model and query rules

No migration is required for R1–R9. Those reports read only from `invoice`,
`invoice_item`, `payment`, `enrollment`, `classroom`, `term`, `session`, `student` and
`guardian`.

### 6.0 Invoice numbers — one series (19 Oct 2026)

`INV-{session start year}-{0001}`, produced by
`App\Service\Finance\InvoiceNumberGenerator` and by nothing else.

Before this, five screens minted five formats — `INV-<hex>` from the entity
constructor, `INV-2026-<hex>` from enrolment and admission, `INV-AUTO-<hex>` from
bulk import — so the number a parent read back over the phone did not look like
the number on the bursar's screen, and 13 hex characters wrapped onto two lines
on a phone.

Rules:

- **Every** creation path calls `next(yearFor($session))`: admission, quick
  enrolment, enrolment promotion, the payment terminal, the invoice generator and
  both store checkouts. `Invoice::__construct` deliberately sets no number.
- The year comes from the session (`2026/2027` → 2026), not from the calendar, so
  next session's invoices raised in December are not numbered under this year.
- The generator keeps an **in-process high-water mark**. A bulk import creates
  hundreds of invoices before flushing, so reading `MAX()` on each call would
  hand out the same number repeatedly and the unique index would abort the
  import half way.
- Legacy numbers (`INV-2026-<hex>`) share the prefix but are never read as a
  sequence, and existing rows are **not** renumbered automatically —
  `app:invoices:renumber` does that explicitly (`--apply`, per school, dry-run by
  default), because renumbering changes what an already-issued statement shows.

R10–R12 add three things that do need a migration or a new flow: the application-fee
confirmation step (§3.2a), the extra `Expense` fields (§5 / R11), and one ledger rule so
an application fee is counted exactly once.

### 6.1 Net billable per invoice

```sql
SELECT i.id,
       i.total_amount                                   AS net_billable,
       COALESCE(i.discount_applied, 0)                  AS discount,
       i.total_amount + COALESCE(i.discount_applied, 0) AS gross_billed
FROM invoice i
WHERE i.type = 'ACADEMIC'
  AND i.term_id = :term_id
  AND i.student_id IS NOT NULL;
```

`total_amount` is NET; gross is derived by adding the discount back. Do not subtract
`discount_applied` a second time — that was the original bug.

### 6.2 Collected per invoice

```sql
SELECT p.invoice_id,
       SUM(p.amount) AS collected
FROM payment p
WHERE p.status = 'CONFIRMED'
GROUP BY p.invoice_id;
```

### 6.3 The debtors query (R3), one row per **guardian**

```sql
SELECT g.id,
       g.full_name  AS guardian,
       g.phone_number,
       GROUP_CONCAT(DISTINCT CONCAT(s.first_name, ' ', s.last_name) ORDER BY s.first_name SEPARATOR ', ') AS children,
       GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR ', ') AS classes,
       SUM(i.total_amount) AS invoiced,
       COALESCE(SUM(p.paid), 0)                              AS paid,
       SUM(i.total_amount) - COALESCE(SUM(p.paid), 0)        AS outstanding,
       MIN(i.created_at) AS oldest_invoice,
       MAX(p.last_paid_at) AS last_payment
FROM invoice i
JOIN student s         ON s.id = i.student_id
LEFT JOIN enrollment e ON e.student_id = s.id AND e.session_id = i.session_id
LEFT JOIN classroom c  ON c.id = e.classroom_id
LEFT JOIN guardian g   ON g.id = s.guardian_id
LEFT JOIN (
    SELECT invoice_id, SUM(amount) AS paid, MAX(confirmed_at) AS last_paid_at
    FROM payment WHERE status = 'CONFIRMED' GROUP BY invoice_id
) p ON p.invoice_id = i.id
WHERE i.type = 'ACADEMIC'
  AND i.term_id = :term_id
GROUP BY g.id, g.full_name, g.phone_number
HAVING outstanding > 0
ORDER BY outstanding DESC;
```

Pupils with `guardian_id IS NULL` are returned by the same query as a group labelled
**"No guardian assigned"** (use a sentinel id) and must be visible, not filtered out.
The expandable child rows use the per-pupil query from §6.3a.

### 6.3a Per-child detail (the expanded row)

Same shape as the per-pupil query: one row per pupil with invoiced, paid, outstanding,
oldest invoice and last payment. Filter by `g.id`.

> `GROUP_CONCAT` is MySQL-specific; if the report service is ever run on another
> platform, aggregate the child names in PHP from a second query instead.

The exact column names of `student`/`guardian` must be checked against the entity
mappings before implementation — this is a design sketch, not a verified query.

### 6.4 "Not billed" pupils

```sql
SELECT s.id, s.admission_number, CONCAT(s.first_name, ' ', s.last_name)
FROM student s
JOIN enrollment e ON e.student_id = s.id AND e.session_id = :session_id
LEFT JOIN invoice i ON i.student_id = s.id AND i.term_id = :term_id AND i.type = 'ACADEMIC'
WHERE i.id IS NULL;
```

### 6.5 Performance

- `payment(invoice_id, status)` and `invoice(term_id, type, student_id)` are the hot
  paths; add indexes if the query plan shows a full scan on a 5,000-payment term.
- All aggregate queries must accept a **term id** and a **class id** — never load all
  invoices into PHP and sum there.
- One query per report, one service method per query; no N+1 through
  `invoice.getPayments()` inside a loop.

### 6.6 Expenses by category (R11 / R12)

```sql
SELECT ec.name AS category,
       COUNT(e.id) AS entries,
       SUM(e.amount) AS total
FROM expense e
JOIN expense_category ec ON ec.id = e.category_id
WHERE e.expense_date BETWEEN :start AND :end
GROUP BY ec.id, ec.name
ORDER BY total DESC;
```

### 6.7 Income statement inputs (R12)

Four independent aggregates, one service method each:

| Line | Query |
|---|---|
| Academic — accrued | `SUM(total_amount)` on `invoice` where `type='ACADEMIC'` and term |
| Academic — collected | `SUM(amount)` on `payment` joined to academic invoices, `status='CONFIRMED'` |
| Store — accrued / collected | same two, `type='STORE'` |
| Application fees | `SUM(fee_amount)` on `application` where `fee_status='paid'` (and separately `waived`) |
| Expenses | §6.6 |

The statement never computes a figure in Twig; it renders what the service returns.

### 6.8 Application fees (R10)

```sql
SELECT a.fee_status,
       a.fee_channel,
       COUNT(*) AS applications,
       SUM(COALESCE(a.fee_amount, 0)) AS amount
FROM application a
WHERE a.created_at BETWEEN :start AND :end
GROUP BY a.fee_status, a.fee_channel;
```

> ⚠️ **The confirmation step does not exist yet.** `Application::FEE_PAID` is declared
> but never set anywhere in the codebase, and `fee_payment_id` is never populated. The
> only transitions today are `unpaid → awaiting_confirmation` (offline proof) and
> `unpaid → waived`. R10 cannot report "collected" until Phase 0 adds the bursar
> confirmation and records the money in a single ledger.

---

## 7. UX rules (these reports are used on a phone)

The bursar checks the day book on a phone at the end of the day, not at a desk.

1. **Filters collapse on mobile.** A single "Filters" disclosure; the chosen filters are
   summarised in one line (`First Term · JSS 1A · all methods`).
2. **Summary before table.** Four numbers (Expected, Collected, Outstanding, Rate) in a
   two-column card grid — the answer first, the detail below.
3. **Tables are not the mobile view.** On `< sm`, a payment or debtor row becomes a card:
   pupil + class on line one, amount right-aligned, status badge, tap → statement.
   Above `sm`, the real table appears.
4. **Wide grids** (R6) go in `overflow-x-auto` with `min-w-[640px]`; the phone gets the
   card list instead.
5. **Touch targets ≥ 44px**, full-width primary actions (`Export`, `Print`), no input
   under 16px.
6. **Status is never colour alone** — `.badge-paid` / `.badge-pending` / `.badge-failed`
   always carry the word.
7. **Money uses `.money`/`.tabular`** so columns line up; `₦` and thousands separators
   come from one Twig filter, never formatted by hand in two places.
8. **Print is not a screenshot.** R1, R3, R4, R10, R11 and R12 print through the A4 PDF
   pipeline with the school masthead; the screen template is separate from the print
   template.

---

## 8. Permissions

| Report | `ROLE_BURSAR` | `ROLE_ADMIN` | `ROLE_TEACHER` | `ROLE_PARENT` |
|---|---|---|---|---|
| R1 Day book | ✅ | ✅ | — | — |
| R2 Class collection | ✅ | ✅ | — | — |
| R3 Debtors | ✅ | ✅ | — | — |
| R4 Statement | ✅ (any pupil) | ✅ | — | ✅ **own children only** |
| R5 Register | ✅ | ✅ | — | — |
| R6 Matrix | ✅ | ✅ | ✅ own class only | — |
| R7 Discounts | ✅ | ✅ | — | — |
| R8 Reconciliation | ✅ | ✅ | — | — |
| R9 Term summary | ✅ | ✅ | — | — |
| R10 Application fees | ✅ | ✅ | — | — |
| R11 Expense register | ✅ | ✅ | — | — |
| R12 Income statement | ✅ | ✅ | — | — |

Add explicit `#[IsGranted]` on every report controller; the tenant `access_control` rules
for `/finance` are **not** currently restored (see repo notes), so the attribute is the
only reliable gate.

---

## 9. Exports and printing

| Output | Format | Rules |
|---|---|---|
| Any report | **CSV** | Only the filtered rows; header row exactly matches the on-screen columns; no merged headings |
| R1 Day book | **A4 PDF** | Cashier, date, method totals, signature line |
| R3 Debtors | **A4 PDF** | School letterhead, aging summary, guardian phone column |
| R4 Statement | **A4 PDF** | QR verification footer, one pupil, running balance; parent downloads their own child's, bursar gets the same document |
| R9 Summary | **A4 PDF** | Board/inspector copy, QR verification footer |
| R10 Application fees | **CSV + A4 PDF** | One line per application; totals by status and channel |
| R11 Expense register | **CSV + A4 PDF** | Category subtotals; a payee/receipt column so the file can be audited |
| R12 Income statement | **A4 PDF** | Both bases shown; previous-term column; QR verification footer |

PDFs follow `.github/skills/pdf-documents` exactly: `A4PdfRenderer`, A4 portrait, plain
CSS tables, `?format=html` renders the same context, verification code + QR in the
footer. Do not build a second Dompdf path for reports.

---

## 10. Phased delivery

| Phase | Ships | Depends on | Exit criterion |
|---|---|---|---|
| **0. Correctness** | ✅ D1 net invoice arithmetic; ✅ payment/invoice constants; ✅ `FeeReportService` + repositories; ✅ application-fee confirmation queue; ✅ `Expense` fields (payee, method, receipt, recorded_by, approval) | — | One definition of net billable; one ledger for application fees; a discounted pupil shows the same balance on every screen |
| **1. Core reports** | ✅ R4 Statement, ✅ R3 Debtors **by guardian**, ✅ R2 By class, ✅ R1 Day book | Phase 0 | A bursar can close a day, chase one family for all its children, and hand a parent a statement without Excel |
| **2. Working tools** | ✅ R5 Register (replaces history), ✅ R6 Matrix, ✅ R8 Reconciliation, ✅ R11 Expense register | Phase 1 | Filters + pagination + CSV on every list; no "all rows" page |
| **3. Statement & governance** | ✅ **R12 Income statement**, ✅ R7 Discounts, ✅ R9 Term summary PDF, ✅ R10 Application fees | Phase 2 | Board pack and income statement use the same numbers as R2 and R11 |
| **4. Automation** | ✅ **End-of-term pack emailed to the proprietor**; ✅ reminder drafts from R3 (CSV); ✅ weekly aging email | Phase 3 + SMS wallet + scheduler | The proprietor receives the term statement without asking; a reminder is drafted, never auto-sent |
| **5. Issued statement** | ✅ R4 as an A4 PDF (`StudentStatementPdfRenderer`) for both bursar and parent; ✅ invoice id privacy guard | Phase 1 | A parent can print or forward their child's statement and a third party can verify it without seeing another family's data |

---

## 11. Decisions for the owner

### 11.1 Taken — 19 Oct 2026

| # | Decision | Owner's answer | Effect on the design |
|---|---|---|---|
| **Q1** | Cashier session (open/close till) | **No** | R1 is a daily report only; no shift table, no counted-cash close |
| **Q2** | Part-payment allocation | **Invoice level is enough** | One balance per invoice; R6 only shows PAID / PARTIAL / UNPAID, never per-fee-item splits |
| **Q3** | Debtors list shape | **By guardian** | R3 groups a family's children into one row with one total and one phone call |
| **Q4** | Application fees | **In scope** | New R10, plus the missing confirmation step in Phase 0 |
| **Q5** | Term summary delivery | **Send to the proprietor at term end** | R9 becomes a scheduled artefact (Phase 4), not just an on-demand PDF |

### 11.2 Still open

| # | Question | Options | Recommendation |
|---|---|---|---|
| **D1** | What does `invoice.total_amount` mean? | (a) net of discount (b) gross, with discount separate | **(a) net — implemented in Phase 0**; gross is derived as `total_amount + discount_applied` |
| **D2** | Which term does a late payment belong to? | (a) the term of the invoice (b) the date the money arrived | **(a)** for R2/R3/R4; **(b)** only for the R1 cash book |
| **D3** | Is store debt part of "outstanding fees"? | (a) one combined figure (b) **separate by default, toggle to combine** | **(b)** — a textbook balance must not inflate the fee default rate |
| **D4** | Who may see the debtors list? | (a) bursar only (b) **bursar + admin** (c) all staff | **(b)** — form teachers get R6 for their own class instead |
| **D5** | Reminders | (a) automatic (b) **drafted, human sends** | **(b)** — a wrongly auto-sent "you owe" message is a reputational incident |
| **D6** | Aging basis | (a) invoice issue date (b) term start date | **(a)** — the promise was made when the invoice was issued |
| **D7** | Where does "Reports" live? | (a) new sidebar group (b) inside Finance submenu | **(a)** — one **Reports** group under Finance, one tap from the dashboard |
| **D8** | Rounding | (a) whole naira (b) **two decimals** | **(b)** — the ledger holds kobo; display `.money` so totals reconcile exactly |

---

## 12. Acceptance checks (write these before the screens)

1. A pupil with **one ₦50,000 invoice, ₦20,000 cash and ₦5,000 pending** reports
   **invoiced 50,000 · collected 20,000 · pending 5,000 · outstanding 30,000**.
2. A pupil with **full payment + ₦1,000 overpayment** reports **outstanding 0** and
   **credit 1,000** — never a negative debt.
3. A pupil with a **₦10,000 scholarship on a ₦50,000 bill** is invoiced for **₦40,000**
   in R2 and owes **₦40,000** in R3 — not ₦50,000 and not ₦30,000.
4. A **walk-in store sale** never appears in R3 or R4.
5. A pupil enrolled but **not invoiced** appears in the *Not billed* count in R2 and in a
   dedicated empty state, never as owing ₦0.
6. Changing the term filter changes **every** summary card and table total, and the CSV
   matches the screen row-for-row.
7. A parent opening R4 sees **only their own children**; a forged id returns 403.
8. At **360px**: no horizontal overflow, summary cards stack two-up, rows become cards,
   every action is ≥44px.
9. A printed statement is **A4 portrait** with a verification QR, and `?format=html`
   shows the identical figures.
10. P&L (`/finance/report/profit-loss`) and R8 agree on confirmed income for the same
    date range, to the kobo.
11. **Guardian grouping:** two siblings (₦40,000 + ₦30,000 outstanding) appear as **one**
    family row owing ₦70,000, and expanding it shows both children; a family with one
    cleared child and one owing child still appears, with only the owing child counted.
12. **Application fees:** an application with `fee_status = 'paid'` appears once in R10
    and once in R12 income; a waived fee appears as waived with ₦0 collected; an
    `awaiting_confirmation` fee appears in neither collected total.
13. **Expenses:** the expense register total by category equals the expense rows of R12
    for the same period; an expense dated outside the period is excluded from both.
14. **Income statement:** accrual total equals R2 net billable + store billed + application
    fees earned; cash total equals R5 confirmed total + confirmed application fees;
    expenses equal R11; both nets equal income − expenses.
15. **Term close:** when the active term is closed, the proprietor receives the R9 pack
    once (not twice), and the figures match the page at the moment of sending.

---

## 13. Open questions for discussion

1. **Income statement basis.** Show **both** accrual (what the term earned) and cash (what
   arrived) side by side — recommendation — or only cash? A proprietor needs cash;
   a board asks about receivables. Showing one invites the wrong argument.
2. **Application fee accounting.** When an admitted pupil's application fee is later
   credited against tuition, is that a refund, a discount, or neither? Recommendation:
   a separate credit line, never a negative income adjustment.
3. **Expense approval.** One bursar entering and approving expenses, or a threshold above
   which the proprietor must approve? Recommendation: optional approval field from day
   one, off by default for small schools.
4. **Term-pack privacy.** Should the emailed end-of-term summary name the top debtor
   families, or show only the total outstanding? Recommendation: totals in the email;
   names only in the attached PDF the proprietor opens deliberately.
5. **Reporting period.** Term, session, or calendar year as the default for R12? The
   school's board may not think in terms. Recommendation: default to **term**, with a
   session-to-date column always visible.
