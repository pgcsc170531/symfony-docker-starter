# 03 — Bursar / cashier (non-academic staff)

**Actor:** the person who takes money. Handles cash at the counter and the offline
transfers that did **not** come through an online payment provider.
**Where:** the school's subdomain · desktop 1024 at the counter, phone 288 for chasing.

---

## Part A — Agent playwright

Use a pupil with a known balance (demo: `361`, `INV-2026-0361`). Record the opening
figures before you start — every later assertion compares against them.

| # | Do | Expect |
|---|---|---|
| 1 | `/finance/payment/` — search by admission number | One result, the right pupil; search by name also works |
| 2 | Open the pupil's terminal (`/finance/payment/terminal/{id}`) | Invoice number, balance, and the three actions: cash, slip, wait |
| 3 | **Cash**: record a part payment (e.g. ₦20,000 of ₦67,000) | Balance drops to ₦47,000; invoice becomes `PARTIAL`; a receipt is offered |
| 4 | Open the receipt | Amount, pupil, invoice number and school bank details print correctly |
| 5 | **Slip**: generate a payment slip for the balance | A reference code is shown; the slip carries the school's bank account from Settings |
| 6 | As the **parent**, upload proof for that reference (`08-parent.md` step 6) | Payment appears as `PENDING`, balance unchanged |
| 7 | `/finance/payment/verify` — enter the reference | The exact pending payment is shown: pupil, amount, date initiated |
| 8 | Confirm it | Balance drops by the amount; the payment becomes `CONFIRMED`; the parent's screen stops saying "verifying" |
| 9 | Confirm the **same** reference again | Refused (already confirmed) — a second confirmation must never double-credit |
| 10 | `/finance/reports/collections?date=<today>` | Day book totals include both payments, split by method |
| 11 | `/finance/reports/payments` — filter by method `TRANSFER` | Both the register and its CSV contain exactly the filtered rows |
| 12 | `/finance/reports/reconciliation` | Pending vs confirmed counts agree with steps 6–8 |
| 13 | `/finance/reports/debtors` | The family's row shows the new balance; expanding shows each child |
| 14 | Export the debtors reminder CSV | One row per family, with phone and a prefilled message |
| 15 | `/finance/reports/statement/{id}.pdf` | A4 (`%PDF-`, 595.28×841.89), number matches, QR present |
| 16 | `/finance/expenses/` — record a cash expense | Appears in the register and reduces the P&L |
| 17 | `/store/sales/cart/{id}` — charge a product to the pupil | A **store** invoice is created; the academic balance is unchanged |
| 18 | `/store/sales/search` → walk-in sale | No pupil debt created; stock reduces; walk-in receipt prints |
| 19 | `/finance/discounts/assign` — give a pupil a 10% scholarship | The invoice re-prices net of the discount; the discount appears in the discount report |

### Assertions specific to money

- After every confirmation, **billed − paid = outstanding** on the pupil's statement, the
  debtors report and the income statement. If two of the three disagree, that is a
  **Data** defect, not a display bug.
- A `PENDING` payment is never counted as collected anywhere.
- Store ledger and academic ledger never merge unless the report explicitly includes
  store purchases.
- The **same** invoice number appears on: terminal, receipt, statement PDF, payment
  register.

### Run 2026-10-19 — bursar — school `stmarys` (1,200 pupils)

Bursar `bursar@stmarys.ng` · pupil `26/EEF9C` (Arraghibun Bello, invoice `INV-2026-0001`, ₦62,000).

