# School-fees import for the bursary

**Status:** BUILT — Phase 1 and Phase 2 are implemented; Phase 3 reporting is
partly in place. Playwright-tested on the `familytest` tenant, 14 November 2026.
**Date:** 14 November 2026
**Owner:** Bursary unit, on behalf of a newly onboarded school.
**Scope:** import fees already collected by hand, for the current term, from a CSV
built on the blank class score sheet. It also closes the gap left by the student
import: it never asked whether a pupil was **new** or **returning**, so every
imported pupil was billed as a new intake.

## Implementation map

| Piece | Where |
|---|---|
| `Student.studentType` (`NEW` / `RETURNING`) | `src/Entity/Tenant/Student.php` |
| Shared invoice arithmetic | `src/Service/Finance/TuitionInvoiceBuilder.php` |
| CSV parse, match, preview, commit, reverse | `src/Service/Finance/FeeImportService.php` |
| Batch table | `src/Entity/Tenant/FeeImportBatch.php` |
| Payment → batch link | `Payment.importBatch` (`fee_import_batch_id`) |
| Screens | `src/Controller/Tenant/FeeImportController.php`, `templates/tenant/fee/import/` |
| Family statement line | `templates/tenant/parent/finance_view.html.twig` |
| Tests | `FeeImportServiceParseTest`, `PaymentReferenceGeneratorTest` |

A bug found while testing this flow is also fixed: `PaymentReferenceGenerator`
returned the same reference twice when several payments were minted before the
first flush, which aborted the whole batch on the unique index.

---

## 1. The problem in the school's words

A school joins Edus in the middle of a term. Its roll is uploaded, and the
invoices the importer creates are for **new intake** pupils — they include the
one-time items (uniform, books, ID card, admission form). Returning pupils were
already at the school and already paid for most of those, so their bill is wrong.
The school has also been collecting fees **by hand** for weeks; that money is in
a cash book, not in Edus.

The bursary needs one import that:

1. takes the class roster it already has (the blank score sheet),
2. says whether each pupil is **new** or **returning**,
3. asks for the **amount already paid**,
4. produces the correct invoice for the term, posts the payment, and leaves the
   outstanding balance visible on the family's statement.

Scholarships are the fourth piece. The school has pupils on 10 %, 50 % and 100 %
scholarships, but has not given us the list yet. The list has to be collected
**before** the fees import starts; the import itself must not guess who is on a
scholarship.

---

## 2. What the code already does (and why the current invoice is wrong)

| Fact | Where | Consequence |
|---|---|---|
| The student importer creates an invoice per row | `QuickAdmissionController::processStudentRow()` → `createTuitionInvoice()` | 360 imported pupils = 360 bills before the office has checked anything. |
| It bills **every non-optional fee item** | `createTuitionInvoice()` — `if (!$fee->getFeeItem()->isOptional())` | One-time items are billed to returning pupils too. This is the bug the school is seeing. |
| The admission path does the same | `AdmissionService::billStudent()` | Two copies of the same billing loop can drift. |
| “New intake only” is encoded as `FeeItem.frequency = ONETIME` | `FeeItem`, `Form/Wizard/FeeItemRowType`, onboarding (`target = NEW → ONETIME`) | We already know which items a returning pupil must not pay; we just never ask for the pupil's type. |
| A scholarship is one `StudentDiscount` → one `DiscountType` | `StudentDiscount`, `DiscountCalculator::assignmentFor()` | One rule per pupil, applied to **tuition only**, capped at the tuition line — except a **100% percentage** rule, which is a full scholarship and covers every fee item. |
| `Invoice.total_amount` is **net of the discount** | `Invoice::getNetBillable()`, `DiscountCalculator` | The invoice line items show the gross; `discount_applied` explains the gap; payments compare against the net. |
| Fees are per classroom + term + item | `FeeStructure(classroom, term, feeItem, amount)` | The importer can compute the right bill from the pupil's class. |
| A payment is a `Payment` row tied to an invoice | `PaymentController::payCash()` | `invoice.paid_amount` and `status` must be updated in the same transaction. Overpayment has a home: `StudentAdvance`. |
| The blank score sheet already lists a class | `GET /students/class/{id}/score-sheet` (`StudentController::printScoreSheet`) | This is the roster the bursary already prints; use it as the CSV template instead of inventing a new one. |
| Bulk imports already handle encoding and row-level errors | `TextEncoding`, `QuickAdmissionController::uploadCsv()`, `UniqueClashExplainer` | Reuse the same rules: `set_time_limit(0)`, `memory_limit`, one flush, row numbers in every error. |

