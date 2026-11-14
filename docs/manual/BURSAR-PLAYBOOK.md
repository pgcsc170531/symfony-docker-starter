# Edus Bursary Playbook

**For:** bursary, accounts office and school-fee staff (`ROLE_BURSAR`)
**Version:** 1.0 — **14 November 2026**
**Use with:** the school's live Edus portal and a training/demo tenant for practice

> This playbook teaches the bursary desk the daily work: finding a family, keeping
> parent contacts correct, importing fees already collected, taking and verifying
> money, and closing the day. Each part explains what the screen is for, then the
> steps, then what success looks like. It includes the changes shipped on **14
> November 2026** — family linking, parent titles, student type, phone search and
> the opening-balance **Fees Import**.

---

## What changed on 14 November 2026

| Change | What it means for you | Where |
|---|---|---|
| **Fees Import** | Upload a CSV of fees the school already collected. Edus builds the term bill, posts the payment and leaves the correct outstanding balance. | **Finance → Fees Import** |
| **Family linking by phone + name** | When a parent's phone number already belongs to another parent, Edus checks whether a child shares a **surname or middle name**. If so, it offers to merge the two records into one family. If not, it refuses and names the children already on that number. | **Parents → Manage family → Edit** |
| **Add a child to a family** | A parent or guardian can say "this child is in my care". Search the pupil, tick, add. The parent gets a notice to confirm or report a mistake. | **Parents → Manage family → Add a pupil to this family** |
| **Parent notices** | Parents confirm a family change or write a note saying it is a mistake. Staff see disputed links in one queue. | **Parents → Family updates** |
| **Student Type** | Every pupil is **New intake** or **Returning**. Returning pupils are not billed one-time new-intake items. | **Students → pupil → Edit**, admission form, bulk CSV |
| **Parent titles ALH / HAJ / MAL** | Alhaji, Hajia and Mallam are now available when creating or editing a parent. | Parent form |
| **Phone search fixed** | A parent is found whether you type `0803…`, `803…`, `+234803…`, `234803…`, or only part of the number. | **Parents** search |
| **Bulk Import Student Type** | The bulk pupil CSV now accepts an optional 14th column, `Student Type (NEW/RETURNING)`. Blank means NEW. | **Admissions → Bulk Import** |
| **Mobile layout** | The parent, fee-import and student screens work at 320 px and 360 px. Tables scroll inside their own box; the page itself does not scroll sideways. | All screens |

> **Two imports — do not confuse them.** **Bulk Import** creates *new pupil
> records*. **Fees Import** records *money already collected* for pupils who are
> already on roll. A pupil must exist before Fees Import can find them.

---

## How to use this playbook

- Read **Part 1** before your first login.
- Work through **Parts 2–9** with a trainer on a **training tenant**, never on the
  live school database.
- Use **Part 15** as the quick reference at the desk.
- The **Training exercises** at the end are the sign-off: if you can do all of
  them without help, you are ready for live work.

**Suggested 2-hour training plan**

| Time | Module | Activity |
|---:|---|---|
| 0–10 min | Login and the bursary menu | Tour the menu, confirm your role |
| 10–30 min | Parents, phone search and family linking | Find a parent, edit a contact, add a child |
| 30–55 min | Student Type and Fees Import | Upload a 3-row CSV, read the preview, post it |
| 55–75 min | Daily collection | Cash, payment slip, verify a transfer, receipt |
| 75–95 min | Reports and close of day | Daily Collections, Outstanding Fees, Payment Register |
| 95–120 min | Practice and sign-off | Complete the exercises at the end |

---

## Part 1 — Before you touch the system

### 1.1 Your login and your role

1. Open the school's Edus address in a browser. On a phone, Chrome is the safest
   choice.
2. Sign in with **your own** staff account. Never use another bursar's login and
   never share your password.
3. The bursar role sees these menu groups:

| Menu group | What you can use |
|---|---|
| **Admissions** | **Bulk Import**, **Students**, **Parents**, **Promote Students** |
| **Finance** | **Fee Schedule**, **Fees Import**, **Invoices**, **Payment Terminal**, **Verify Payments**, **Unmatched Transfers**, **Advance Payments**, **Discounts**, **Expenses**, **Income & Expenditure** |
| **Reports** | **Fee Reports**, **Daily Collections**, **Collections by Class**, **Outstanding Fees**, **Payment Register**, **Reconciliation**, **Online Payments**, **Class Fee Status**, **Expense Register**, **Income Statement**, **Discount Report**, **Application Fees** |

**Not on the bursar menu:** New Admission (one child at a time), Applications,
Settings, Staff, Classes, Sessions & Terms. Those belong to the administrator or
admissions officer. If you cannot see a screen you need, ask the administrator —
do not ask for a colleague's password.

### 1.2 The five rules that prevent most mistakes

1. **Preview before you post.** Fees Import writes nothing until you press
   **Post**. Read the preview first.
2. **One row = one pupil = the total already paid for the term.** It is not an
   instalment schedule and it is not one row per payment.
3. **One posted opening-balance batch per term.** To correct a posted batch,
   reverse it first, then upload the corrected file.
4. **Set Student Type before the bill is generated.** NEW and RETURNING pay
   different lines. Changing the type after money is on the bill does not
   re-price that bill.
5. **A phone number is a family key.** It is how siblings are matched and how a
   parent signs in. Verify the parent's identity before changing it.

### 1.3 The two imports — a one-minute comparison

| | **Bulk Import** | **Fees Import** |
|---|---|---|
| **Purpose** | Add new pupils to the school | Record fees already collected |
| **Creates** | Pupil record, parent link, enrolment, invoice (if **Generate Bills?** is ticked) | Payment, invoice status, outstanding balance |
| **Use when** | A new intake list arrives | The school collected cash/transfer/POS before using Edus |
| **Pupil must exist?** | No — this creates them | **Yes** — match by admission number and name |
| **Menu** | Admissions → **Bulk Import** | Finance → **Fees Import** |
| **Rows** | One per new pupil | One per pupil per term |
| **Danger** | Duplicate admission numbers | Posting the same term twice |