| Step | Result | Note |
|---|---|---|
| 1 Search by admission number | **pass** | `26/EEF9C` → one result, correct pupil |
| 2 Terminal | **pass** | Invoice, items, balance, three actions |
| 3 Part cash ₦20,000 | **pass** | Balance ₦42,000, invoice `PARTIAL`, receipt redirect |
| 4 Receipt | **pass** | `RECEIPT #2610528`, ₦20,000, CASH, bursar signature |
| 5 Generate slip | **FAIL → FIXED → pass** | **P-BURSAR-01**: 500 duplicate reference `2610528`. After `PaymentReferenceGenerator`: slip 3 / reference **2610529** |
| 5b Bank details on slip | **FAIL → FIXED → pass** | **P-BURSAR-02**: printed `0000000000 / Bank Not Set`. After the fix: warning when unset; with settings set it prints `JAIZ BANK · 0003344556 · St Mary's College Fees` |
| 6 Parent uploads proof | **pass** | Flash: *“Proof of payment uploaded!”* — a 1-byte fake file was rejected, a real PNG accepted (validation works) |
| 7 Verify by reference | **pass** | ₦42,000, Pending, proof preview, “Confirm Payment Received” |
| 8 Confirm (dialog: *“Are you sure the money is in the bank?”*) | **pass** | Payment `CONFIRMED` by bursar; invoice `PAID` (₦62,000 = ₦20,000 cash + ₦42,000 transfer) |
| 9 Confirm again | **pass** | Screen shows “Status: Verified ✅ Confirmed by bursar” and **no** confirm button — no second credit |
| 10 Day book | **pass** | CONFIRMED ₦62,000 · CASH 1/₦20,000 · TRANSFER 1/₦42,000 |
| 11 Payment register | **pass** | 2 rows; filter, CSV and print present |
| 12 Reconciliation | **pass** | CONFIRMED ₦62,000 · UNVERIFIED ₦0 · per-method split correct |
| 13 Debtors + reminder CSV | **pass** | *1,007 families owing · ₦74,338,000.00*; `/finance/reports/debtors.csv?reminder=1` → 1,008 lines, columns `Guardian,Phone,Pupils,Children,Outstanding,Message` |
| 14 Statement PDF | **pass** | `STMT-26-EEF9C-ALL`, 45,123 bytes, MediaBox 595.28 × 841.89 (A4), QR footer |
| 15 Expense category + entry | **pass** | *Fuel & Power* created; *Diesel for generator* ₦15,000 (Total Filling Station) recorded |
| 16 Discount rule | **pass** | *Staff Child Scholarship*, 10% |
| 17 Assign the rule to a pupil with an open bill | **FAIL → FIXED → pass** | **P-BURSAR-04**: flashed success, bill stayed ₦62,000. After `DiscountRepricer`: *“Bill INV-2026-0002 re-priced: ₦62,000.00 → ₦55,800.00 (₦6,200.00 discount).”*, board shows `₦62,000 → ₦55,800`, terminal and invoice print *Staff Child Scholarship (10%) −₦6,200.00* |
| 18 Parent sees the scholarship | **pass** | Parent of `26/F13DE` signs in: fees list reads *₦55,800 — −₦6,200 scholarship*; the invoice page names the rule |
| 19 Revoke the rule | **pass** | *“Bill INV-2026-0002 put back to ₦62,000.00 (it was ₦55,800.00 with the scholarship).”* — then re-assigned to leave the demo data consistent |
| 20 Store stock | **pass** | *Exercise Book (80 leaves)* · STATIONERY · ₦800 · 40 in stock (no class restriction) |
| 21 Charge a store item to a pupil | **pass** | POS cart on `26/EEF9C`: 2 books → **INV-2026-1201**, `STORE`, ₦1,600 `UNPAID`; the academic bill `INV-2026-0001` stayed `PAID · ₦62,000` — the two ledgers did not mix |
| 22 Walk-in sale | **pass** | *Mallam Sani (walk-in)* · 1 book · receipt `26106770` · invoice `INV-2026-1202` `PAID` ₦800 — **no pupil attached**, so no child carries a debt |
| 23 Stock after three units sold | **pass** | 40 → **37** |

Role complete. Two product suggestions were logged rather than implemented: the POS
`Add to Cart`/checkout buttons are plain DOM with a native `confirm()`, and the store
invoice uses the same `INV-` series as academic fees (1201 follows 1200 untagged by
type in the number itself — the `type` column distinguishes them).

### Expected 403s for this role
`/settings/general`, `/staff/`, `/tenant/results/entry` (unless also a teacher),
`/subscription/` (admin only) — record which are refused.

---

## Part B — User handout

### Your job

Money in, money proven. You record what a parent hands you, and you confirm transfers
that actually arrived in the school's bank account.

### A parent pays cash at the counter

1. Sign in and open **Finance → Payment Terminal**.
2. Search by the pupil's name or admission number and open the pupil.
3. Type the amount received and click **Confirm cash payment**.
4. Print or hand over the receipt.
5. The balance drops immediately, and the parent's portal shows it.

### A parent says they transferred to the school account

Handle it in this order — this is the step most schools get wrong:

1. **Check the bank statement first.** No money in the account means no confirmation,
   whatever the parent says.
2. Open **Finance → Payment Terminal → Verify bank transfer**.
3. Enter the reference from the parent's slip (or their transfer narration).
4. Read the details shown: pupil, amount, date. If the amount is wrong or the reference
   belongs to another child, **do not confirm** — call the parent.
5. Click **Confirm payment received**. The balance updates and the parent can download
   their receipt.
6. Confirming the same reference twice is refused. If you see a refusal, it is already
   done — do not look for another way in.

### Giving a parent something to pay with

1. Open the pupil's terminal and choose **Generate payment slip**.
2. Give the parent the reference. It is what they must write on the transfer.
3. The slip prints the school's bank details — if those are wrong, fix them in
   **Settings → General → Banking details**, not on the paper.

### Reading the money screens

- **Daily collections** — what came in today, by cash, transfer, POS or cheque. Check it
  against the cash box before you leave.
- **Payment register** — every payment, filterable and exportable to CSV.
- **Reconciliation** — anything still `PENDING` after two days is a proof nobody has
  checked. That is your chase list.
- **Outstanding fees** — one row per family with a phone number, not one row per child.
  A family with two children appears once; open the row to see each child.
- **Income statement** — what the term earned against what it cost.

### What you should never do

- **Never confirm a transfer you have not seen in the bank.**
- **Never take a payment "on trust" and confirm later.** Leave it pending — the record
  is the point.
- **Never delete an invoice or a payment.** If something was recorded wrongly, record the
  correction and note the reason.
- **Never chase a store debt as a fee debt**, or the other way round. They are separate
  ledgers on purpose.

### When it goes wrong

| What you see | What to do |
|---|---|
| The reference is not found | The parent may be quoting an invoice number, or the slip was never created. Search the pupil first |
| A payment is pending but the money is not in the bank | Leave it. Tell the parent to check with their bank; do not confirm |
| The balance on the terminal and the statement disagree | Stop and record it — that is a system fault, not something to fix by hand |
| A receipt prints without the school's bank details | Set them in **Settings → General → Banking details** |