---

## 3. The CSV the bursary will fill in

### 3.1 Where it comes from

1. **Finance → Fee schedule** is already set for the term.
2. Open the class and print the **blank score sheet** (`/students/class/{id}/score-sheet`).
3. Save/export it as CSV. It already carries the pupils' names and admission
   numbers, so nobody retypes a name.
4. Add the fees columns below.

> The score-sheet columns are a roster, not a bill. The import must match a row to
> a pupil by **admission number first**, then by first name plus **either the
> middle name or the surname** in the same class. The middle name and surname are
> interchangeable, because the office sometimes types one in the other's column.
> A name-only fallback is allowed only when the admission number is not found, and
> it carries a warning on the row.

### 3.2 Columns

| # | Column | Required | Example | Notes |
|---|---|---|---|---|
| 1 | `admission_number` | yes | `ADM/2026/0007` | Primary key for the row. Trimmed, case preserved. |
| 2 | `first_name` | yes | `Aisha` | Must match the pupil record. |
| 3 | `middle_name` | no | `Bello` | Used when the surname is blank, or when the office put the surname in this column. |
| 4 | `surname` | yes* | `Bello` | The column is required; the value may be blank when a middle name is supplied. Used with first name to verify the admission number and catch a swapped row. |
| 5 | `class_name` | yes | `JSS 1A` | Must match the school's class exactly (the importer already normalises dots/spaces/case). |
| 6 | `student_type` | yes | `NEW` \| `RETURNING` | `NEW` = first term at this school. `RETURNING` = was here before this session. |
| 7 | `amount_paid` | yes | `25000` or `25000.00` | Money already collected by hand. Blank/0 means “nothing yet”. No thousands separators. |
| 8 | `payment_date` | no | `2026-09-14` | Defaults to the import date when blank. |
| 9 | `payment_method` | no | `CASH` \| `TRANSFER` \| `POS` \| `CHEQUE` | Defaults to `CASH`. |
| 10 | `payment_reference` | no | `TRF/0914/0042` | Bank teller / POS slip number. Stored in `Payment.reference`. |
| 11 | `note` | no | `Paid at the bursary, first instalment` | Stored on the payment; useful at audit. |
| 12 | `scholarship` | read-only | `—` | Filled by the system in the preview, from the pre-import scholarship list. Not typed by the bursary. |

\* At least one of `middle_name` or `surname` must carry a value; both blank is a row error.

**Deliberately absent:** `amount_due`. The due amount is computed by Edus from
the fee schedule and the pupil's type. A bursary typing the due amount would
create a second, silent fee schedule that disagrees with Finance.

### 3.3 What “returning” changes

The current fee model already separates:

- `TERM` — every pupil pays each term;
- `SESSION` — once per session (first term);
- `ONETIME` — new intake only (uniform, books, ID card, admission form).

Therefore:

| Pupil type | Items billed |
|---|---|
| `NEW` | `TERM` + `SESSION` (in the first term) + `ONETIME` |
| `RETURNING` | `TERM` + `SESSION` (in the first term); **no `ONETIME`** |

If a school charges returning pupils a different **recurring** price (many do,
to reward loyalty), that is a fee-schedule question, not an import question. Two
options are set out in §10, decision **D2**.

