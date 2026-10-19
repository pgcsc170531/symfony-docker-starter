# Edus — End-to-End Acceptance Test (full platform)

> **Which document do I want?**
> - **Tenant only** — you are onboarding a school onto an existing platform: use
>   **`docs/testing/TENANT-ACCEPTANCE-TEST.md`**. It starts from "you have been given a
>   login" and covers only what a school does. **This is the one to hand to a new school.**
> - **Full platform** — you are testing the platform itself, including creating the
>   school from the landlord side: this document.

**Who this is for:** anyone who needs to prove the platform works from an empty
landlord down to a parent reading their child's report sheet. No command line,
no database access — everything here is done through the browser.

**How to use it:** work top to bottom. Every numbered step has an
**Expected** column — what the system is supposed to do — and an **Observed**
column. Fill in **Observed** as you go. If what you see matches *Expected*,
write `OK`; if anything is different, write what you actually saw. The
**Observer notes** column is for the person auditing the run (the agent/tester),
not for the person clicking: it is where a different-from-expected experience
gets recorded *from the user's point of view* — wording that confused you, a
button you could not find, a message that told you the wrong thing.

> ⚠️ **Do not "fix" anything mid-run.** Write it down and carry on. A run that
> is abandoned at step 14 has tested far less than one that finishes with
> twenty observations attached.

---

## 0. What you need before you start

| Item | Notes |
|---|---|
| A running instance | e.g. `http://localhost:8082` (landlord) |
| Landlord (super-admin) login | ask whoever set the environment up |
| Two email addresses you control | one for the school's bursar, one for the parent |
| `docs/testing/sample-students.csv` | the sample bulk-import file in this folder |
| About 45 minutes | the full run is long; the first half alone is worth doing |

**Reading the host names.** This system gives every school its own subdomain,
and its own database. `localhost` is the landlord (the company); a school lives
at `<subdomain>.localhost`. So a school you create with the subdomain `greenfield`
is reached at `greenfield.localhost:8082` — and nothing you do on the landlord
site exists inside that school.

### The roles used below

| Role | What it is for | Created in |
|---|---|---|
| **Landlord / Super-admin** | Creates schools, sees subscription state | Seeded |
| **School admin** (`ROLE_ADMIN`) | The school's owner: sets the school up | Step 2 |
| **Bursar** (`ROLE_BURSAR`) | Admissions, fees, payment reconciliation | Step 2 (or later, §9) |
| **Teacher** (`ROLE_TEACHER`) | Enters scores, sees only their own classes | Step 2 / §9 |
| **Parent** (`ROLE_PARENT`) | Registers, applies for a child, pays fees | §5 |
| **HOD / Form teacher / Year-group master** | Three-stage result approval | §9 |

---

## 1. Create the school (landlord side)

Go to `http://localhost:8082` and sign in as the landlord.

| # | Do this | Expected | Observed | Observer notes |
|---|---|---|---|---|
| 1.1 | Open **Schools → Enroll New School** (`/admin/school/new`) | A form asking for the school's name, subdomain and an admin account | | |
| 1.2 | Enter name `Greenfield Academy`, subdomain `greenfield`, and an admin email/password you control. Submit | Success message; the school appears in `/admin/school/` with an **active** subscription | | |
| 1.3 | Open the new school's detail page (`/admin/school/{id}`) | Shows the school, its subdomain, the tenant database name, and the subscription/trial state | | |
| 1.4 | Visit `http://greenfield.localhost:8082` | The school's own site loads, branded with its name — **not** the landlord site | | |

**Watch for:** the subdomain must be lowercase letters/digits only. If creation
reports success but the subdomain does not load, the tenant database was not
provisioned — record it and stop the run, everything after this depends on it.

---

## 2. Sign in as the school admin and finish onboarding

| # | Do this | Expected | Observed | Observer notes |
|---|---|---|---|---|
| 2.1 | Go to `http://greenfield.localhost:8082/login` and sign in with the admin account from 1.2 | You land on the dashboard, **not** the landlord | | |
| 2.2 | Complete the onboarding wizard (identity → academic calendar → classes/subjects → fee setup) | Each step saves; you can move forwards and back without losing what you typed | | |
| 2.3 | Note the sidebar | Grouped sections: Admissions, Administration, Finance, Teaching, Reports. Every item is reachable; a filter box sits at the top | | |
| 2.4 | Open `/dashboard/settings` and fill in the school's phone, address, motto | "School website settings saved successfully." | | |

