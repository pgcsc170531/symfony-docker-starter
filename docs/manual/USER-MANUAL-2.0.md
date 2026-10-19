# Edus User Manual 2.0 — design & content plan

> **Status:** working document · 19 Oct 2026
> **Supersedes:** `edus-official-user-manual.md` (v1.0) and the manual chapter list
> inside `templates/landlord/home/manual.html.twig`.
> **Why this file exists:** v1.0 was written before the school portal grew its
> admissions engine, applications inbox, behaviour traits, year groups,
> departments, class subjects, subject teachers, mass promotion, subscriptions,
> parent applications, public school website and online payments. The rendered
> `/manual` page still teaches a smaller product. This file defines the manual
> that matches what is actually shipped, and doubles as the script a tester
> follows when walking the system as a school.

---

## 1. How this document is used

1. **As a design:** each chapter below states its audience, entry point (route),
   the steps, the expected result and the screenshot to capture.
2. **As a test script:** every chapter ends with **Test checkpoints**. A tester
   signs in as the stated role and works the chapter top to bottom. If a step
   does not produce the expected result, that is a defect — record it; do not
   work around it silently.
3. **As the source for the rendered manual:** once the content is agreed, the
   chapter text populates `templates/landlord/home/manual.html.twig` (web) and,
   optionally, an A4 PDF through the shared PDF pipeline. The Markdown is the
   single source; the HTML and PDF are outputs.

**Rule:** a screenshot is evidence, not decoration. Every figure in a screenshot
must come from a demo tenant that is safe to publish (no real parent names,
phones or balances).

---

## 2. Who the manual is for

| Actor | Role | What they need from the manual | What breaks if they cannot |
|---|---|---|---|
| **Proprietor / Principal** | `ROLE_ADMIN` | Set the school up, see the whole picture, approve results, read the money | Nothing else can start |
| **Bursar** | `ROLE_BURSAR` | Enrol, invoice, take and verify money, chase balances | The school cannot collect |
| **Store keeper** | `ROLE_STORE` | Stock, sell, charge a student or take a walk-in sale | Stock and sales become paper again |
| **Teacher** | `ROLE_TEACHER` | Own class, score entry, submit for approval | There are no results |
| **Form teacher / HOD / Year-group master** | `ROLE_TEACHER` + assignment | Confirm and approve in the right order | Results never get released |
| **Parent** | `ROLE_PARENT` | Apply, pay, read results, see notices | The whole chain produces no value |
| **Super admin / agent** | landlord firewall | Create schools, verify subscription payments | Out of scope for the main manual; appendix only |

The manual is written in the **second person** ("Click **Save**") and assumes no
database or command-line knowledge. A chapter never says "ask support to do it"
unless it is genuinely a support action (server setup, SMS provider).

---

## 3. Coverage map — what must be in v2.0

Legend: **v1** = present in the old manual · **NEW** = shipped after v1.0 and
missing from the old text · **REP** = planned reporting section
(`docs/payments/FEES-REPORTING-DESIGN.md`).