---

## 4. The import flow

```
Upload CSV
   ↓
Parse + normalise (encoding, phone-style digits, decimal comma)
   ↓
Match every row to a pupil  ──►  unmatched rows go to the error report, never posted
   ↓
Preview (one screen, one row per pupil)
   name · class · type · gross fees · scholarship · net due · paid · outstanding
   ↓
Bursar confirms  ──►  one transaction:
   invoice (create or reuse) + payment rows + invoice totals/status + notice
   ↓
Result screen + downloadable “posted / skipped” CSV
```

### 4.1 Preview, then commit

Nothing is written on the first POST. The preview is the control that makes a
1,200-row file safe: the bursary sees the total due, the total collected and the
outstanding before a single `Payment` exists.

The preview is also where a **returning** pupil is visibly different: their
`ONETIME` lines are absent, so the due amount drops.

### 4.2 Idempotency

A school will upload the same file twice — after a correction, or because the
first upload seemed to hang.

- Every import gets a `FeeImportBatch` (new table) with a **file checksum** and a
  status (`preview`, `posted`, `rolled_back`).
- Re-uploading the exact same file returns the existing batch instead of
  posting twice.
- A corrected file is a new batch. It must **replace** the previous opening
  balance for the same pupil/term, not add to it. The safest rule:
  **one opening-balance batch per term**. A second commit for the same term
  refuses until the first is reversed.
- The reversal is a batch-level operation: delete the payments created by that
  batch, restore each invoice's `paid_amount` and status. No `Payment` is deleted
  outside its own batch.

> If we do not build `FeeImportBatch` in phase 1, the fallback is a fixed
> `Payment.note` prefix (`OPENING-BALANCE/<term-id>`) and a unique index on
> `(invoice_id, note)`. That is fragile. The batch table is small and worth it.

### 4.3 Row matching

1. `admission_number` exact (trimmed). This is the key.
2. For a found admission number, the **first name must match**, and **either the
   middle name or the surname** must match. The two are interchangeable: a surname
   typed in the middle-name column (or vice versa) is accepted. A blank surname
   is fine when the middle name matches.
3. If the admission number is not found: `first_name` plus **either**
   `middle_name` or `surname`, inside `class_name`, must match exactly one pupil.
4. If still ambiguous: row is **skipped**, with the candidates named in the error
   report. Never pick the first match.

A row whose admission number belongs to a pupil in a different class is an
error: it usually means the roster was sorted and two columns shifted. Say both
classes.

---

## 5. Building the invoice

### 5.1 Extract one biller

`AdmissionService::billStudent()` and
`QuickAdmissionController::createTuitionInvoice()` are the same loop written
twice. The fees import would be the third copy. Extract:

```php
final class TuitionInvoiceBuilder
{
    public function build(
        Student $student,
        Classroom $classroom,
        Term $term,
        string $studentType,      // NEW | RETURNING
    ): ?Invoice;
}
```

Rules:

1. Load `FeeStructure` for the pupil's classroom and the term.
2. Skip `isOptional()` items (unchanged).
3. Skip `ONETIME` items when `studentType === RETURNING`.
4. Sum the rest to `gross`.
5. Discount = `DiscountCalculator::calculateDiscount($student, tuitionBase, gross)`
   — **tuition only**, except a **100% percentage** rule, which uses `gross`.
6. `invoice.total_amount = gross − discount`; keep `discount_applied` and
   `discount_label`.
7. If an invoice already exists for `student + term + type ACADEMIC`, **reuse it**
   instead of creating a second one. If it already has payments, the fees import
   compares the CSV against it rather than rewriting history.
8. Return `null` when no fee rows apply; the row is reported, not silently
   zero-billed.

`AdmissionService` and the student importer then call this builder with
`NEW`/`RETURNING` from the pupil record.

### 5.2 Where the pupil's type is stored