---

## 3. Academic basics (only if the wizard did not do it)

| # | Do this | Expected | Observed | Observer notes |
|---|---|---|---|---|
| 3.1 | **Administration → Sessions & Terms** | A session with three terms exists; exactly one term is **active** | | |
| 3.2 | **Administration → Classes** | The classes you need exist (e.g. `JSS 1A`, `JSS 1B`, `JSS 2A`, `JSS 3C`) | | |
| 3.3 | **Administration → Subjects** | At least one subject exists (e.g. `Mathematics`) | | |
| 3.4 | **Administration → Grading Scale** | Grades with min/max ranges (e.g. A1 = 70–100). Add one if the list is empty | | |
| 3.5 | **Administration → Class Subjects** | Each class has its subjects linked, with a subject teacher | | |
| 3.6 | **Finance → Fee Items** then **Fee Structures** | At least one *non-optional* fee item priced for each class + active term | | |

> **The single most common reason a later step looks broken:** a class has no
> **fee structure** for the active term. The pupil is still admitted, but no
> invoice is generated and you will wonder why the parent sees nothing to pay.
> Check 3.6 before moving on.

---

## 4. Bulk-upload eight pupils from CSV

This is the step most schools actually start with: their existing pupils.

| # | Do this | Expected | Observed | Observer notes |
|---|---|---|---|---|
| 4.1 | **Admissions → Bulk Import** (`/quick-enroll/`) | An upload page with a **Download Template** link | | |
| 4.2 | Click **Download Template** and open the file | 13 columns: First Name, Middle Name, Last Name, Gender (M/F), DOB (YYYY-MM-DD), Religion, Blood Group, Genotype, Home Town, Admission Number (Leave blank to auto-generate), Class Name, Parent Name, Parent Phone | | |
| 4.3 | Compare with `docs/testing/sample-students.csv` | Same 13 columns, same order. Use the sample file directly if you prefer | | |
| 4.4 | Select the **active term** in the term dropdown, choose `sample-students.csv`, upload | A preview/result stating **8 imported** (not 0) | | |
| 4.5 | **Admissions → Students** | Eight pupils, each with an auto-generated admission number (`ADM/<year>/####`) | | |
| 4.6 | Open one pupil | Guardian name and phone from the CSV are attached | | |
| 4.7 | **Finance → Invoices** | Eight invoices, one per pupil, each with line items summing to the class fee | | |

**Watch for:** if the run reports **0 imported**, the term dropdown was left on
its placeholder ("Select Term…"). That silently rejects every row — record it,
select a real term, and re-upload.

**Watch for:** if pupils import but invoices do not, see the note under §3.

Re-uploading the same file is safe — it is idempotent. The second upload should
report **0 new** rows, not eight duplicates.

---

## 5. A parent registers and applies for a child

Use a **second** email address, in a private/incognito window so the admin
session is not replaced.

| # | Do this | Expected | Observed | Observer notes |
|---|---|---|---|---|
| 5.1 | Go to `http://greenfield.localhost:8082/apply` | The public admissions landing page — no login needed | | |
| 5.2 | Choose to register as a parent | A registration form asking for name, email, phone, password | | |
| 5.3 | Register with a real password (e.g. `GreenField!2026`). Use `password123` and see what happens | Weak passwords are rejected with a clear message | | |
| 5.4 | Open the confirmation email | *If the environment uses Mailpit* (dev), read it at `http://localhost:8025`. Otherwise open the inbox | | |
| 5.5 | Sign in as the parent | You land on the parent dashboard | | |
| 5.6 | Click **+ Apply for a child** | A multi-step application form | | |
| 5.7 | Fill in the child's details, choose class `JSS 1A`, complete every step, then submit | A reference number such as `APP/<year>/0001`; the dashboard shows the application | | |
| 5.8 | Note the state of the application | It should move to **Submitted** (or **Awaiting payment** if an application fee is configured) | | |
| 5.9 | Check the inbox again | An acknowledgement email naming the reference | | |

**Watch for:** the parent can only see their own application. There is no
staff-facing page where a teacher could look at it — the next step is where
staff pick it up.

---