| Area | Screen / route | v1? | Chapter |
|---|---|---|---|
| Login, logout, verification | `/login` | ✅ | 1 |
| First-run onboarding wizard (6 steps) | `/onboarding` | ✅ | 1 |
| Dashboard | `/dashboard` | ✅ | 2 |
| School identity, logo, brand, public site content | `/settings/general`, `/dashboard/settings` | partial | 3 |
| Public school website (home/about/academics/admissions/events/contact) | `school subdomain /` | ❌ **NEW** | 3 |
| Sessions & terms | `/academic` | ✅ | 4 |
| Classes + arms (smart creator) | `/classrooms` | ✅ | 4 |
| Year groups | `/year-groups` | ❌ **NEW** | 4 |
| Departments + HOD span | `/departments` | ❌ **NEW** | 4 |
| Subjects | `/subjects` | partial | 4 |
| Class subjects (core/elective) | `/class-subjects` | partial | 4 |
| Grading scale | `/grading-scale` | ✅ | 4 |
| Behaviour traits | `/behaviour-traits` | ❌ **NEW** | 4 |
| Staff accounts | `/staff` | ✅ | 5 |
| Form teacher / HOD / Year-group master / Subject teacher | assignment screens | partial | 5 |
| Applications inbox (public `/apply`) | `/applications` | ❌ **NEW** | 6 |
| New admission (parent-first, siblings) | `/admission` | ✅ | 6 |
| Bulk import (CSV / grid) | `/quick-enroll` | ✅ | 6 |
| Student directory, filters, export, ID cards | `/students` | ✅ | 7 |
| Student profile & finance ledger | `/students/{id}` | ✅ | 7 |
| Mass promotion | `/enrollment/promote` | ❌ **NEW** | 7 |
| Fee schedule | `/finance/fee/schedule` | ✅ | 8 |
| Fee items & structures | `/finance/fee-items`, `/finance/fee-structures` | ✅ | 8 |
| Academic invoices | `/finance/invoices/academic` | ✅ | 8 |
| Payment terminal, cash, transfer slip, verify | `/finance/payment/*` | ✅ | 8 |
| Receipts (including walk-in) | `/finance/payment/receipt/{id}` | ✅ | 8 |
| Payment history | `/finance/payment/history` | ✅ | 8 |
| Scholarships / discounts | `/finance/discounts` | ✅ | 8 |
| Parent fees & online payment | `/parent/fees` | partial | 8, 14 |
| Store inventory (CSV) | `/store/products` | ✅ | 9 |
| Store POS (student charge / walk-in) | `/store` | ✅ | 9 |
| Store sales ledger | `/store/invoices` | ✅ | 9 |
| Expenses + categories | `/finance/expenses` | ✅ | 10 |
| Profit & loss | `/finance/report/profit-loss` | ✅ | 10 |
| SMS wallet & top-up proof | `/wallet` | ❌ **NEW** | 11 |
| School calendar & events (import, notify) | `/events` | ❌ **NEW** | 11 |
| Result entry (CA1–3, theory, practical, import) | `/results/entry` | ✅ | 12 |
| 4-stage approvals + unlock | `/results/approvals` | ✅ | 12 |
| Reports hub, broadsheets, HOD masters, analytics | `/reports` | partial | 12 |
| Report cards, transcripts, sharing/verification | `/reports/*` | partial | 12 |
| Attendance register & changes | `/attendance` | ❌ **NEW** | 13 |
| Behaviour ratings | `/behaviour` | ❌ **NEW** | 13 |
| Parent dashboard | `/parent` | ✅ | 14 |
| Parent applications | `/parent/applications` | ❌ **NEW** | 14 |
| Parent results | `/parent/results` | ✅ | 14 |
| School subscription (the school's own Edus bill) | `/subscription` | ❌ **NEW** | 15 |
| Outstanding fees / debtors / statements / day book | planned | ❌ **REP** | 15 |

**The v2.0 promise:** every row above has a numbered chapter, a screenshot and at
least one test checkpoint. No screen ships undocumented.

---

## 4. Chapter plan (content to write)

### Chapter 1 — Getting started
**Who:** all staff · **Where:** `<school>.edus.ng/login`

1. Open the school's own address (not `edus.ng`).
2. Enter email and password; click **Log in**.
3. First login only: the six-step wizard.
   - 1 Identity (name, motto, type, brand colour, contact, logo)
   - 2 Class structure (levels + arms → generated classes)
   - 3 Academic calendar (session, term, resumption, vacation)
   - 4 Fee items (frequency, target)
   - 5 Fee prices (returning vs new intake)
   - 6 Finish → dashboard
4. If the wizard was already completed, go straight to the dashboard.
5. Logout from the header; on a phone use the drawer footer.

**Expected:** the school name appears in the header; the sidebar matches the
signed-in role; the active term is shown on the dashboard.

**Screenshot:** `manual/01-login.png`, `manual/01-wizard-identity.png`.

**Test checkpoints**
- Wrong password shows an error, never a 500.
- The wizard cannot be finished with an empty school name or no active term.
- After finishing, `/dashboard` shows the school name, not "Edus".

---

### Chapter 2 — Dashboard and finding your way
**Who:** all staff · **Where:** `/dashboard`

- Explain the five numbers: students, invoiced, collected, outstanding, today's
  intake (plus low stock and SMS balance where present).
- Explain the sidebar groups (Admissions, Administration, Teaching, Finance,
  Store, System) and the filter box.
- Explain the parent experience: bottom tab bar with four places only.
- Point to **Settings** and **My Wallet** as the two system screens.

**Test checkpoints:** every sidebar row opens a page (no dead `#`); the filter
finds "Fee Schedule" after typing `fee`; the current role never sees a menu group
it cannot use.

---

### Chapter 3 — School identity and the public website
**Who:** admin · **Where:** `/settings/general` and the school's public home page

1. Set name, motto, telephone, email, address, logo, hero image, colours.
2. Add founded year, facilities (one per line) and core values
   (`Title: what it means` per line).
3. Open the public site: `/`, `/about`, `/academics`, `/admissions`, `/events`,
   `/contact`.
4. Show the admissions status band: open / opens later / closed, with call and
   WhatsApp always available.
5. Explain that **Apply** always goes to `/apply`, even when the portal is closed.

**Expected:** saving shows "School website settings saved successfully."; the
public pages reflect the saved content immediately.

**Test checkpoints:** a school with no logo shows its initial, not a broken
image; the public site has exactly one `<h1>`; `/apply` never redirects to
`/admissions`.

---

### Chapter 4 — Academic structure
**Who:** admin · **Where:** Administration group

Sub-sections, in dependency order:

1. **Sessions & terms** — create, activate exactly one term.
2. **Classes** — smart creator (`JSS 1` + arms `A,B` → `JSS 1A`, `JSS 1B`).
3. **Year groups** — group arms of a level.
4. **Departments** — create, then assign a HOD.
5. **Subjects** — name, code, department.
6. **Class subjects** — per class, per session, core or elective.
7. **Grading scale** — ranges, points, remarks.
8. **Behaviour traits** — the traits form teachers rate.

**Test checkpoints:** starting a new term deactivates the previous one; a class
without subjects hides the subject from teachers; a grading scale with a gap
between bands is rejected or warned.

---

### Chapter 5 — Staff and academic assignments
**Who:** admin · **Where:** `/staff` plus the four assignment screens

1. Create a staff login (name, email, role).
2. Assign **Form Teacher** (one per class), **HOD** (one per department),
   **Year Group Master** (one per year group), **Subject Teacher** (class ×
   subject).
3. Explain that a teacher only sees their own classes in **My Class** and
   **Result Entry**.

**Test checkpoints:** creating a duplicate staff email is blocked; a teacher
without an assignment sees an empty-state explanation, not an error.

---

### Chapter 6 — Admissions: from an application to a pupil
**Who:** bursar/admin · **Where:** `/apply` (public), `/applications`, `/admission`, `/quick-enroll`

Two paths, and the manual must say which to use when:

**Path A — a parent applies online**
1. Parent opens `/apply`, fills guardian + child details, pays the application
   fee (online or offline), submits.
2. School opens **Applications**, filters by status, opens the application.
3. Review, then **Admit**: the engine creates the student, enrolment, invoice and
   (optionally) sends SMS.
4. If requested, print the admission letter.

**Path B — a walk-in or bulk intake**
1. **New Admission**: search the parent by phone first (leading `0` dropped),
   then create or reuse the guardian, then register the child.
2. **Bulk Import**: download the CSV template, fill the 12 columns, choose the
   term, tick *Generate bills*, upload.
3. Read the import report; fix rejected rows and re-upload only those.

**Test checkpoints:** siblings link to the same guardian; a duplicate admission
number is rejected; an import row with `DOB` in `1/1/2015` is rejected with a
readable message; **Admit** generates exactly one invoice.

---

### Chapter 7 — Student records and promotion
**Who:** bursar/admin · **Where:** `/students`, `/enrollment/promote`

1. Search and filter (class, debtor/cleared, academic vs store debt).
2. Open a profile: guardian, siblings, ledger, ID card print.
3. Export the directory to CSV.
4. **Promote**: select pupils, choose the target class, mark repeaters, generate
   the new session's invoices.

**Test checkpoints:** the export contains exactly the filtered rows; promotion is
idempotent (running twice does not duplicate invoices); a repeater stays in the
same class.

---

### Chapter 8 — Fees: billing, collection and receipts
**Who:** bursar · **Where:** Finance group

1. **Fee items** (tuition, PTA, books) → **Fee structures** (amount per class per
   term, optional flags).
2. **Fee schedule** — confirm the matrix before invoicing.
3. Generate an academic invoice for one pupil, then in bulk after an import.
4. Take **cash**; issue a **bank-transfer slip**; verify the parent's reference.
5. Explain the proof upload and the pending → confirmed transition.
6. Print a receipt; reprint from **Payment History**.
7. Assign a **scholarship** and show the discount on the invoice.
8. Point to the reporting section (Chapter 15) for balances and defaulters.

**Test checkpoints:** a payment cannot exceed the balance without a credit being
recorded; confirming a payment sends exactly one SMS; an invoice is never both
`PAID` and has an outstanding balance; discounts reduce the balance exactly once.

---

### Chapter 9 — Store and POS
**Who:** store keeper/bursar · **Where:** `/store`

1. Add products with stock and optional class targeting; import a CSV.
2. **Store POS**: search pupil → cart → student charge (store ledger).
3. Walk-in sale: no pupil, take cash, print the walk-in receipt, stock reduces.
4. **Store sales** ledger: filter by status.

**Test checkpoints:** a walk-in sale never creates a student debt; selling more
than stock is blocked or warns; the store ledger and the POS total agree.

---

### Chapter 10 — Expenses and profit & loss
**Who:** bursar · **Where:** `/finance/expenses`, `/finance/report/profit-loss`

1. Create expense categories, then expenses with dates and amounts.
2. Run P&L for a date range; read academic vs store income and total expenses.
3. Explain that P&L counts **confirmed** payments only.

**Test checkpoints:** an expense outside the range is excluded; the total equals
income − expenses to the kobo.

---

### Chapter 11 — SMS wallet, calendar and notices
**Who:** admin/bursar · **Where:** `/wallet`, `/events`

1. Show the wallet balance and transaction history.
2. Submit a top-up proof; explain the approval step.
3. Create a calendar event; import events from Excel; mark a flash notice.
4. **Notify** parents for one event.

**Test checkpoints:** sending with a zero balance is refused with a clear
message; a notified event appears on the parent dashboard; an import with a bad
date is rejected row-by-row.

---

### Chapter 12 — Results: entry, approval, publication
**Who:** teacher → form teacher → HOD → year-group master; admin · **Where:** `/results`, `/reports`

1. Explain the assessment scheme (CA1–CA3, theory, practical) and the totals.
2. Teacher enters scores, saves a draft, submits.
3. Each approval stage confirms or rejects; a rejection unlocks the result.
4. Admin unlock, with a reason.
5. Reports: mark sheet, broadsheet with positions, HOD subject master, year-group
   status, grade distribution.
6. Report card: preview, print, share, verify by QR.

**Test checkpoints:** a teacher cannot enter another teacher's subject; results
are locked after submission; a rejected result returns to the teacher only; the
report card totals match the entry screen.

---

### Chapter 13 — Attendance and behaviour
**Who:** form teacher/admin · **Where:** `/attendance`, `/behaviour`

1. Open today's register for the class; mark present/absent/late; save.
2. Amend a past register (change log).
3. Rate behaviour traits for the term; view a pupil's history.

**Test checkpoints:** one register per class per day; an amendment keeps the old
value in the change log; a parent sees only their child's attendance.

---

### Chapter 14 — The parent portal
**Who:** parent · **Where:** `<school>.edus.ng/parent`

1. Sign in; see each child, their balance and notices.
2. Apply for another child; track the application status.
3. View fees, start a bank-transfer payment, upload proof, see the receipt.
4. **Download the statement** — the “Download statement (PDF)” button on the fees
   screen produces an A4 Statement of Account for the whole account: pupil, class,
   guardian, billed/paid/outstanding, the running ledger and a payment instruction
   with the child's admission number as the reference. The footer carries a QR and
   a code that open `/verify/fee-report/{code}` and confirm the school issued it,
   without disclosing any amounts.
5. Read results and download/share the report card.
6. View the calendar.

**Test checkpoints:** a parent cannot open another family's child by changing the
URL (403), and cannot open another family's **invoice** or statement (403); the
statement PDF is A4, opens on a phone, and its verification code resolves; the
portal works at 360px; the four tab-bar items are the only destinations and all
work.

---

### Chapter 15 — School subscription and the term-close report (REP)
**Who:** admin/bursar

1. **Subscription**: plan, roll, the school's own invoices and renewal.
2. **Reports** (planned, `docs/payments/FEES-REPORTING-DESIGN.md`):
   - Daily collections (day book) — no cashier session, a daily view is enough
   - Collection by class (expected vs collected vs outstanding)
   - Outstanding fees / debtors **grouped by guardian** — one row, one phone call
     per family, expandable to each child's balance
   - Pupil fee statement (printable) — issued as an A4 PDF with a QR verification
     footer, downloadable by the bursar and by the parent for their own child
   - Payment register, class matrix, method reconciliation
   - Application fees (collected, waived, awaiting confirmation)
   - Expense register by category
   - Income statement — accrual and cash columns, expenses by category, net surplus
   - Term summary PDF **emailed to the proprietor at the end of the term**, with a
     verification QR that resolves to a public page showing issuer, term and date
     (never amounts)
   - Weekly outstanding-fees email to the proprietor
   - Reminder drafts: the debtors screen opens a prefilled WhatsApp message and
     exports a reminder CSV (guardian, phone, children, amount, message). Nothing
     is sent to a parent automatically
3. Explain how to read each figure (billed, discount, net, collected, pending,
   outstanding) and why store debt is separate.
4. Explain the invoice-level rule: a part payment reduces the invoice balance;
   it is not allocated to individual fee items.

**Test checkpoints:** the acceptance checks in the reporting design (§12), at
minimum: part payment, overpayment, scholarship, walk-in exclusion, not-billed
pupil, guardian grouping, application fees counted once, expenses vs income
statement reconciliation, and parent ownership.

---

## 5. What changes from v1.0

| v1.0 text | v2.0 correction |
|---|---|
| "EDUS is a school finance management platform" | It is a full school operating system: admissions, academics, results, store, communication, parent portal |
| Navigation lists only Academic / Finance / System with ~20 items | Current sidebar is role-gated and has ~40 items; document them by role, not as one list |
| "Result entry" described only as score entry | Add CA components, Excel import, lock-on-submit, four-stage approval, unlock |
| No mention of applications | Add the public apply flow, the applications inbox and parent applications |
| No mention of the public website | Add Chapter 3 and the school subdomain |
| No mention of store inventory or walk-in sales | Add Chapter 9 |
| No mention of the SMS wallet | Add Chapter 11 |
| No mention of year groups, departments, class subjects, subject teachers, behaviour traits | Add Chapter 4/5/13 |
| No mention of subscriptions | Add Chapter 15 |
| Payment history presented as "the report" | Payment history is a log; reporting is Chapter 15 |
| Screenshots from `demo.edus.ng` with old UI | Re-capture from the current demo tenant at 1440px (desktop) and 360px (mobile) |

---

## 6. Writing and screenshot conventions

- **Screen names match the sidebar exactly.** If the sidebar says
  **Scholarships**, the manual says Scholarship, not "Discounts".
- **Learner nouns follow the school setting** (Student/Pupil/Learner). Use the
  `learner` Twig variables as the source of truth in examples.
- **Money:** `₦` + thousands separators + two decimals where a ledger is shown.
- **References:** `APP/2026/0001` and `PAY-…` are `break-words`; never let a
  reference push a phone layout sideways.
- **Screenshots:** PNG, full viewport, no browser chrome for the manual, desktop
  1440×900 and mobile 360×800 for every screen that has a mobile layout.
  File name: `manual/<chapter>-<screen>-<desktop|mobile>.png`.
- **Avoid demo data that looks real.** Use `Demostration School`, not a real
  school's pupils, and never a real phone number in a screenshot.
- **Every chapter opens with a one-sentence "what this screen is for"** and ends
  with **Done when…** so a tester knows when to move on.

---

## 7. Deliverables

| Deliverable | Format | Notes |
|---|---|---|
| Web manual (`/manual`) | Twig | One page, sticky chapter nav, searchable by browser find; mobile drawer of chapters |
| Printable manual | A4 PDF | Through `A4PdfRenderer`, plain CSS, QR verification footer, generated from the same chapter text |
| Quick-start card | 2-page A4 | Login → wizard → add pupils → take a payment; for handover day |
| Test script | Markdown | Alias of `docs/testing/TENANT-ACCEPTANCE-TEST.md`, kept in sync with this chapter numbering |
| Screenshot set | PNG | `public/images/manual/`, re-captured after the UI stabilises |

---

## 8. Demo environment for writing and testing

| Item | Value |
|---|---|
| Marketing site | `http://localhost:8082` |
| Demo school | `http://demo.localhost:8082` |
| Demo admin | `demo@gmail.com` / `demo123` (`ROLE_ADMIN`, `ROLE_BURSAR`) |
| Other tenants | `gobarau.localhost:8082`, `danlami.localhost:8082`, `gakonline.localhost:8082` |
| Default tenant password | `12345678` |
| Mail | Mailpit `http://localhost:8025` (`.env.local` may point at Brevo — check before testing mail) |
| Database | `localhost:3306`, user `root`, password `root` |
| phpMyAdmin | `http://localhost:8083` |

> The demo tenant's trial was extended to **19 Oct 2027** locally so the walkthrough
> can write (onboarding, admissions, payments). Production needs the same extension
> before the public "View Demo" button is shown to prospects.

---

## 9. Chapter-by-chapter test run (the order to walk it)

| Run | Sign in as | Chapters | What must be true at the end |
|---|---|---|---|
| 1 | Demo admin | 1–5 | School configured, classes and staff exist |
| 2 | Demo admin | 6 | One parent application admitted end-to-end |
| 3 | Bursar | 6–8 | Pupils enrolled, invoices generated, a cash payment and a verified transfer |
| 4 | Store keeper | 9 | One student charge and one walk-in sale |
| 5 | Teacher | 12 | Scores submitted for one class/subject |
| 6 | Form teacher → HOD → Year master | 12 | The same result approved through all four stages |
| 7 | Bursar/admin | 10, 15 | Expenses recorded; P&L and fee reports agree |
| 8 | Parent | 14 | Sees the child, the balance, the receipt and the result |
| 9 | Anyone | all | At 360px: no horizontal scroll, every action ≥44px |

---

## 10. Open questions

1. Do we document the **landlord / agent** screens in this manual, or keep them in
   a separate operations manual? Recommendation: separate appendix — a school
   should never read about creating schools.
2. Should the web manual be **one long page** (current) or chapter routes
   (`/manual/getting-started`)? One page is simpler and prints; chapter routes
   are easier to link from the app's help buttons. Recommendation: one page now,
   chapter anchors; split when it passes ~10,000 words.
3. Do we embed **video/GIF** for the wizard and POS? Recommendation: no in v2.0;
   screenshots plus numbered steps until the UI stops moving.
4. Who signs off the copy? The manual repeats commercial promises (free trial,
   import included). Those must match `/pricing` and the sales script exactly.

---

## 11. Walkthrough log — run of the demo tenant

Executed against `demo.localhost:8082` (ARRAGHIBUN ACADEMY KATSINA, 360 pupils)
as part of the manual's test-run table above. Every screen below was opened and
its content checked, not just its status code.

| Area | Routes walked | Result |
|---|---|---|
| Marketing | `/`, `/about`, `/admissions`, `/academics`, `/events`, `/contact`, `/pricing`, `/partners`, `/apply` | 200 |
| Dashboard & admissions | `/dashboard`, `/admission/`, `/admission/applications`, `/quick-enroll/`, `/students/`, `/students/361/profile`, `/students/361/finance` | 200 |
| Academic | `/academic/`, `/classrooms/`, `/subjects/`, `/class-subjects/`, `/grading-scale/`, `/year-groups/`, `/staff/`, `/staff-assignments/*`, `/departments/`, `/parents/`, `/enrollment/promote`, `/events/`, `/my-class` | 200 |
| Fees | `/finance/items/`, `/finance/structure/`, `/finance/fees/schedule`, `/finance/invoice/{academic,store,361}`, `/finance/payment/`, `/finance/payment/history`, `/finance/payment/terminal/361`, `/finance/payment/verify`, `/finance/payment/receipt/3`, `/finance/discounts/{types,assign}`, `/finance/expenses/`, `/finance/expenses/categories`, `/finance/report/profit-loss` | 200 |
| Fee reports | `/finance/reports`, `/collections`, `/classes`, `/debtors`, `/statement/1`, `/statement/1.pdf`, `/payments`, `/reconciliation`, `/matrix`, `/expenses`, `/income`, `/discounts`, `/application-fees`, `/summary.pdf` | 200, CSV + PDF verified earlier |
| Store, wallet, subscription | `/store/products/`, `/store/sales/search`, `/store/sales/cart/361`, `/wallet/`, `/reports`, `/reports/archive`, `/subscription/` | 200 |
| Parent portal | `/parent/dashboard`, `/parent/applications`, `/parent/fees`, `/parent/student/1/finance`, `/parent/calendar/2026/10`, `/reports/student` | 200 after fixes below |
| Settings | `/settings/general`, `/settings/behaviour-traits`, `/dashboard/settings` | 200 |

Expected non-200s (correct behaviour, worth stating in the manual so a reader does
not think the app is broken): `/tenant/results/entry` and `/tenant/approvals` return
403 for a bursar/admin who is neither a subject teacher nor an approver, and a
parent opening another family's child (`/parent/student/361/finance`) gets 403.

### Defects found and fixed during the walkthrough

1. `/parent/applications` returned **500** — the template read `school.phone`
   instead of `school.phoneNumber`. Fixed.
2. Application fee payments had no confirmation step, so `fee_status` never
   reached `FEE_PAID`. Confirm / reject / waive actions added; `fee_status` widened
   to 30 characters because `awaiting_confirmation` did not fit.
3. Invoice arithmetic was inconsistent — some paths charged the gross amount.
   Every screen, the terminal and the bulk importer now use the same
   net-of-discount figures from `DiscountCalculator`.
4. Debtors showed "no phone" because `guardianBalancesForTerm` dropped the
   `guardian`/`phone` fields. Fixed.
5. The statement mixed store invoices into fees; store purchases are now opt-in
   (`?store=1`) so the totals agree with the debtors report.
6. Mobile: the student directory's row actions, toolbar buttons and pagination
   were 30–38px tall, and two file inputs in settings were 14px. All raised to
   ≥44px tap targets / 16px text.
7. `/finance/invoice/{id}` had no ownership check, so a parent could read another
   family's invoice by changing the id. Now limited to bursar/admin/store, or the
   pupil's own guardian.
8. The statement had no printable document, only a browser print of the screen.
   It is now an issued A4 PDF (`StudentStatementPdfRenderer`) for both the bursar
   and the parent, with a verification QR.

### Mobile audit (360px phone, 288 CSS px viewport)

No horizontal scrolling on any page listed above. Tap targets and input sizes
were asserted on the pages touched by the walkthrough; the student directory,
the payment terminal, the fee reports and the whole parent portal now report
zero controls under 44px and zero inputs under 16px.

---

## 12. Status — written vs still planned

> **Related: `docs/playbooks/`.** That folder is the role-by-role counterpart to this
> manual: an agent walkthrough *and* a printable handout for each actor (landlord, admin,
> bursar, subject teacher, form teacher, HOD, year-group master, parent), plus the
> 1,200-pupil demo roll. Read `docs/playbooks/README.md` first; the defect and backlog
> rules are in `DEFECT-LOG.md` and `AGENT-PLAYWRIGHT-STANDARD.md`.

The manual is published at **`/manual`** on the landlord host
(`templates/landlord/home/manual.html.twig`). It is a standalone page with its own
sidebar, so it needs no app session — a proprietor can send the link to staff.

| Part | State |
|---|---|
| Chapters 1–8 (v1.0 text) | **Written, re-checked 19 Oct 2026** — navigation groups, parent logins, store menus, payment history and subscription plans corrected |
| Chapter 9 — Academic structure & assignments | **Written** (19 Oct 2026) |
| Chapter 10 — Results, approvals & report cards | **Written** |
| Chapter 11 — Attendance & behaviour | **Written** |
| Chapter 12 — Fee reporting & the term close | **Written** |
| Chapter 13 — Online applications & parent portal | **Written** |
| Chapter 14 — Verifying an EDUS document | **Written** |
| Chapter 15 — What changed in 2.0 | **Written** |
| Screenshots | **Not yet** — every image is still a 1.0 capture. Shooting list: `docs/manual/SCREENSHOT-CHECKLIST.md` (37 shots, URL + viewport + destination) |
| `edus-official-user-manual.md` (root) | Still the 1.0 companion copy; regenerate from the page when the copy is final |

Conventions now baked into the page, so new chapters match without discussion:

- `.table-scroll` wrapper around every `table.manual-table` — a wide table
  scrolls inside its own box and the page never moves sideways on a phone.
- `.checkpoint` for the "check before you move on" block that closes each new chapter.
- `.note` for anything a reader could get wrong and lose money over.
- `.version-banner` for the "what this version adds" statement at the top.
- Sidebar list and `<h2 id="section-N">` must be extended together; there are
  currently 15 of each and no dead anchors.
- Verified 19 Oct 2026 at 288 CSS px: 0 horizontal overflow, the tables scroll
  inside their wrappers, the drawer opens, and the menu button is 46px tall.

### Still to do on the manual

1. **Screenshots** — the agent cannot capture them: the integrated browser runs
   in a sandbox and a screenshot has nowhere to write inside the workspace. Work
   from `docs/manual/SCREENSHOT-CHECKLIST.md` in a normal browser, saving into
   `public/images/manual/` as `NN-slug.png`, then replace the `src` and `alt` of
   each 1.0 `<img>` and delete the old file.
2. **Regenerate** `edus-official-user-manual.md` from the published page once
   the screenshots are in.

### Contextual help — built 19 Oct 2026

Every app screen now carries a "?" in the header that opens the manual at the
right chapter, in a new tab:

- `App\Twig\ManualExtension` maps a **route-name prefix** to a chapter anchor
  (`app_tenant_report_debtors` → `section-12`,
  `app_tenant_report_student` → `section-10`). Add a screen by adding one line;
  never hard-code a manual URL in a template.
- The URL is generated from the `app_manual` route, so it is correct on any host:
  the manual is published on `%app.base_domain%` while the app runs on a school's
  subdomain, and a relative link would point at a route that subdomain does not
  serve.
- A route with no chapter yields `null` and the header shows no icon — better than
  a link to the manual's front page that the reader has to search.
- **Order is load-bearing.** The map is matched top-down, so the results-report
  prefixes sit above the generic `app_tenant_report_` rule, and
  `app_tenant_dashboard_settings` above `app_tenant_dashboard`.
- Several tenant controllers do not use the `app_` prefix
  (`tenant_result_entry_*`, `tenant_approval_*`, `tenant_subscription_*`,
  `tenant_wallet_*`, `tenant_statement_verify`) — both spellings are in the map.
- Covered by `tests/Twig/ManualExtensionTest.php` (7 tests, including the two
  ordering traps).

⚠️ `cache:clear --no-warmup` followed immediately by `lint:twig` reports
*"Unknown function manual_help"*. That is a half-built container, not a real
break: re-run `cache:clear` with warmup and the lint passes.