Add to `Student`:

```php
#[ORM\Column(name: 'student_type', length: 12, options: ['default' => 'NEW'])]
private string $studentType = 'NEW';   // NEW | RETURNING
```

- The student importer gains an optional `Student Type` column. Missing value =
  `NEW`, which is the current behaviour and preserves existing files.
- The fees import can correct the type on the pupil if the CSV disagrees, but it
  must show that correction in the preview ("was NEW, file says RETURNING").
- `app:tenant:update-schema` after the mapping change (one run per tenant; check
  for orphan FK rows first — see `docs/` note).

An alternative is to derive “returning” from an earlier `Enrollment` row. That
is attractive, but it is wrong for a pupil transferring in mid-session with an
old enrolment record from another school. The CSV is an explicit statement from
the bursary; store it explicitly.

---

## 6. Posting the money

For each committed row:

```text
invoice = builder->build(student, class, term, type)
if amount_paid <= 0      → no Payment row; invoice stays UNPAID
if amount_paid > 0       → Payment(
                              invoice      = invoice,
                              amount       = amount_paid,
                              method       = CASH | TRANSFER | POS | CHEQUE,
                              reference    = row reference,
                              status       = CONFIRMED,
                              confirmedAt  = payment_date,
                              confirmedBy  = importing user,
                              note         = "Opening balance import <batch> — <row note>"
                           )
```

Then, in the same unit of work:

```text
invoice.paid_amount = old paid + import paid
invoice.status      = PAID     when paid >= total − 0.01
                    = PARTIAL  when paid > 0
                    = UNPAID   when paid = 0
```

### 6.1 Overpayment

If `amount_paid > net due`, do **not** inflate `paid_amount` beyond the bill.
The excess is money the family has paid ahead and belongs in `StudentAdvance`
(the existing “paid in advance” object), with a note naming the import batch.
The parent then sees a bill of ₦40,000 fully paid and ₦5,000 credit, rather than
an invoice that paid more than it billed.

Alternatively the import can refuse the row and ask the bursary to confirm the
excess. Decision **D3**.

### 6.2 Part payment

`amount_paid < net due` is the normal case for a school that has been collecting
in instalments. The invoice becomes `PARTIAL`; `getOutstanding()` already
returns the remainder and the family statement prints it. Nothing else to build.

### 6.3 Zero / missing amount

- Blank or `0` → invoice created, no payment, outstanding = full due. This is a
  debtor, which is a legitimate state.
- The preview shows these pupils explicitly, so a zero cannot hide inside a
  large file.

---

## 7. Scholarships — do this before the fees import

The scholarship list is **phase 0** and is not optional.

### 7.1 The model as it stands

- `DiscountType(name, mode, value)`: e.g. `Scholarship 10%`, `PERCENTAGE`, `10.00`.
- `StudentDiscount(student, discountType)`: one rule per pupil.
- `DiscountCalculator` applies it to the **tuition line only** and caps a fixed
  award at the tuition.

### 7.2 What the school must send us

A list with, at minimum:

| Column | Example |
|---|---|
| `admission_number` | `ADM/2026/0007` |
| `scholarship_type` | `Staff Child 50%` |
| `percentage` | `50` |
| `note` | `Approved by the principal, 12 Sep 2026` |

Then, **before** the fees CSV is uploaded:

1. Create three `DiscountType` rows (10 %, 50 %, 100 %) — or one per named
   scheme if the school distinguishes them.
2. Assign `StudentDiscount` to every pupil on the list (a tiny two-column import
   is enough; the UI already exists under Finance → Discounts).
3. Spot-check five pupils in the preview: net due must equal gross tuition −
   scholarship.

### 7.3 The 100 % rule

**Decided by the owner, 14 November 2026:** a **100% percentage** rule is a full
scholarship and waives **every fee item** — tuition, development levy, books and
one-time items. The total due becomes ₦0.