## 6. The bursar reviews and admits the application

Sign back in as the **school admin** (or a bursar).

| # | Do this | Expected | Observed | Observer notes |
|---|---|---|---|---|
| 6.1 | **Admissions → Applications** (`/admission/applications`) | The queue lists the parent's application with counters for Needs attention / New / Under review / Needs info / Enrolled / Rejected | | |
| 6.2 | Click the application | A review screen: the child's details, the parent's details, uploaded documents, the history, and a **Decision** panel | | |
| 6.3 | Check the status badge after opening | It changed from *Submitted* to *Under review*, and the history has a new entry naming you | | |
| 6.4 | Press **Send back to parent** with an empty reason | It is refused — a reason is required, because the parent will read it | | |
| 6.5 | Press **Approve & enrol** | A green confirmation naming the new admission number; the banner changes to **Enrolled** | | |
| 6.6 | **Admissions → Students** | A new pupil, with an `ADM/<year>/####` number and class `JSS 1A` | | |
| 6.7 | **Finance → Invoices** | A new invoice for that pupil, with line items | | |
| 6.8 | Check the parent's inbox | A "place offered" email with the admission number | | |
| 6.9 | Sign in as the parent and open the dashboard | The child appears with an **outstanding balance**, and a **Pay Now** link | | |

**Watch for:** the guardian must be **re-used**, not duplicated. Applying for a
second child from the same parent must not create a second guardian record.

---

## 7. Payment — the offline method

| # | Do this | Expected | Observed | Observer notes |
|---|---|---|---|---|
| 7.1 | As the parent, open **Fees & Payments** | The outstanding invoice, with its balance | | |
| 7.2 | Open the invoice and press **Generate Payment Slip** | A slip with a transaction reference, the amount, and the **school's bank name and account number** | | |
| 7.3 | Read the bank details | Real values. If you see `0000000000` / **Bank Not Set**, the school has not entered its account — record it, because a parent cannot pay against it | | |
| 7.4 | As staff, go to **Finance → Payments**, open the pupil's terminal | The pending transfer slip is listed | | |
| 7.5 | Confirm the transfer | The invoice becomes **PAID**, the balance reaches zero, and the parent gets a receipt email | | |

---

## 8. Payment — the online method

| # | Do this | Expected | Observed | Observer notes |
|---|---|---|---|---|
| 8.1 | As the admin, open `/dashboard/settings` and set **Payment method** to `Both` or `Online`. Save | Saved | | |
| 8.2 | Back as the parent, open **Fees & Payments** | A **Pay online** button now appears next to the invoice | | |
| 8.3 | Press it | Either the real gateway page, or a **simulated checkout** explaining that no gateway key is configured | | |
| 8.4 | On the simulated page, press **Simulate successful payment** | "Payment received", a reference like `PAY-<year>-XXXXXX`, and the amount split into school fees + a processing charge | | |
| 8.5 | Check the inbox | A "Payment received" receipt email | | |
| 8.6 | As staff, open the pupil's payment terminal | Invoice **PAID**, balance zero, the payment recorded as `ONLINE` / `CONFIRMED` | | |
| 8.7 | Press **Simulate a decline** on a second attempt | The invoice stays unpaid and the parent is told the payment failed | | |

**Watch for:** the processing charge is added **on top of** the fee and is capped.
Check that the school's own figure is unaffected — the school should receive the
full fee.

---

## 9. Teaching staff, score entry and the three-stage approval

| # | Do this | Expected | Observed | Observer notes |
|---|---|---|---|---|
| 9.1 | As admin, create a **staff** record for a teacher and give them a login (`/staff/`) | The teacher can sign in | | |
| 9.2 | Sign in as the teacher | The sidebar shows **only** Teaching and Reports items — no Finance, no Administration | | |
| 9.3 | Open **Teaching → Result Entry** and pick the class + subject | A table of the class's pupils with CA1/CA2/CA3/Theory columns | | |
| 9.4 | Type a few scores | **Total** and **Grade** update as you type | | |
| 9.5 | Press **Save All Scores**, then reload the page | "Scores saved."; after reload the scores are still there | | |
| 9.6 | Enter a score above the maximum (e.g. 999) | It is refused or capped, not silently accepted | | |
| 9.7 | Press **Submit for Approval** | The page reloads with a padlock: results are submitted and locked | | |
| 9.8 | Open **Approvals** | Three stages are visible: Form Teacher — Confirm Classes, HOD — Approve Department Subjects, Year Group Master — Final Approval | | |
| 9.9 | Complete the three stages in order | Each reports success; after the final one the subject shows as fully approved | | |
| 9.10 | As the parent, open **My Results** | The subject, the total and the grade, for the term | | |

