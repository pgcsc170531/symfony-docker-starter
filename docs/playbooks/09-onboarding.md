# 09 — Onboarding (the first hour of a new school)

**Actor:** the school's administrator, watched (and later documented) by the agent.
**Why its own file:** onboarding is the only flow that runs **once** and cannot be
repeated on the same school. If it is wrong, every later role inherits the damage.

**Ask the platform owner for a fresh school** (see `01-landlord.md` steps 4–5) — never
run this on `demo`, its wizard is already complete.

---

## Part A — Agent playwright

| # | Do | Expect |
|---|---|---|
| 1 | Open the new school's address and sign in with the principal account | Redirected into onboarding, not the dashboard |
| 2 | Submit **Identity** (`/onboarding/identity`) with the name empty | Validation error; nothing saved |
| 3 | Fill identity: name, motto, institution type, brand colour, email, phone, address, logo | Advances to class structure |
| 4 | **Structure** (`/onboarding/structure`): the suggested levels arrive **pre-checked** with arms `A, B, C` | 18 classes, named `JSS 1 A` … `SS 3 C` (a space before the arm) |
| 4b | Clear every level and submit | Refused with "Select at least one class level to continue" (a school with no classes cannot bill or register) |
| 4c | Re-visit the structure step once classes exist | Offers **Keep existing** / **Wipe & regenerate**; wipe is refused while pupils are enrolled |
| 5 | **Calendar** (`/onboarding/calendar`): session `2026/2027`, First Term, resumption and vacation dates | Term saved and active |
| 6 | **Fee items** (`/onboarding/fees/items`): keep tuition + PTA, mark them compulsory | Items saved with their frequency and target |
| 7 | **Fee prices** (`/onboarding/fees/prices`): amount per class, returning and new intake | Matrix filled; the summary shows those amounts |
| 8 | Finish (`/onboarding/summary`) | "Setup Complete!" with the master fee schedule, and **Confirm Fees & Import Students** |
| 9 | Re-open `/onboarding/identity` after finishing | Loads with the saved name — it does not restart or wipe |
| 10 | Open `/dashboard` **before any pupil exists** | **Redirects to onboarding — by design:** a school with no pupils gets the wizard, not a dashboard of zeros. It stops the moment the first pupil exists |
| 11 | Import pupils (`02-tenant-admin.md` step 18), then open `/dashboard` | Real dashboard, school name in the header, active term |
| 12 | Public site `/` on the subdomain | School name as the `<h1>`, motto present, Apply reaches `/apply` |

### Assertions

- **No step can be skipped by URL.** Navigating straight to `/onboarding/fees/prices`
  before identity redirects back to identity.
- The fee prices matrix and the class list agree — a class missing from the price matrix
  will bill ₦0 and look like a bug later.
- The active term is unambiguous: `/dashboard` shows one term, and only one row in the
  terms list is active.

---

## Part B — User handout

### Before you start

You need from the platform owner: your school's **web address**, the **principal
email** and a **starting password**.

Have ready: the school name and motto exactly as they should print, a logo file, the
phone and email parents use, the physical address, your **bank name / account number /
account name**, your class structure, and your term fee per class.

### The six steps

1. **Identity.** Name, motto, type of school, your brand colour, contact details, logo.
   This is what appears on every invoice and receipt — misprints here are on every
   document until you change them.
2. **Class structure.** Tick the levels you run, then type the arms separated by commas
   (`A,B,C`). The system builds `JSS 1A`, `JSS 1B`, and so on.
3. **Academic calendar.** Session, term, resumption and vacation dates. Only one term is
   active; the dashboard and every invoice follow it.
4. **Fee items.** What you charge for: tuition, exam, PTA, and anything else.
5. **Fee prices.** The amount per class, split between returning pupils and new intake.
   Take your time here: this matrix is the bill.
6. **Finish.** You land on your master fee schedule. Read it before you admit anyone.

### The five things to do immediately after

1. **Settings → General**: add your **bank account** — it prints on every slip and
   statement.
2. **Grading scale**: bands from 0 to 100, no gaps, plus the remark for each.
3. **Staff**: create a login for each member of staff.
4. **Staff assignments**: one form teacher per class, one HOD per department, one year
   group master per year group, and a subject teacher for every class × subject.
5. **Students**: import your existing roll or admit your first families.

### Recovering from a wrong start

The wizard cannot be re-run, and that is deliberate — re-running it would overwrite
classes your pupils are already enrolled in. Everything it creates is editable
afterwards:

| Wrong | Fix it in |
|---|---|
| School name, motto, contacts, logo, brand colour | **Settings → General** |
| A class name or a missing arm | **Classes** |
| Term dates | **Academic → Sessions & Terms** |
| A fee amount | **Fee structure**, then check the **Fee Schedule** |
| Everything at once | Ask the platform owner before deleting data — deleting a class that has pupils is not reversible |

### When it goes wrong

| What you see | What to do |
|---|---|
| The wizard sends you back to step 1 | A required field on an earlier step is missing; the form shows which |
| A class you need is missing | Add it in **Classes** — the wizard is not the only way to create one |
| Fees show ₦0 on the schedule | The class was never priced in step 5; add it in **Fee structure** |
| The public site shows no logo | The logo upload failed; re-save it in **Settings → General** |

---

### Run 2026-10-19 — onboarding — agent (school `stmarys`)

Principal `principal@stmarys.ng` · viewports 1024 + 288.

| Step | Result | Note |
|---|---|---|
| 1 Identity page | **pass** | Redirected from login to `/onboarding/identity` |
| 3 Identity submit | **pass** | Saved, advanced to structure |
| 4 Structure | **pass** | Levels were **pre-checked** (all six) with arms `A, B, C`; generated 18 classes `JSS 1 A` … `SS 3 C` |
| 5 Calendar | **pass** | Flash “Calendar updated! Now define your Fee Items.”; term active |
| 6 Fee items | **pass** | Tuition + PTA pre-selected; “Fee Configuration Updated!” |
| 7 Fee prices | **pass** | 18 cell values written (₦55,000 tuition, ₦5,000 PTA); “Fees updated successfully!” |
| 8 Summary | **pass** | “Setup Complete!” with the fee schedule and **Confirm Fees & Import Students** |
| 9 Identity replay | **pass** | Reloads with the saved name; nothing wiped |
| 10 `/dashboard` with 0 pupils | **pass (by design)** | Redirects to `/onboarding/identity` — documented in `DashboardController`; stops when the first pupil exists |
| 12 Public site | **pass** | `<h1>` “St Mary's College”, motto present, Apply → `/apply` |

**Defect found and fixed during this run — P-ONBOARD-01 (Minor).** Submitting the
structure step with **no level ticked** flashed *"Success! 0 classrooms generated"* and
moved to the calendar, leaving a school that cannot bill or register. `OnboardingController::step2`
now refuses an empty selection with *"Select at least one class level to continue."*
(Note: with classes already present the form offers **Keep existing** / **Wipe & regenerate**,
so the guard only fires on the generate path.)

**Observation, not a defect:** the wizard names classes with a space (`JSS 1 A`) while the
CSV template examples use `JSS 1A`. The importer normalises both sides, so an import works
either way — recorded so nobody "fixes" the importer later.