Every other percentage and every fixed amount is still a **tuition waiver**,
capped at the tuition line. A 99% rule does not reach the uniform or a levy.

The fees import follows the same calculator, so a full-scholarship pupil previews
at ₦0 due and is not asked to pay a residual levy.

### 7.4 One rule per pupil

The current model returns the first `StudentDiscount` for a pupil. If a pupil is
both a staff child (50 %) and a sibling (₦5,000 off), the two cannot stack today.
For the onboarding import, ask the school to name **one** rule per pupil. Stacking
is a separate change; the doc for that should extend `StudentDiscount` to many
rows and define the order of application.

---

## 8. The screens

| Screen | Route (proposed) | Role | Purpose |
|---|---|---|---|
| Fees import — upload | `GET/POST /finance/fees/import` | `ROLE_BURSAR` | Term picker, CSV file, “download template”. |
| Fees import — preview | `POST /finance/fees/import/preview` | `ROLE_BURSAR` | Table of every row with due/paid/outstanding and errors. |
| Fees import — commit | `POST /finance/fees/import/commit` | `ROLE_BURSAR` (or `ROLE_TENANT_SUPER_ADMIN`, D4) | Writes invoices + payments in one transaction. |
| Fees import — result | `GET /finance/fees/import/{batch}` | `ROLE_BURSAR` | Posted/skipped counts, downloadable error CSV, reversal button. |

The template can be produced by adding the §3 columns to the existing blank score
sheet, or by a dedicated `GET /finance/fees/import/template?classroom={id}` that
streams the same roster with the extra headers.

### 8.1 Mobile rule

The bursar will do this on a laptop, but the preview must still survive a 360 px
screen because the result is often checked on a phone. One column of cards per
row on mobile, a table only from `sm:` upwards, and every action full width and
44 px tall. No horizontal scrolling.

---

## 9. Validation and error report

The preview and the error CSV share one list. Each row can have more than one
error. Never abort a whole file for a bad row — the student importer already
learned that lesson.

| Error | Message the bursary reads |
|---|---|
| Unknown admission number | `Row 14: admission number ADM/2026/0099 is not on roll, and no pupil in JSS 1A matches Aisha Bello by first name plus middle name or surname.` |
| Name mismatch | `Row 14: admission number ADM/2026/0099 belongs to Aisha Bello, but the row says Musa Bello. Check the row order.` |
| No surname or middle name | `Row 14: enter a surname or a middle name.` |
| Class mismatch | `Row 14: Aisha Bello is in JSS 1A, but the file says JSS 2B.` |
| Unknown pupil type | `Row 14: student type “old” is not NEW or RETURNING.` |
| Amount not a number | `Row 14: amount paid “25,000” has a thousands separator. Write 25000.` |
| Negative amount | `Row 14: amount paid cannot be negative.` |
| No fee schedule | `Row 14: no fees are set for JSS 1A this term. Set the fee schedule first.` |
| Duplicate row | `Row 14 and row 22 both name ADM/2026/0099.` |
| Pupil already has a posted opening balance | `Aisha Bello already has an opening balance for this term from batch #12. Reverse it first.` |

Encoding is handled exactly as the student importer does it: `TextEncoding::row()`
and a warning when a value had to be repaired. Excel saves CSV in Windows-1252
unless the office picks “CSV UTF-8”; a curly apostrophe otherwise reaches MySQL
as an invalid string and takes the whole batch down.

---

## 10. Decisions wanted