**Watch for:** after submitting, the teacher must no longer be able to edit.
Confirm the inputs are disabled.

---

## 10. Report sheet

| # | Do this | Expected | Observed | Observer notes |
|---|---|---|---|---|
| 10.1 | As staff, go to **Reports** (`/reports`) | A hub offering teacher / form-teacher / HOD / year-group / admin views | | |
| 10.2 | Open the class broadsheet for `JSS 1A` | Every pupil in the class, every subject, with totals and positions | | |
| 10.3 | Print-preview the broadsheet | A clean sheet, no navigation or sidebar | | |
| 10.4 | As the parent, print-preview **My Results** | The same figures the broadsheet shows | | |

---

## 11. Mobile check — do not skip this

Parents use this on a phone. Open your browser's device toolbar.

| # | Do this | Expected | Observed | Observer notes |
|---|---|---|---|---|
| 11.1 | Set the width to **360px** and visit the parent dashboard | One column, nothing cut off, **no sideways scrolling** | | |
| 11.2 | Visit **Fees & Payments** at 360px | The invoice is readable; any wide table scrolls *inside its own box*, not the page | | |
| 11.3 | Visit the staff **Applications** queue at 360px | Each application is a card, not a squeezed table | | |
| 11.4 | Tap every primary action | Comfortably tappable — nothing smaller than a fingertip | | |
| 11.5 | Tap a text field | The page must **not** zoom in (that means the field is under 16px) | | |
| 11.6 | Repeat 11.1–11.3 at **1024px** | Layout widens sensibly; still no sideways scrolling | | |

---

## 12. Sign-off

| Question | Answer |
|---|---|
| Subdomain tested | |
| Date and time of run | |
| Tester name | |
| Steps completed (of the 60 above) | |
| Steps that failed outright | |
| Most confusing moment in the whole run | |
| Would a real bursar get through §4 and §6 unsupervised? | |
| Would a real parent get through §5 and §7 unsupervised? | |
| Overall: **ready / ready with caveats / not ready** | |

---

## Known rough edges — confirm or clear these

These were found on a live run against a demo tenant. Treat them as
*things to check*, not as a script: if the behaviour has since changed, say so
in your observations rather than assuming the note is still true.

| Where | What was seen | Why it matters to a user |
|---|---|---|
| Bursar: `/finance/payments` slip | The school's bank name and account number were blank, so the slip read `Bank Not Set` / `0000000000` | A parent is told to transfer money into a non-existent account |
| School settings | There is **no field** for bank name or account number anywhere in the UI | The school cannot fix the above without a developer |
| Parent dashboard | Merely *generating a payment slip* shows up as a **"Last payment"** with a *Verifying…* badge | A parent may believe they have paid when they have only been given instructions |
| Online payment | The **Pay online** button is hidden unless a gateway key is configured *and* the school has opted in | Parents see no way to pay online and no explanation why |
| Result entry | Score entry saves and submission both silently failed because the browser posted to `/entry/undefined` | A teacher sees "Scores saved." while nothing is stored |
| Approvals | After final approval the **Final Approve** button is still enabled | A second press is possible on an already-approved result |
| Seeded demo data | One person can hold Form Teacher + HOD + Year-group Master for the same class | The approval demo shows no separation of duties |
| Email | Development mail goes to **Mailpit**, not a real inbox | Nothing reaches a parent until a real mail provider is configured — see `docs/operations/EMAIL-SETUP.md` |

---

## Related reading

| Topic | Document |
|---|---|
| The parent application flow in full | `docs/parent-application/DESIGN.md` |
| Email setup, sender domain, deliverability | `docs/operations/EMAIL-SETUP.md` |
| Mobile standard this test enforces | `docs/architecture/MOBILE-FIRST.md` |
| Design system / what the screens should look like | `docs/architecture/UI-SPEC.md` |
| Payment architecture | `docs/payments/PAYMENT-ARCHITECTURE.md` |