---

## Part 2 — Find a pupil or a parent

### 2.1 Find a pupil

1. Go to **Admissions → Students**.
2. Search by **name** or **admission number**.
3. Filter by class or by fee status if you need a list.
4. Click the pupil's name to open the profile.

On the profile you can see the class, admission number, parent, siblings, fee
ledger and the **Parent & Siblings** button. Use **ID Card** to print an ID card
and the statement/PDF links for a family statement.

### 2.2 Find a parent — including the phone-number fix

Go to **Admissions → Parents**. The search box accepts:

- a parent's name;
- an email address;
- a child's name;
- a phone number in **any** of these shapes:
  - `08039658200`
  - `8039658200`
  - `+2348039658200`
  - `2348039658200`
  - the last four or five digits, e.g. `58200`.

You no longer need to remember whether the leading zero was stored. Type the
number the way the parent wrote it and search.

**Expected result:** one row per parent, showing phone, email/login, and every
child linked to that parent. If a parent has no login, the row says **No login
account**. If no parent matches, try the child's name or the last digits of the
phone.

> **Tip:** if a parent says "I have two children in the school", search the phone
> once. Both children should appear in the **Children** column. If only one
> appears, use **Manage family** to see the family page and add the missing child
> (Part 3.4).

### 2.3 Titles ALH, HAJ and MAL

The parent form now offers **ALH** (Alhaji), **HAJ** (Hajia) and **MAL**
(Mallam), in addition to the existing titles. Use the title the parent uses; it
is saved on the parent record and used wherever the parent's designation is
printed. The directory search still uses the parent's name, phone, email or
child's name — not the title.

---

## Part 3 — Parent contacts and family links (new)

### 3.1 Open a family

1. **Admissions → Parents**.
2. Search by phone, name or child.
3. Click the parent's name, or the **Manage family** button.

The family page shows:

- **Contact details** — phone, alternate phone, email/login, address.
- **Pupils in this family** — every child, their class and admission number.
- **Add a pupil to this family** — search and move a child here.
- **Family updates** — notices sent to the parent and their answers.

### 3.2 Change a parent's contact details

1. On the family page, press **Edit** in the **Contact details** card.
2. Change the title, name, phone, alternate phone, email, relationship,
   occupation or address.
3. Press **Save contact details**.

**Expected result:** you return to the family page with a green message. The new
number is stored in one standard shape and is used for **every child in that
family**.

> **Changing a phone changes the family, not one child.** All siblings under that
> parent are reached on the new number. Confirm with the parent before saving.

If the parent signs in with a phone-derived address (for example
`8039658200@edus.ng`), Edus updates that login when the phone changes. A personal
email login is left unchanged.

If the new number already belongs to another parent, the save does not happen
straight away — see 3.3.

### 3.3 "Another child already has this number" — the merge preview

This is the new sibling-merge flow. It runs when the phone number you type is
already held by a **different** parent record.

1. After you press save, Edus checks the other parent's children.
2. It compares names with the children under the parent you are editing.
3. A match must be a shared **surname** or a non-empty **middle name**. A blank
   middle name never matches another blank middle name.

**If one child matches**, you see a panel:

> **Another child already has this number**
> *Other parent's name* already holds *the number* and has: …
> The names match on **surname "Bello"** (or **middle name "Ibrahim"**), so the
> system treats these as one family. Saving will move *your children* under
> *the other parent*. The other parent record is removed and one family login is
> kept.
> ☐ I confirm this is the same family.

- Nothing has been saved at this point.
- Read the names carefully. If they are the same family, tick the box and press
  **Merge and save contact**.
- **Expected result:** all children are under one parent, the duplicate parent
  record is removed, and one login is kept. The family page opens on the
  surviving parent.

