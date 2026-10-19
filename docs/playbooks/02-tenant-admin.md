# 02 — School administrator (tenant admin)

**Actor:** the school's own administrator. Builds the school inside Edus, then hands
day-to-day money work to the bursar.
**Where:** the school's subdomain (`demo.localhost:8082`) · desktop 1024 + phone 288.

Run `09-onboarding.md` first — this file assumes the wizard is done.

---

## Part A — Agent playwright

| # | Do | Expect |
|---|---|---|
| 1 | `/dashboard` | 200; school name in the header, **not** "Edus"; the active term shown |
| 2 | `/settings/general` — change the motto, save | Flash "saved"; the motto appears on the public site `/` |
| 3 | `/settings/general` — set bank name / account number / account name | Saves; the values appear on a payment slip (checked in `03-bursar`) |
| 4 | `/academic/` — create a session and terms | Session listed; exactly one term is active |
| 5 | `/classrooms/` — create `JSS 1`–`SS 3` with arms `A,B,C` | 18 classes; no duplicate names |
| 6 | `/year-groups/` — create JSS 1, JSS 2, JSS 3, SS 1, SS 2, SS 3 | Each shows its arms |
| 7 | `/departments/` then `/subjects/` | Subjects carry a department |
| 8 | `/class-subjects/` — assign every subject to every class for the session | A class with no subjects hides those subjects from teachers (by design) |
| 9 | `/grading-scale/` | Bands 0–100, no gaps, no overlaps |
| 10 | `/settings/behaviour-traits` | Traits ordered; move arrows work |
| 11 | `/staff/` — create one account per role: teacher, form teacher, HOD, year master, bursar | Login works for each; duplicate email is refused |
| 12 | `/staff-assignments/subject-teacher` — assign a teacher to a class × subject | Assignment listed |
| 13 | `/staff-assignments/form-teacher` — one per class | One per class only |
| 14 | `/staff-assignments/hod` — one per department | One per department only |
| 15 | `/staff-assignments/year-group-master` — one per year group | One per year group only |
| 16 | `/finance/items/` and `/finance/structure/` — set term fees per class | Amounts match the school's fee schedule |
| 17 | `/finance/fees/schedule` | The matrix reads correctly for the active term |
| 18 | `/quick-enroll/` — upload the 1,200-pupil CSV (see `DEMO-DATA-1200.md`) with **Generate bills** ticked | Import report: accepted rows = file rows, 0 rejected; `/students/` shows ~1,200 |
| 19 | `/students/{id}/profile` | One invoice, sequential number `INV-2026-0001`+, balance = billed |
| 20 | `/students/?finance_status=owing` | The filter returns only pupils with a balance |
| 21 | `/enrollment/promote` — load a class, promote, run it again | Second run does not duplicate invoices |
| 22 | `/store/products/` — add one product | Listed with stock |
| 23 | `/finance/expenses/` — record one expense against a category | Appears in the register with the right category |
| 24 | `/finance/reports`, `/debtors`, `/income` | Billed = collected + outstanding on every screen |
| 25 | `/subscription/` — register the term with the declared roll | Invoice raised for the declared number |

### Role-boundary checks (expected 403)

Open each of these **as a bursar-only account** and as a **teacher-only account** and
record the status:

- `/settings/general`, `/staff/`, `/finance/reports/income` — bursar: reports yes,
  settings/staff no.
- `/tenant/results/entry` — admin may be refused unless also a teacher; record which.
- `/students/{id}` for a pupil in another school's tenant — impossible by host, confirm
  by trying a foreign id.

### Data checks worth making explicitly

- Import 1,200 rows → `SELECT COUNT(*)` on students equals the accepted count.
- Every invoice number is `INV-<year>-<4 digits>`, unique, ascending in creation order.
- A pupil with a scholarship shows billed = gross − discount, on the invoice **and** on
  the debtors report.

---

### Run 2026-10-19 — admin — school `stmarys` (1,200 pupils)

