# Handout 03 — Bursar (money at the counter)

**Your job:** take money in, record it correctly, and know who still owes.
**Where you work:** your school's Edus address, menu group **Finance**. Mostly a
phone at the counter — everything below works one-handed.

---

## Take a cash payment

1. Open **Finance → Payment Terminal**.
2. Type the pupil's name or admission number and press **Search**.
3. Tap the pupil.
4. Under **Record New Cash**, type the amount the parent is handing over.
5. Tap **Confirm Cash Payment**.

**What you should see:** a receipt with a reference like `2610528`, the amount, today's
date and your name. The invoice status changes to **PARTIAL** or **PAID**.

If the family has a scholarship, the bill page shows the fees, then a line naming the
scholarship and the amount taken off, then **Total Due**. You never type the discount.

## Give a parent something to pay with

1. On the same bill page, tap **Generate Payment Slip**.
2. Print or show the slip.

**What you should see:** the school's bank name, account number and account name, plus a
reference number. If the slip says the bank details are **not set**, stop — the parent
must not transfer anywhere until an admin fills in **Settings → General → Banking
details**.

## A parent says they transferred

1. Go to **Finance → Payment Terminal → Verify Bank Transfer**.
2. Enter the reference from their slip.
3. Check the amount against the school's bank statement or alert.
4. Only then tap **Confirm Payment Received**.

**What you should see:** the parent's portal switches from *Verifying* to *Paid* and they
can download the receipt.

**Never** confirm a payment you have not seen in the bank.

## Give a family a scholarship

1. Open **Finance → Discounts**.
2. Search the pupil, tap them.
3. Choose the rule and tap **Confirm Assignment**.

**What you should see:** the pupil's unpaid bill is re-priced immediately and the message
names both numbers — *"Bill INV-2026-0002 re-priced: ₦62,000.00 → ₦55,800.00"*. The
**Bill effect** column shows the same on the board. If the message says *no open bill was
changed*, the rule will apply to the next bill only.

To undo it, press **Revoke** — the bill goes back to the full amount.

## Sell from the store

1. Open **Store → Store POS**.
2. If the buyer is a pupil, search them; if not, tap **Walk-In** and type their name.
3. Choose the item, set the quantity, tap **Add to Cart**.
4. Tap **Charge to Student** or **Complete Walk-In Sale**.

**What you should see:** a separate bill numbered after the fee bills. A store bill
never changes what a family owes in school fees, and a walk-in sale is attached to no
pupil at all.

## See who owes money

1. Open **Finance → Reports → Outstanding Fees**.
2. Tap **Export reminders** for the CSV — one row per family, with phone numbers.

**What you should see:** the number of families and the total, both at the top.

## Check the day

1. **Finance → Reports → Daily Collections** — what came in today, by method.
2. **Finance → Reports → Reconciliation** — confirmed money against bank.
3. **Finance → Income & Expenditure** — money in, money out, and whether the period
   ended in surplus or deficit.

## What you should never do

- **Never confirm a transfer you have not seen in the bank** — you are the record.
- **Never take a payment "on trust" and confirm later** — leave it pending; pending is
  the honest state.
- **Never delete an invoice or a payment** — record a correction instead and note why.
- **Never pay a store debt as a fee debt**, or the other way round — they are separate
  bills on purpose.
- **Never promise a parent that their money "went to tuition first"** — part payments
  settle the invoice, not a particular line.

## When it goes wrong

| What you see | What to do |
|---|---|
| "Reference not found" | They may be quoting an invoice number. Search the pupil instead |
| The slip has no account number | Ask an admin to set **Settings → General → Banking details**. Do not invent one |
| The balance here and on the statement differ | Stop and report it. Do not fix by hand |
| A scholarship changed nothing | The message says *no open bill was changed* — the rule applies from the next bill |
| A receipt prints without the school's name | Same place: **Settings → General** |
