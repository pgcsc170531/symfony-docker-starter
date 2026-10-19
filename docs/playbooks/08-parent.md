# 08 — Parent / guardian

**Actor:** a parent or guardian. Applies for a child, keeps a child at the school,
pays fees and reads results. **The only role that is always on a phone — run the entire
file at 288 CSS px.**
**Where:** the school's subdomain, public site and `/parent`.

---

## Part A — Agent playwright

Two accounts are needed: one who already has a child (`7058813998@edus.ng` /
`12345678` on demo) and one that applies fresh through `/apply`.

| # | Do | Expect |
|---|---|---|
| 1 | Open the school's home page `/` | 200; school name, one `<h1>`, Apply button full-width on a phone |
| 2 | `/apply` — start an application | 200 even when admissions are closed; the closed state is explained, Apply still works |
| 3 | Register / sign in within the application, verify the email | Application creates a **guardian account**, not a duplicate per child |
| 4 | Complete guardian + child details, submit | Application appears on the dashboard with a status |
| 5 | Sign in as the existing parent, open `/parent/dashboard` | Each child with a balance; notices; four tabs only |
| 6 | `/parent/fees` then a child's finance screen | Invoices, receipts, and **Download statement (PDF)** |
| 7 | Add a second child to the same parent | Both children on the dashboard; one guardian record |
| 8 | Upload proof of payment for an invoice | Payment shows `PENDING`; the balance does **not** change |
| 9 | Download the statement PDF | A4, opens on the phone, carried invoice number matches the screen |
| 10 | Open the QR's verification URL from the PDF footer | 200; confirms issuer and period; shows **no** amounts |
| 11 | `/reports/student` | The released report card; before release, a clear "not yet available" state |
| 12 | `/parent/calendar/{year}/{month}` | Events for the month |
| 13 | Change the URL to another family's child (`/parent/student/{other}/finance`) | **403** |
| 14 | Change the invoice id (`/finance/invoice/{other-id}`) | **403** |
| 15 | Change the statement id (`/parent/student/{other}/statement.pdf`) | **403** |
| 16 | Sign out on a phone | Reachable from the header; no dead end |

### Assertions

- **Privacy first.** Steps 13–15 are the most important in the file. A parent reading
  another family's invoice is the worst defect this product can ship.
- The parent never sees staff navigation, never sees the landlord area, and the four
  bottom tabs are the only destinations.
- Money words are consistent: `Pending` vs `Paid` means the same thing here as on the
  bursar's screen.
- At 288: no horizontal scroll anywhere, `Download statement` is full width and ≥44px,
  inputs ≥16px.

---

### Run 2026-10-19 — parent — school `stmarys` (1,200 pupils)

Parent `7058813998@edus.ng` (**Mrs. Hauwa Bello**) · children: Arraghibun Bello (26/EEF9C).
The fresh-application half of this playbook was covered in `09-onboarding.md`.

| Step | Result | Note |
|---|---|---|
| 1 School home page `/` | **pass** | Renders on the school's own subdomain |
| 5 `/parent/dashboard` | **pass** | Each child with a balance, notices, the four parent destinations only — no staff navigation anywhere |
| 6 `/parent/fees` → child finance | **pass** | Invoices, receipts and *Download statement (PDF)*; the store purchase appears as its own invoice (`INV-2026-1201`, ₦1,600) and does not touch the fee bill |
| 9 Statement PDF | **pass** | 200, `%PDF-`, A4 **595.28 × 841.89**, 45 KB |
| 11 `/reports/student` → report sheet | **pass, with a finding** | FIRST TERM REPORT SHEET for Arraghibun: five subjects, CA columns, total, grade, position, attendance. It is available **before** the class is fully approved — folded into **P-YGM-02** |
| 12 `/parent/calendar/2026/10` | **pass** | October 2026 grid with navigation |
| 13 Another family's child (`/parent/student/529/finance`) | **pass** | **403** *“You are not authorized to view this student.”* |
| 14 Another family's invoice (`/finance/invoice/529`) | **pass** | **403** *“This invoice belongs to another family.”* |
| 15 Another family's statement (`/parent/student/529/statement.pdf`) | **pass** | **403** — no PDF produced |
| 16 Mobile pass | **pass** | At 288 CSS px (true 360): **0 px** horizontal overflow, every link and button ≥ 44 px |

**Privacy: all three cross-family probes refused with a sentence, not a stack trace.**

---

## Part B — User handout

### Signing in

Use the address your school gave you, plus the email or phone-based username and password
the school issued. If you never received one, ask the school office — they create it; it
is not self-service for existing pupils.

### Your dashboard

You see each child at your school with:

- their **outstanding balance**,
- any notice the school has flagged as urgent,
- the **last three applications** you made.

On a phone there are four tabs: **Home**, **Applications**, **Results**, **Fees**.
There is nowhere else to go, and that is deliberate.

### Paying fees

1. Open **Fees** (or tap a child on the dashboard).
2. You see each invoice with its status and the balance still due.
3. If the school accepts online payment, a **Pay online** button appears. If it does
   not, use the school's bank details from the payment slip and upload your proof.
4. After uploading a transfer, the payment shows **Pending** — the money has not been
   confirmed yet. It clears once the bursar checks the school's bank account.
5. Download **the statement (PDF)** whenever you need proof for a bank, an employer or a
   relative helping with fees. It covers the whole account, not just one term.

### Results

Open **Results**. You see the report card once the school has released it for the term.
Before that, you will see nothing rather than a half-finished card — that is on purpose.

### Applying for another child

1. **Applications** → start a new application.
2. Your details are already there; add the child's details.
3. Pay the application fee — online, or by transfer with proof.
4. Track the status on the same screen. You do not need a second account for a second
   child.

### What the school can and cannot see

You see your own children only. If you change the web address to try to open another
family's child, invoice or statement, the system refuses — and the same is true in
reverse: no other parent can open yours.

### When it goes wrong

| What you see | What to do |
|---|---|
| "No child is linked to your account" | Your child's record is not linked to your login. Call the school office — you cannot fix it yourself |
| You forgot your password | Use the reset link on the sign-in page. It goes to the email on your record |
| A payment still says Pending after a week | The school has not confirmed it against their bank statement. Send them your transfer receipt |
| The balance looks wrong | Download the statement PDF and compare it with your receipts; then take it to the bursar — do not pay "the difference" without checking |
| You cannot see the report card | The school has not released the term's results yet |