| Step | Result | Note |
|---|---|---|
| 11 Staff accounts | **pass** | 5 created (teacher, form teacher, HOD, year master, bursar) + principal. Role list is only 4 (`ADMIN`/`BURSAR`/`STORE`/`TEACHER`) — the four approval roles come from **assignments**, as designed |
| 7 Departments | **pass** | Sciences, Languages, Humanities (inline form, no CSRF token — see B-07) |
| 8 Subjects | **pass** | Mathematics, English Language, Basic Science, Social Studies, Computer Studies |
| 6 Year groups | **pass** | JSS 1 – SS 3 |
| Class subjects | **pass** | 5 subjects assigned to JSS 1 A for 2026/2027 |
| 12–15 Assignments | **pass** | Form teacher → JSS 1 A · HOD → Sciences · Year master → JSS 1 · Subject teacher → Mathematics × JSS 1 A. **P-ADMIN-05** fixed on the way: an unresolvable staff value used to fail silently |
| 18 Import | **pass after 2 fixes** | 1,200 pupils; P-ADMIN-01 (sibling guardians) and P-ADMIN-02 (memory) fixed, then re-run |
| 21 Dashboard | **pass after 1 fix** | P-ADMIN-03: “Across 5 students” → real count “Across 1200 students” |

**Harness note (not a product defect):** the staff picker is an Alpine search box; its
hidden field posts a **user id**. Driving it from Playwright needed a real click on the
suggestion, not a value set on the hidden input. A human clicking the name is unaffected.

**Open, deferred by the owner:** P-ADMIN-04 — schools cannot set their own
admission-number pattern; the import issues `26/6EDD7` while the application engine
issues `ADM/2026/0001`.

---

## Part B — User handout
### Setting your school up

You only do this once. Work top to bottom — each step depends on the one above it.

1. **Sign in** at your school's address with the email the platform owner gave you.
2. **Settings → General**: enter the school name, motto, phone, email, address, logo,
   and — importantly — your **bank name, account number and account name**. That
   account is what parents will be told to pay into, on every slip and statement.
3. **Academic → Sessions & Terms**: create the session, add the three terms, and activate
   the one you are in. Only one term is active at a time.
4. **Classes**: create each level with its arms (`JSS 1` + `A,B,C` gives JSS 1A, 1B, 1C).
5. **Year groups, departments, subjects**: a year group gathers the arms; a subject
   belongs to a department.
6. **Class subjects**: for this session, list which subjects each class actually takes.
   A class with no subjects will not appear to teachers.
7. **Grading scale**: bands from 0 to 100 with no gaps, and the remark for each band.
8. **Staff**: create a login for every member of staff, one role each.
9. **Staff assignments**: one form teacher per class, one HOD per department, one year
   group master per year group, and a subject teacher for each class × subject.
   **Nothing works until these are done** — a teacher with no assignment sees an empty
   screen, which is correct behaviour, not a fault.
10. **Fee items and fee structure**: what you charge, and how much per class per term.
    Check the **Fee Schedule** page before billing: it is the matrix every invoice is
    built from.
11. **Students**: use **Quick Enrolment** for a spreadsheet of existing pupils, and
    **New Admission** for one family at a time. For a spreadsheet, tick **Generate bills**
    so each pupil is invoiced immediately.

### Your monthly routine

- **Every day**: check **Finance → Reports → Daily collections** against the cash box.
- **Every week**: open **Outstanding fees**, work down the list from the largest balance.
  Each row is a family, with a phone number; use the WhatsApp button to draft a message.
  Nothing is sent automatically — you press send.
- **End of term**: confirm every pending transfer, then read the **Term summary PDF**
  before it goes to the proprietor. Unverified proofs are not income and the summary
  will say so.

### What you should never do

- **Never edit a pupil's class instead of promoting them.** Promotion keeps the history;
  editing the class field destroys it.
- **Never take a payment outside the terminal.** A payment recorded by hand in a
  spreadsheet does not exist to the system, and the debtors list will chase the family.
- **Never admit a pupil whose application fee is unconfirmed.** Admission creates the
  invoice; an unconfirmed promise leaves a pupil on roll with nobody chasing the money.

### When it goes wrong

| What you see | What to do |
|---|---|
| A teacher says "nothing is assigned to me" | Check **Staff assignments** — the assignment step is missing |
| An import rejects rows | Open the import report; fix only the rejected rows and re-upload those |
| A balance looks wrong | Open the pupil's **Finance** and the **Statement**: billed − paid must equal the balance, and store purchases are a separate ledger |
| A parent cannot sign in | Reset from the guardian's record in the pupil profile, not from the parent's account |