**If no child shares a surname or middle name**, nothing is saved. The phone
field shows a message naming the other parent and the children already on that
number. This is deliberate: two families can share a handset (an office phone or
a relative's phone), and merging them would put a stranger's child on the wrong
bill.

What to do then:

- If the number was typed wrongly, correct it and save again.
- If it really is the same family but the children have different surnames (for
  example, a remarriage), do **not** force a merge. Open the correct parent's
  family page and use **Add a pupil to this family** (3.4), then ask the
  administrator to tidy the empty duplicate record.

**If both parent records already have a dedicated bank account (virtual
account)**, Edus refuses the merge so that neither bank account is orphaned. Use
**Add a pupil to this family** instead.

**If more than one other parent record shares a child's name**, Edus also refuses
and tells you to use **Add a pupil** on the correct family. Do not guess which
record is the right one.

### 3.4 Add a child to a family — the guardian case

Use this when a parent, uncle, aunt or guardian says: "This child is in my care,
but was registered under someone else."

1. Open the **correct** parent's family page.
2. In **Add a pupil to this family**, search by name or admission number.
3. The results show the child's current class, admission number, and the parent
   they are currently under.
4. Tick the child (or children) to move.
5. Press **Add selected to this family**.

**Expected result:**

- The child moves to this parent immediately.
- The parent receives a notice in their portal: a new pupil has been added to
  their family.
- The parent can press **This is correct** or **This is a mistake — write a
  note**.

> **This changes who is responsible for the bill.** Before moving a child, ask
> for evidence: a written request, a guardianship letter, or confirmation from
> the school management. Do not move a child because someone at the gate says so.

### 3.5 Family updates — the staff queue

Go to **Admissions → Parents → Family updates**.

Tabs:

| Tab | Meaning |
|---|---|
| **Reported by parents** | The parent says the link is wrong. Read the note and act. |
| **Awaiting parent** | The notice was sent; the parent has not answered yet. |
| **Confirmed** | The parent agrees with the change. |
| **All** | Every notice. |

Each card shows the parent, phone, pupil, the notice text and the parent's note.
A reported link needs the office: contact the parent, check the evidence, and
correct the family. Do not ignore a reported notice.

### 3.6 What not to do with families

- Do **not** create a second parent record with the same phone number to "fix" a
  sibling. Use the family page.
- Do **not** change a child's surname or middle name just to make a merge work.
  Names belong to the pupil's records.
- Do **not** use **Add a pupil** to fix a wrong admission number or a wrong class.
  Correct the pupil record or the enrolment instead.
- Do **not** move a child between families without evidence and without telling
  the parent — the parent will see the notice, and a disputed link comes back to
  the office.

---

## Part 4 — Pupil records and Student Type (new)

### 4.1 Open and edit a pupil

1. **Admissions → Students**.
2. Search and open the pupil.
3. Press **Edit Profile**.
4. The form includes **Student Type**.

### 4.2 New intake vs returning

| Student Type | Who it is | What Edus bills |
|---|---|---|
| **New intake** (`NEW`) | A pupil joining the school for the first time | Recurring items **plus** one-time new-intake items (uniform, books, ID card, admission form — whatever the school marks as one-time) |
| **Returning** (`RETURNING`) | A pupil who was already in the school | Recurring items only; **no** one-time new-intake items |

A `SESSION` item is billed only in the **first term** of the session, regardless
of type.

**Set this correctly before generating an invoice or posting a Fees Import.** If
the type is wrong, the bill is wrong.

### 4.3 Changing Student Type later

- If the pupil has **no payment** against the term invoice, a Fees Import row for
  that pupil can rebuild the unpaid invoice using the file's type.
- If money is already on the bill, the bill is treated as history and is **not**
  re-priced automatically. Stop and ask the administrator before changing the
  type.
- Changing the type on the pupil form alone corrects the record; it does not
  re-price an existing bill.

### 4.4 Student Type in Bulk Import

The bulk pupil CSV now has an optional 14th column:
`Student Type (NEW/RETURNING)`. Blank means **NEW**, which matches the old
template. For a returning intake, fill `RETURNING` so the one-time items are not
billed.

> **Use the exact words `NEW` or `RETURNING`.** In Bulk Import, any other value
> (for example `RETURNNG`) is treated as NEW, so a returning pupil can be billed
> the one-time items. Fees Import is stricter and rejects a bad value with a row
> error.

---

## Part 5 — Bulk Import: adding new pupils

**Menu:** Admissions → **Bulk Import**

Use this for a new intake list. It creates pupil records, links siblings by
parent phone, enrols the pupil, and can generate bills.

### 5.1 Download the template

Press **Download CSV Template**. The file has **14 columns**:

| # | Column | Notes |
|---:|---|---|
| 1 | First Name | Required |
| 2 | Middle Name | Optional |
| 3 | Last Name | Required |
| 4 | Gender (M/F) | `M` or `F` |
| 5 | DOB (YYYY-MM-DD) | e.g. `2015-01-01` |
| 6 | Religion | Optional |
| 7 | Blood Group | Optional |
| 8 | Genotype | Optional |
| 9 | Home Town | Optional |
| 10 | Admission Number | Leave blank to auto-generate |
| 11 | Class Name | Must match an existing class exactly |
| 12 | Parent Name | Parent/guardian full name |
| 13 | Parent Phone | **Required** — this is how siblings are matched |
| 14 | Student Type (NEW/RETURNING) | Optional; blank = NEW |

### 5.2 Step by step

1. Fill the file, one row per new pupil.
2. Save as **CSV UTF-8** (Excel: *Save As → CSV UTF-8 (Comma delimited)*).
3. Choose the **Term**.
4. Tick **Generate Bills?** if you want invoices created now.
5. Choose the file and press **Upload & Process**.
6. Read the import report.

**A bad row does not destroy the file.** Edus imports the good rows and reports
the bad ones with their row number. Fix only the rejected rows and upload them
again.

### 5.3 Common Bulk Import errors

| Message / symptom | Cause | Fix |
|---|---|---|
| "Class '…' not found" | Class name does not match the class list | Copy the exact class name |
| "no parent phone number" | Parent phone blank | Add the phone; siblings depend on it |
| "admission number … belongs to …" | Number already used by another pupil | Use the correct number or leave it blank |
| "row … of this file already uses it" | Same admission number twice in the file | Remove the duplicate |
| "Format Error: Template requires 12 columns" | File has too few columns | Download a fresh template |
| Names or dates look wrong | Excel changed the format | Save as CSV UTF-8; use `YYYY-MM-DD` |
| Imported names show `Ã©` or `â€™` | File was not UTF-8 | Re-save as CSV UTF-8 and re-upload |

---

## Part 6 — Fees Import: opening balances (new)

**Menu:** Finance → **Fees Import**

### 6.1 What Fees Import does

It imports fees the school has **already collected by hand** — cash, transfer,
POS or cheque — for pupils already on roll.

For each valid row, Edus:

1. finds the pupil by admission number, then checks the first name plus either the middle name or the surname, in that class;
2. builds the term bill from the **Fee Schedule**, using the pupil's **Student
   Type** and any scholarship;
3. posts the **Amount Paid** as a confirmed payment;
4. marks the invoice **PAID**, **PARTIAL** or **UNPAID**;
5. leaves the outstanding balance on the family statement;
6. records any overpayment as an **advance credit** for the pupil.

**It does not** create pupils, create fee amounts, or accept one row per
instalment.

### 6.2 Before you upload — six checks

1. **The term is the correct term.** The term you choose is the term billed.
2. **The Fee Schedule is complete** for every class in the file. A class with no
   fees produces an error row.
3. **The pupils exist** and their class matches the file.
4. **Student Type is correct** for each pupil.
5. **Scholarships are already assigned** in **Finance → Discounts**. If an invoice
   exists, the import reads its discount; if not, the rule is applied when the
   bill is priced.
6. **No other opening-balance batch is posted for this term.** If one is, reverse
   it first (6.11).

**To check the Fee Schedule:** go to **Finance → Fee Schedule**, choose the term,
and confirm every class in your file has its tuition and other fee lines. Use
**Edit Prices** to correct an amount, or **Per arm** when one arm costs more. If
a whole fee item is missing, ask the administrator. An empty schedule is the most
common reason a whole Fees Import is skipped.

### 6.3 Build the file from a class roster

1. In **Fees Import**, find **Build the file from a class roster**.
2. Choose the **Class**.
3. Press **Download roster CSV**.

The download is pre-filled with:

`Admission Number, First Name, Middle Name, Surname, Class Name, Student Type,
Amount Paid, Payment Date, Payment Method, Payment Reference, Note`

The admission number, all three name columns and the class are filled from the
class list. Student Type comes from the pupil record. The bursary fills
**Amount Paid** and the payment details.

> **Middle Name is there for tracing.** Some pupils were entered with a middle
> name but no surname. The roster now shows First, Middle and Surname together,
> so the row can still be identified before it is uploaded.

> **Check the pre-filled Student Type.** It comes from the pupil record. If the
> school has not maintained the field, a returning pupil may still say `NEW`.
> Correct it in the file before posting, or the one-time new-intake items will be
> billed.

### 6.4 The eleven columns

| Column | Required? | What to enter |
|---|---|---|
| **Admission Number** | **Yes** | The pupil's exact admission number, e.g. `24/001`. This is the key. |
| **First Name** | **Yes** | As on the pupil record |
| **Middle Name** | No | As on the pupil record. Used when the surname is blank or when the two are swapped. |
| **Surname** | **Yes\*** | As on the pupil record. May be blank when a middle name is supplied. |
| **Class Name** | **Yes** | Exact class, e.g. `JSS 1A` |
| **Student Type** | **Yes** | `NEW` or `RETURNING` |
| **Amount Paid** | **Yes** | **Total already collected for the term**, not an instalment. Blank means `0`. |
| **Payment Date** | No | `YYYY-MM-DD` or `DD/MM/YYYY`. Blank = today. |
| **Payment Method** | No | `CASH` (default), `TRANSFER`, `POS`, `CHEQUE` |
| **Payment Reference** | No | Teller, receipt or transfer reference |
| **Note** | No | A short note; it is added to the payment record |

\* At least one of **Middle Name** or **Surname** must carry a value; both blank
is a row error.

**How a pupil is matched:** the **Admission Number is the key**. Edus then requires
the **First Name** plus **either the Middle Name or the Surname**. The two may be
swapped or one may be blank — the import accepts whichever one matches the pupil
record. If the admission number is not found, Edus can still fall back to the name
plus class, but it marks that row with a warning. Check the number before posting.

The column headings are flexible (for example `Admission No`, `Last Name`,
`Method`), but the safest file is the template with its headings unchanged.

### 6.5 Amount Paid — the rule that causes most errors

- Enter the **total** the school has collected for that pupil for that term.
  - Parent paid 20,000 in September and 17,500 in October → enter **37500**.
  - Do **not** enter 17500 as a second instalment.
- Enter `0` or leave it blank if the pupil has paid nothing.
- Do **not** use thousands separators: write `37500`, not `37,500`.
- `₦` signs and spaces are ignored. Up to two decimal places are accepted.
- A negative amount is rejected.
- If the total is **more than the term bill**, the extra becomes an **Advance
  Payment** credit for that pupil.
- Edus compares the file total with what is **already recorded**. It posts only
  the difference. If ₦10,000 is already on the invoice and the file says
  ₦37,500, the import posts ₦27,500.

> **The file is a snapshot.** If a parent pays more after you prepared the CSV
> but before you post it, either update **Amount Paid** to the new total, or post
> the file first and then record the extra payment in **Payment Terminal**. Never
> do both for the same money.

### 6.6 Upload and preview

1. Choose the **Term**.
2. Choose the CSV file.
3. Press **Preview import**.

**Nothing is posted.** The preview is a draft. Re-uploading the exact same file
reuses the existing preview instead of creating a duplicate.

If the file is missing a required column, nothing is imported and the page lists
the missing column(s). Download a fresh template and copy your data into it.

### 6.7 Read the preview

The summary shows:

- **Due** — total term bill for all valid rows;
- **Collected** — total Amount Paid;
- **Outstanding** — Due − Collected;
- **Ready** — rows that will post;
- **Skipped** — rows with a red error;
- **Warnings** — rows that will post but need a second look.

For each pupil, check:

- the name and admission number;
- the class and Student Type;
- **Due**, **Paid** and **Outstanding**;
- the invoice number (blank means a new invoice will be created);
- any **Scholarship** label;
- warnings and errors.

**Red error = the row will not post.** Yellow warning = the row will post, but
read it. For example, "the pupil record says NEW, the file says RETURNING — the
record will be corrected" is a warning: **posting will overwrite the pupil's
type**. If the file is wrong, stop and correct the CSV before posting.

### 6.8 Post the batch

When the preview is correct, press **Post N rows**.

- Only ready rows are posted. Skipped rows are left out and reported.
- The posting runs as one transaction; a database failure rolls the whole batch
  back. A row whose pupil or class disappeared is skipped and named.
- You cannot post a second opening-balance batch for the same term. If one
  exists, Edus names the posted batch and tells you to reverse it first.

**Expected result:** the batch status becomes **Posted**, with counts of posted
and skipped rows. Each posted pupil now has:

- the term invoice (created or updated);
- a confirmed payment with the amount paid;
- the correct status: `PAID`, `PARTIAL` or `UNPAID`;
- an outstanding balance on the family statement;
- an advance credit if the payment was above the bill.

### 6.9 After posting

- Press **Download rows CSV** to keep the result. It contains the pupil, class,
  due, paid, outstanding, invoice number, status, warnings and errors.
- Open a pupil's profile or the **Payment Register** to confirm the payment.
- Do **not** change the fee schedule expecting posted bills to change. A posted
  bill is history.
- Keep the original upload file and the batch number in your records.

### 6.10 Where the bills live

**Finance → Invoices** is the school's bill ledger.

1. Filter by **Unpaid**, **Partial** or **Fully Paid**.
2. Search by pupil name or invoice number.
3. Read the filtered totals at the bottom: total billed, paid and balance.
4. Press **Manage** to open an invoice: its lines, payments, balance, **Print**
   and **Generate Payment Slip**.

If a pupil has no invoice yet, open **Payment Terminal**, search the pupil and
press **Generate Invoice Now**. Edus builds it from the Fee Schedule and the
pupil's Student Type. Do not create a second invoice by hand — the system reuses
the existing one for the term.

### 6.11 Reverse a batch

Only a **tenant super admin** sees **Reverse this batch**.

1. Open the posted batch.
2. Press **Reverse this batch** and confirm.
3. Edus:
   - removes the payments created by the batch;
   - restores each invoice's paid amount and status;
   - removes unused overpayment credits from the batch;
   - restores the pupil's previous Student Type;
   - marks the batch **Reversed**.

**It refuses** if an overpayment credit from the batch has already been used.
Reverse the payments that used the credit first, then reverse the batch.

After reversal, upload the corrected file. The reversed batch stays on record.

### 6.12 Scholarships and discounts

Assign scholarships **before** the Fees Import.

1. Go to **Finance → Discounts**.
2. Use **Manage Rules** to create, **edit** or **delete** a rule (percentage or
   fixed amount). Editing a rule re-prices the open bills that use it; an
   assigned rule must be revoked from its pupils before it can be deleted.
3. On the **Scholarship Board**, search the pupil, select the rule and confirm.
   The **Apply to grand total** box on that form is the pupil's own scope:
   ticked, this award is taken off the whole bill for this child; unticked, it
   follows whatever the rule says. Two pupils can therefore share one rule and
   still be treated differently.
4. Read the **Bill effect** column:
   - a green `₦gross → ₦net` means the rule reached the bill;
   - "No open bill" means it will apply to the next bill;
   - "Not applied yet" means revoke and re-assign to re-price the bill.

Scholarships reduce **tuition only**, not every fee line, by default. There are
two places to widen that:

| Where | What it does |
|---|---|
| **Apply to grand total** on the *rule* | The default for every pupil on that rule |
| **Apply to grand total** on the *Scholarship Board* | This pupil only — leaves the rest of the school alone |

Either way the percentage is then taken off the **grand total due** — tuition,
levies, books and one-time items together. A 100% award there makes the total due
₦0; one that does not covers tuition only. Every row on the board is labelled
**Grand total** or **Tuition only**, so you can see which scope is in force.

In the Fees Import preview, a discounted pupil shows `Scholarship: <label>` and
the **Due** is already net of the discount. If the discount is missing, stop and
fix the scholarship before posting.

### 6.13 Worked examples (illustrative amounts)

| Situation | Term due | Amount Paid in file | Result |
|---|---:|---:|---|
| New pupil, part payment | ₦62,500 | ₦37,500 | **PARTIAL** — ₦25,000 outstanding |
| Returning pupil, full payment | ₦50,000 | ₦50,000 | **PAID** — ₦0 outstanding |
| Returning pupil, tuition scholarship already applied | ₦25,000 due after discount | ₦25,000 | **PAID** — ₦0 outstanding |
| Parent paid above the bill | ₦50,000 | ₦60,000 | **PAID** — ₦10,000 advance credit |
| Nothing paid yet | ₦50,000 | `0` or blank | **UNPAID** — ₦50,000 outstanding |
| Wrong student type in file | ₦62,500 as NEW, but pupil is RETURNING | any | Warning; posting corrects the type and rebuilds an **unpaid** bill |

### 6.14 Common Fees Import errors and fixes

| Message / symptom | What it means | What to do |
|---|---|---|
| "The file is missing the … column" | Template headings were changed or deleted | Download a fresh template; keep the headings |
| "student type '…' is not NEW or RETURNING" | Typo such as `RETURNNG` | Use `NEW` or `RETURNING` |
| "amount paid '…' has a thousands separator" | `37,500` was typed | Write `37500` |
| "amount paid '…' is not a number" | Text, or more than two decimals | Write `37500` or `37500.00` |
| "amount paid cannot be negative" | A minus sign | Enter the real amount |
| "class '…' was not found" | Class name does not match | Copy the exact class name from the class list |
| "Row … and row … both name …" | The same admission number appears twice | Keep one row per pupil; remove the duplicate |
| "admission number … belongs to …, but the row says …" | Admission number and names do not match; rows may be out of order | Check the row against the pupil record |
| "enter a surname or a middle name" | Both name fields are blank | Fill in whichever name the pupil record uses |
| "… is in … but the file says …" | Pupil's class is different from the file | Correct the class or the enrolment |
| "no fees are set for … this term" | Fee Schedule is empty for that class | Ask the administrator/bursar to complete the Fee Schedule |
| "already has … recorded … which is more than the file says" | A previous payment or import is higher than this file | Reverse the earlier import/payment first |
| "An opening-balance import for … was already posted" | One posted batch per term | Open the named batch and reverse it first |
| "This batch has already been posted or reversed" | You opened an old batch | Open the preview/current batch instead |
| All rows skipped | Missing Fee Schedule, wrong term, or wrong class names | Read the red errors; start with the first one |
| "Post" button missing | No valid rows | Fix the red errors and re-upload |

### 6.15 Before you press Post — checklist

- [ ] The term is the correct term.
- [ ] Every class in the file has a Fee Schedule.
- [ ] The pupils exist; the admission number, first name and one of middle/surname match.
- [ ] Student Type is right for every row.
- [ ] Scholarships are assigned and visible in **Due**.
- [ ] Amount Paid is the **total** collected, with no commas.
- [ ] The summary totals agree with your own control sheet.
- [ ] Every red error is understood; skipped rows are expected.
- [ ] No other posted batch exists for this term.
- [ ] You have kept the source CSV.

---

## Part 7 — Collecting money day to day

### 7.1 Cash at the office

1. **Finance → Payment Terminal**.
2. Search the pupil by name or admission number.
3. Open the pupil's invoice.
4. Check the **Balance to Pay**.
5. Enter the amount received.
6. Press **Confirm Cash Payment**.
7. The receipt opens. Print it or give the parent the receipt number.

**Rules:**

- Count the cash before you type the amount.
- Enter only what you received.
- If the parent is paying **ahead** of the bill, use **Advance Payments**
  instead of forcing an overpayment on the invoice.
- Never record a cash payment for money you have not counted.

### 7.2 Bank transfer — generate a slip

1. Open the pupil's invoice in **Payment Terminal**.
2. Press **Generate Payment Slip**.
3. Give the parent the slip code/reference and the school account details.
4. The payment stays **Pending** until the school verifies it.

### 7.3 Verify a transfer

1. **Finance → Verify Payments**.
2. Search by name, admission number, slip code, phone, invoice or amount.
3. Open the parent's proof if one was uploaded.
4. Match the payment in the school's bank app.
5. Type the **amount you saw in the bank**.
6. If the bank amount differs from the slip, tick **The bank shows a different
   amount from the slip** and enter the real amount.
7. Press **Approve payment**.

**Expected result:** the payment becomes confirmed, the invoice balance reduces,
and the parent can see the confirmed payment in the portal. A slip cannot be
confirmed against an invoice that is already fully paid — that slip is cancelled
and you see a message.

> **Do not approve from a screenshot alone.** The money must be visible in the
> school's bank account or Paystack balance.

### 7.4 Receipts and reprints

- A cash payment opens the **receipt** immediately.
- To reprint, go to **Reports → Payment Register**, find the payment
  and open the receipt.
- The receipt is the parent's proof. Never issue a handwritten receipt for a
  payment that is in Edus.

### 7.5 Unmatched transfers

**Finance → Unmatched Transfers** holds money that reached a family's dedicated
account but could not be matched to a bill.

1. Read the amount, date, sender and the parent/account.
2. Type the **invoice number** to apply it.
3. Press **Apply ₦…**.

A transfer larger than the bill is refused — place the correct part first, then
deal with the rest. **Mark for refund** only records the decision; the refund is
made at Paystack.

### 7.6 Advance Payments

Use this when a parent pays **before** the bill exists, or pays more than the
current bill.

1. **Finance → Advance Payments**.
2. Search the pupil by name or admission number and select the pupil.
3. Enter the **amount received**, the method, the date and what it covers (for
   example “3 sessions, 2026/2027–2028/2029”).
4. Write a clear **remark for the audit**.
5. Press **Record advance**.

The credit is applied to every invoice raised for that pupil afterwards. Do not
manually add the same money again when the new bill appears.

### 7.7 Online payments — what to tell a parent

- An **online school-fee payment** carries a flat **₦750** processing charge on
  each payment, whether it is a part payment or the full balance.
- The school still receives its **full school fee**. The charge covers Paystack's
  transfer cost and Edus handling; it is not deducted from the school's fee.
- The **application fee** (for a new application, before the form) is different:
  the school's form fee, plus Paystack's card cost, plus **₦350**.
- The school can switch the online charge off in Settings. If it is off, the
  parent pays only the invoice.
- Never add the ₦750 manually to a cash or office transfer. It belongs to online
  checkout only.

---

## Part 8 — Scholarships and discounts

1. **Finance → Discounts**.
2. **Manage Rules** to create, edit or delete a rule: name, percentage or fixed
   amount, value. A rule in use is protected from deletion.
3. **Scholarship Board** to assign it to a pupil.
4. Check **Bill effect**:
   - green `₦gross → ₦net` — applied to the open bill;
   - "No open bill" — it will apply to the next bill;
   - "Not applied yet" — revoke and re-assign.

**Rules:**

- Get the approved scholarship list from management before assigning.
- Confirm the pupil by **admission number**, not by name alone.
- A scholarship reduces **tuition** only unless the rule has **Apply to all fee
  items** ticked. A 100% rule with that box ticked waives every fee item.
- Assign scholarships **before** generating invoices or running Fees Import.
- To correct a wrong scholarship, revoke it and re-assign the correct rule; do
  not edit the invoice by hand.

---

## Part 9 — Reports the bursary uses

### 9.1 Daily

| Report | Use |
|---|---|
| **Reports → Daily Collections** | Total collected today, split by method |
| **Payment Register** | Every confirmed payment; reprint a receipt |
| **Verify Payments** | Clear the pending transfer queue |
| **Unmatched Transfers** | Place money that did not match a bill |

### 9.2 Weekly

| Report | Use |
|---|---|
| **Outstanding Fees** | Debtors list; print or download a family statement |
| **Collections by Class** | Which classes are paying, which are behind |
| **Class Fee Status** | One matrix of pupils × fee status for a class |
| **Fee Reports** | Term summary of billed, collected and outstanding |

### 9.3 Monthly / end of term

| Report | Use |
|---|---|
| **Reconciliation** | Compare Edus collections with the bank/Paystack |
| **Online Payments** | Online collections and payouts to the school |
| **Discount Report** | Every scholarship and its value |
| **Application Fees** | Application-fee income |
| **Expense Register** | All expenses in the period |
| **Income Statement / Income & Expenditure** | Income against expenditure for the period |

**How to run a report:** choose the date range or term, apply the filters, then
use the **CSV** or **PDF** button where offered. Keep the export with your
monthly accounts.

> **Outstanding Fees** is the list to use when chasing parents. Open a pupil's
> statement from the report or the pupil profile; it shows every invoice, payment
> and balance.

---

## Part 10 — Expenses and income & expenditure

1. **Finance → Expenses**.
2. Use **Manage Categories** if the category does not exist, then choose it (for
   example diesel, repairs, transport).
3. Enter the date, amount, payee and a clear description.
4. Press **Save Record** and keep the receipt.
5. **Finance → Income & Expenditure** for the period report.

The income statement counts **confirmed** payments only. A pending transfer is
not income yet.

---

## Part 11 — Promoting pupils

**Admissions → Promote Students**

1. Choose the current class/session.
2. Select the pupils to promote.
3. Choose the target class for the new session.
4. Mark any repeaters so they stay in the same class.
5. Generate the new session's invoices if that is the school's practice.

**Before promoting:** confirm the new session and terms exist, classes are
created, and the fee schedule for the new term is ready. Promotion is a
management decision; do not promote a whole class without the principal's list.

---

## Part 12 — Close of day

- [ ] Count the cash and agree it with the receipts in Edus.
- [ ] Clear **Verify Payments**; leave nothing pending that you have seen in the
  bank.
- [ ] Check **Unmatched Transfers**; place or note every item.
- [ ] Record today's expenses with their receipts.
- [ ] Confirm today's Fees Import (if any) is **Posted**, not left as a preview.
- [ ] File the source CSV and the batch number.
- [ ] Log out, especially on a shared computer.

**Close of term:**

- [ ] Every class has a complete Fee Schedule for the term.
- [ ] The opening-balance import is posted, or you have recorded why not.
- [ ] Scholarships are assigned and the Discount Report agrees.
- [ ] Outstanding Fees is exported for management.
- [ ] Payment Register and Daily Collections are exported for the accounts file.
- [ ] Any reversed batch has a written reason on file.

---

## Part 13 — Troubleshooting

| Problem | Likely cause | What to do |
|---|---|---|
| Parent not found by phone | Number typed with spaces or a country code in an unexpected way | Try the national digits, the last 4–5 digits, or the child's name |
| Parent title missing | Old record was created before ALH/HAJ/MAL | Edit the parent and choose the title |
| Merge panel will not save | Confirmation box not ticked | Tick **I confirm this is the same family**, then save |
| "We only join two parent records when a child shares a surname or middle name" | Shared phone, no name match | Correct the number; if genuinely one family, use **Add a pupil** |
| Merge refused because of virtual accounts | Both records have dedicated bank accounts | Use **Add a pupil** on the correct family |
| Child is under the wrong parent | Old admission or a data-entry error | **Add a pupil to this family** on the correct parent; parent confirms |
| Parent says they never added a child | A staff member moved the pupil | **Parents → Family updates → Reported by parents**; read the note and fix |
| Fees Import "Post" button missing | No valid rows | Read the red errors; fix and re-upload |
| "One posted batch per term" | A batch is already posted | Open the named batch; reverse it first |
| "No fees are set for this class this term" | Fee Schedule incomplete | Complete the Fee Schedule, then re-preview |
| Amount paid rejected | Comma, negative, or text | Write `37500` or `37500.00` |
| Row skipped because admission number belongs to another pupil | Wrong number or rows out of order | Check the pupil record and the row |
| Parent's balance looks wrong | Wrong Student Type, missing scholarship, or an earlier payment | Check the pupil, the invoice and the Fee Import batch before changing anything |
| Receipt needed again | Payment already confirmed | **Payment Register** → open the payment → receipt |
| Pending transfer not showing | Already confirmed, or wrong term/invoice | Check **Payment Register**; confirm you are on the right pupil |
| Page looks cramped on a phone | Wide table | Scroll the table inside its box; the page should not scroll sideways. If it does, report it |

**When to escalate to the administrator instead of fixing it yourself:**

- a payment is on the wrong pupil or the wrong invoice;
- a posted Fees Import batch must be reversed;
- a parent record has two dedicated bank accounts;
- the Fee Schedule is missing or wrong for a class;
- a scholarship must be changed after money has been posted;
- a parent disputes a family link and the evidence is unclear;
- you see a duplicate parent you cannot merge safely.

---

## Part 14 — Security, fraud and data quality

1. **Use your own login.** Every action is recorded against your name.
2. **Verify identity before changing a phone number.** The phone is the family
   login and the sibling key. Ask for the old number or an ID.
3. **Do not move a child between families on a verbal request alone.** Ask for
   written evidence or management approval.
4. **Do not confirm a transfer you have not seen** in the bank or Paystack.
5. **Do not share receipts or statements** with anyone who is not the parent or
   an authorised staff member.
6. **Do not delete or edit a posted payment.** Use the reversal/correction flow
   and record why.
7. **Keep the source files.** For every import, keep the CSV, the batch number
   and the rows CSV.
8. **Log out** on shared computers and at the end of the day.
9. **Do not install browser extensions or save the school password** on a shared
   machine.

---

## Part 15 — Quick reference

### 15.1 Where everything is

| Task | Path |
|---|---|
| Find a pupil | Admissions → **Students** |
| Find a parent / family | Admissions → **Parents** |
| Family notices | Parents → **Family updates** |
| Add a child to a family | Parents → **Manage family** → **Add a pupil to this family** |
| New pupils from CSV | Admissions → **Bulk Import** |
| Fees already collected | Finance → **Fees Import** |
| Fee amounts per class | Finance → **Fee Schedule** |
| Bill ledger / open an invoice | Finance → **Invoices** |
| Assign a scholarship | Finance → **Discounts** |
| Take cash / generate a slip | Finance → **Payment Terminal** |
| Verify a transfer | Finance → **Verify Payments** |
| Money with no bill | Finance → **Unmatched Transfers** |
| Parent paid ahead | Finance → **Advance Payments** |
| Receipt reprint | Reports → **Payment Register** |
| Debtors | Reports → **Outstanding Fees** |
| Today's totals | Reports → **Daily Collections** |
| Expenses | Finance → **Expenses** |
| Income vs expenditure | Finance → **Income & Expenditure** |

### 15.2 Fees Import CSV — the eleven columns

```
Admission Number,First Name,Middle Name,Surname,Class Name,Student Type,Amount Paid,Payment Date,Payment Method,Payment Reference,Note
24/001,John,,Doe,JSS 1A,NEW,37500,2026-09-15,CASH,RC-1042,First instalment collected at the office
24/002,Aisha,,Bello,JSS 1A,RETURNING,50000,2026-09-15,TRANSFER,TRX-9981,Full term paid
24/003,Halima,,Musa,JSS 1A,RETURNING,0,,,
```

> `Amount Paid` is the **total** collected for the term. The third row has paid
> nothing and will be posted as **UNPAID**.
>
> A pupil with no surname can leave **Surname** blank and fill **Middle Name**.
> Edus matches on the admission number, then First Name plus either Middle Name
> or Surname — the two are interchangeable.

### 15.3 Status meanings

| Status | Meaning |
|---|---|
| **Preview only** | The file has been read; nothing is posted |
| **Posted** | The valid rows created payments and invoices |
| **Reversed** | The batch was undone; nothing from it is posted |
| **PAID** | Invoice fully settled |
| **PARTIAL** | Some money received, balance remains |
| **UNPAID** | Nothing received |
| **Pending** | A transfer/slip is waiting for the bursar to verify |
| **Confirmed** | The bursar verified the money |
| **Advance** | Money held for the pupil's next bill |

### 15.4 One-page desk checklist

- [ ] Search the parent by phone before creating anything.
- [ ] Check the child's class and Student Type.
- [ ] Preview every Fees Import; never post unseen.
- [ ] One posted opening-balance batch per term.
- [ ] Assign scholarships before billing.
- [ ] Count cash; confirm only money seen in the bank.
- [ ] Give/print the receipt.
- [ ] Clear Verify Payments and Unmatched Transfers daily.
- [ ] Keep the CSV and the batch number.
- [ ] Log out.

---

## Part 16 — Training exercises (sign-off)

Do these on a **training tenant**. Do not post test data on the live school.

| # | Exercise | Expected result |
|---:|---|---|
| 1 | Search a parent using `0803…`, then `803…`, then the last 4 digits | The same parent appears each time |
| 2 | Add **ALH** to a test parent and save | The form saves; reopen Edit and **ALH** is still selected |
| 3 | Edit a parent's phone to a number held by another parent whose child shares a surname | The merge preview appears; tick confirm; children end up under one parent |
| 4 | Edit a parent's phone to a number held by an unrelated family | Nothing saves; the message names the other children |
| 5 | Add a pupil from another family to the correct parent | The pupil moves; the parent gets a notice |
| 6 | As the parent, report the notice with a note | The staff **Family updates → Reported by parents** shows the note |
| 7 | Set a pupil to **Returning** and save | The profile shows Returning |
| 8 | Download a class roster, fill three rows (part payment, full payment, zero), preview | The summary shows due, collected and outstanding; nothing is posted |
| 9 | Post the batch | Status Posted; the three invoices show PAID/PARTIAL/UNPAID correctly |
| 10 | Upload the same file again | The existing preview/batch is reused or reported as already posted; no duplicate payments |
| 11 | Trainer/super-admin reverses the batch (training only) | Payments removed; invoices restored; status Reversed |
| 12 | Type `37,500` in Amount Paid and preview | The row is rejected with the thousands-separator message |
| 13 | Assign a 50% scholarship, then import that pupil | Due is reduced; the preview shows the scholarship label |
| 14 | Take a cash part payment | Receipt opens; the invoice becomes PARTIAL |
| 15 | Verify a pending transfer with a different bank amount | The confirmed amount is the bank amount; the invoice balance updates |
| 16 | Run **Daily Collections** and **Payment Register** for today | The day's payments appear; a receipt can be reprinted |
| 17 | Export **Outstanding Fees** | The debtor list downloads and agrees with the pupil balances |
| 18 | Tick **Apply to grand total** on the Scholarship Board for one pupil on a 100% tuition-only rule | That pupil's bill becomes **₦0** and the status becomes PAID; another pupil on the same rule keeps their levies; **REVOKE** restores the gross bill |
| 19 | Edit a rule's value from 10% to 20% | Every open bill using it re-prices immediately; a bill with money already paid does not change |
| 20 | Create a temporary unused rule, then delete it; try to delete an assigned rule | The unused rule is deleted; the assigned rule is refused with a “revoke it from those pupils first” message |

**Sign-off**

| Name | Role | Modules completed | Trainer | Date |
|---|---|---|---|---|
|  |  |  |  |  |
|  |  |  |  |  |
|  |  |  |  |  |

---

*End of playbook. Keep the source CSV of every import and the batch number with
your term records.*