| # | Question | Recommendation |
|---|---|---|
| **D1** | Is the CSV generated from the blank score sheet, or from a dedicated fees template? | Dedicated template that **starts** from the same roster. It keeps the score sheet a scoring document and lets us name the new columns. |
| **D2** | How is a returning pupil's fee made different? | Phase 1: same schedule, skip `ONETIME`. If the school has a lower recurring price, add `FeeStructure.appliesTo` (`ALL`/`NEW`/`RETURNING`) later; do not put the difference in the CSV. |
| **D3** | What happens to an overpayment? | Post the bill as paid and put the excess in `StudentAdvance`, with the import batch in the note. Never inflate `paid_amount`. |
| **D4** | Who may commit? | `ROLE_BURSAR`. A reversal needs `ROLE_TENANT_SUPER_ADMIN`. |
| **D5** | Is the import current-term only? | Yes for phase 1. Opening balances from previous sessions are a separate report/decision; the term picker makes the scope explicit. |
| **D6** | Is one `DiscountType` per percentage enough? | Yes for the onboarding list. Named schemes can share a percentage and differ only in the label shown on the invoice. |
| **D7** | Do we issue a receipt per imported payment? | No. The import creates the `Payment` rows; the receipt is printable from the payment like any other. Emailing 400 receipts on import day would be a mistake. |

---

## 11. Implementation plan

### Phase 0 — before any fees file is uploaded (school-side)

1. Collect the **scholarship list** (admission number, scheme, percentage).
2. Create the `DiscountType` rows.
3. Assign `StudentDiscount` to each pupil on the list.
4. Confirm the fee schedule for the term (including which items are `ONETIME`).
5. Upload/correct the roll, with the **Student Type** column (or let the fees
   file correct it in phase 2).

### Phase 1 — model + builder (no new screen yet)

1. `Student.studentType` column (`NEW`/`RETURNING`, default `NEW`).
2. Extract `TuitionInvoiceBuilder`; make `AdmissionService` and
   `QuickAdmissionController` use it.
3. Skip `ONETIME` for `RETURNING`.
4. Tests: new vs returning totals for the same class/term; a percentage below
   100% applied to tuition only; a 100% rule applied to the whole bill; invoice
   is not duplicated when one already exists.

### Phase 2 — the import itself

1. `FeeImportBatch` table (file checksum, term, status, counts).
2. Upload → parse → match → preview (read-only).
3. Commit transaction: reuse/create invoice, payments, totals/status, batch.
4. Result screen + error CSV + reversal (super-admin).
5. Tests: a full fixture file (new, returning, scholarship, part payment,
   overpayment, bad row) produces the expected invoices and payments; re-upload
   is a no-op; reversal restores the previous state.

### Phase 3 — reporting

1. Batch summary on the Finance dashboard: “Opening balances imported: ₦X of ₦Y,
   ₦Z outstanding”.
2. A line on the family statement: “Opening balance imported on <date>”, so a
   parent is not surprised by a payment they did not make through the portal.

---

## 12. What this does **not** change

- Fees remain per classroom + term + fee item. No new pricing table.
- Scholarships remain tuition-only and capped at the tuition line, except a
  100% percentage full scholarship, which covers every fee item.
- An already-issued invoice is historical: the import reuses it and adds
  payments; it does not recalculate a bill a family has already seen.
- The parent portal keeps reading `invoice.total_amount` (net) and
  `invoice.paid_amount`; the outstanding figure appears without any new field.

---

## 13. Open questions for the school

1. Does “returning” mean *any* previous term at this school, or *this session*?
2. Are there returning pupils whose recurring fee is genuinely lower than a new
   pupil's? If yes, we need a per-type price before the import.
3. Which payment methods and dates go into the file — is every line a single
   payment, or can a pupil have several instalments? (Phase 1 assumes one line
   per pupil; multiple instalments can be repeated rows if the CSV carries a
   `payment_date` and `reference`, but the duplicate-row rule then becomes a
   duplicate-(pupil, date, reference) rule.)
4. ~~Is the 100 % scholarship intended to cover everything, or tuition only?~~
   **Decided 14 Nov 2026: it covers everything.** A 100% percentage rule waives
   every fee item; lower percentages remain tuition-only.
5. Who signs off a reversal, and how long after the import is a reversal allowed?
