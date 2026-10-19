# Edus — Tenant Acceptance Test

**Scope: the school, not the platform.** This document tests what happens *inside*
a school (tenant). Creating the school, subscriptions, plans and billing belong to the
landlord and are out of scope — you are given a tenant that already exists.

**How to use it:** work top to bottom, fill in the **Observed** column as you go
(`OK`, or what you actually saw), and use **Observer notes** for one thing only:
the user's own experience of anything that differed from *Expected* — wording that
confused, an action you could not find, a message that said the wrong thing.
The observer is the agent sitting beside the user, not the person clicking.

> **Do not fix anything mid-run.** Write it down and carry on. A run that stops at
> step 20 has tested less than one that finishes with thirty observations.
>
> **The order is deliberate.** Each section only uses things created in the sections
> above it. If you find yourself needing something that does not exist yet, that is
> a finding — note it, do not jump ahead and come back.

---

## 1. The stakeholders — read this before you start

These are the five people the school actually employs or serves. **The school's own
staff come first**, because nothing in the platform works until they have set it up:
every other actor — and every number a parent eventually sees — is downstream of
them. A parent cannot register, apply, pay or read a result until an admin has built
the academic year and a bursar has loaded the pupils.

| # | Actor | Role in the system | What they must be able to do unaided | What breaks if they cannot |
|---|---|---|---|---|
| **A** | **School Admin** (proprietor/principal) | `ROLE_ADMIN` | Configure the school, the academic year, classes, subjects, fees and staff | Nothing else can happen. Every later section depends on this one |
| **B** | **Bursar** | `ROLE_BURSAR` | Load pupils in bulk or one at a time, admit applicants, take and reconcile money | The school cannot enrol, bill, or prove anyone paid |
| **C** | **Teacher** | `ROLE_TEACHER` | Enter, save and submit CA and exam scores for their own classes only | There are no results to report |
| **D** | **Form teacher / HOD / Year-group master** | usually also `ROLE_TEACHER` | Confirm and approve results through three stages | Results are never released, or leak unreviewed |
| **E** | **Parent** | `ROLE_PARENT` | Register, apply for a child, pay, and read the result | The school's work produces no value — this is the audience |

**The golden rule this ordering protects:** *staff first, parent last.* The parent is
the customer but the last link in the chain; every acceptance run must prove that a
parent never has to ask the school to "do something" that the school could have done
earlier.

**Deliberately out of scope:** the landlord/super-admin, subscription and plan
billing, agent/agency flows, SMS to parents (no SMS provider is wired up), and the
public marketing website.

---

## 2. Before you start

| Item | Notes |
|---|---|
| Tenant URL | e.g. `http://greenfield.localhost:8082` |
| School admin login | given to you |
| `docs/testing/sample-students.csv` | 8 pupils, 13 columns — the bulk-import file |
| Two email addresses | one for a parent account; one you can open to read mail |
| ~40 minutes | sections 3–7 alone are the core |

**Sign in at `<tenant>/login`.** Everything below happens on the tenant host — if you
find yourself on a page without the school's name in the header, you are on the wrong
host.

---

## 3. Actor A — School Admin sets the school up

Signed in as: **school admin**.

| # | Do this | Expected | Observed | Observer notes |
|---|---|---|---|---|
| 3.1 | Open `/dashboard/settings` and fill in the school's phone, address and motto. Save | "School website settings saved successfully." | | |
| 3.2 | Check the **sidebar** | Grouped sections — Admissions, Administration, Finance, Teaching, Reports — with a filter box at the top. Every item is reachable | | |
| 3.3 | **Administration → Sessions & Terms** | One session with three terms; **exactly one** term is marked active | | |
| 3.4 | **Administration → Classes** | The classes you need exist (`JSS 1A`, `JSS 1B`, `JSS 2A`, `JSS 3C`) | | |
| 3.5 | **Administration → Subjects** | At least one subject exists (`Mathematics`) | | |
| 3.6 | **Administration → Grading Scale** | Grade bands with min/max (e.g. A1 = 70–100). Add one if the list is empty | | |
| 3.7 | **Administration → Class Subjects** | Every class has its subjects linked **and a subject teacher assigned** | | |
| 3.8 | **Finance → Fee Items**, then **Fee Structures** | At least one **non-optional** fee item priced for each class in the active term | | |
| 3.9 | **Administration → Staff** | A staff record exists for the bursar and for at least one teacher, each with a working login | | |
| 3.10 | **Administration → Departments / Year Groups** (if shown) | Heads and year-group masters are assigned | | |

> ⚠️ **The most common reason a later section looks broken** is step 3.8: a class with
> no **fee structure** for the active term. Pupils are still admitted, but no invoice
> is raised, and section 7 has nothing to pay. Confirm 3.8 before continuing.

---

## 4. Actor B — Bursar loads the existing pupils

Signed in as: **bursar** (`ROLE_BURSAR`).

| # | Do this | Expected | Observed | Observer notes |
|---|---|---|---|---|
| 4.1 | **Admissions → Bulk Import** (`/quick-enroll/`) | An upload page with a **Download Template** link | | |
| 4.2 | Download the template and open it | 13 columns, in this order: First Name, Middle Name, Last Name, Gender (M/F), DOB (YYYY-MM-DD), Religion, Blood Group, Genotype, Home Town, Admission Number (Leave blank to auto-generate), Class Name, Parent Name, Parent Phone | | |
| 4.3 | Compare it with `sample-students.csv` | Identical columns and order — you may use the sample file directly | | |
| 4.4 | Select the **active term**, choose the CSV, upload | A result stating **8 imported** — not 0 | | |
| 4.5 | **Admissions → Students** | 8 pupils, each with an auto-generated `ADM/<year>/####` number | | |
| 4.6 | Open one pupil | Guardian name and phone from the CSV are attached | | |
| 4.7 | **Finance → Invoices** | 8 invoices, one per pupil, line items summing to the class fee | | |
| 4.8 | Re-upload the same file | Reports **0 new** rows — no duplicates | | |
| 4.9 | **Admissions → New Admission** and admit one pupil by hand | The same admission engine runs: admission number, enrollment and invoice appear | | |

**Watch for:** a run reporting **0 imported** almost always means the term dropdown was
left on its placeholder ("Select Term…"), which silently rejects every row.

---

## 5. Actor E — Parent registers and applies

Use a **second email address**, in a private window so the staff session survives.

| # | Do this | Expected | Observed | Observer notes |
|---|---|---|---|---|
| 5.1 | Open `<tenant>/apply` | The public admissions page — no login needed | | |
| 5.2 | Register as a parent | Form for name, email, phone, password | | |
| 5.3 | Try the password `password123` first | Rejected with a clear "too easy to guess" message | | |
| 5.4 | Register with a strong password (e.g. `GreenField!2026`) | Account created; a confirmation email is sent | | |
| 5.5 | Open the confirmation email | Arrives from the school's own name. *(Dev: `http://localhost:8025`. With Brevo configured, a real inbox)* | | |
| 5.6 | Sign in as the parent | Parent dashboard | | |
| 5.7 | **+ Apply for a child** | Multi-step application form | | |
| 5.8 | Complete every step for a child, choosing class `JSS 1A`, then submit | A reference `APP/<year>/0001`; the application shows as **Submitted** | | |
| 5.9 | Check the inbox again | Acknowledgement email naming the reference | | |

---

## 6. Actor B — Bursar reviews and admits the application

Back as the **bursar** (or admin — the queue accepts both).

| # | Do this | Expected | Observed | Observer notes |
|---|---|---|---|---|
| 6.1 | **Admissions → Applications** (`/admission/applications`) | The queue lists the application, with counters (needs attention / new / under review / needs info / enrolled / rejected) | | |
| 6.2 | Open it | Child, guardian, documents, history and a **Decision** panel | | |
| 6.3 | Look at the status badge | Moved from *Submitted* to *Under review*, with a history entry naming you | | |
| 6.4 | Press **Send back to parent** with no reason | Refused — a reason is required, because the parent reads it | | |
| 6.5 | Press **Approve & enrol** | Green confirmation naming the new admission number; banner becomes **Enrolled** | | |
| 6.6 | **Admissions → Students** | The new pupil, correct class, `ADM/<year>/####` | | |
| 6.7 | **Finance → Invoices** | A new invoice with line items | | |
| 6.8 | Apply for a **second child** from the same parent (repeat 5.7–5.8), then admit it | The existing guardian is **reused** — the parent list does not gain a duplicate | | |

---

## 7. Actors E and B — Money, both ways

### 7a. Offline transfer

| # | Do this | Expected | Observed | Observer notes |
|---|---|---|---|---|
| 7.1 | As the **parent**, open **Fees & Payments** | The outstanding invoice and its balance | | |
| 7.2 | Open the invoice → **Generate Payment Slip** | A slip with a reference, the amount, and the **school's real bank name and account number** | | |
| 7.3 | Read the bank details | Real values. `0000000000` / **Bank Not Set** means school setup is incomplete — record it, a parent cannot pay against it | | |
| 7.4 | As the **bursar**, open **Finance → Payments** → the pupil's terminal | The pending transfer is listed | | |
| 7.5 | Confirm it | Invoice becomes **PAID**, balance zero, receipt email sent to the parent | | |
| 7.6 | Try to confirm the same slip twice | Refused with "already fully paid" — and the slip is cancelled, not silently re-added | | |

### 7b. Online payment

| # | Do this | Expected | Observed | Observer notes |
|---|---|---|---|---|
| 7.7 | As the **admin**, set **Payment method** to `Both` or `Online` in `/dashboard/settings`. Save | Saved | | |
| 7.8 | Back as the **parent**, open **Fees & Payments** | A **Pay online** button now appears | | |
| 7.9 | Press it | The real gateway page, or a **simulated checkout** explaining that no gateway key is configured | | |
| 7.10 | Press **Simulate successful payment** | "Payment received", a `PAY-<year>-XXXXXX` reference, fees and a processing charge shown separately | | |
| 7.11 | Check the inbox | A "Payment received" receipt email | | |
| 7.12 | As the **bursar**, open the pupil's terminal | Invoice **PAID**; the payment recorded as `ONLINE` / `CONFIRMED` | | |
| 7.13 | Repeat 7.8–7.10 and choose **Simulate a decline** | The invoice stays unpaid and the parent is told it failed | | |

**Watch for:** the processing charge is added on top and capped. The school must still
receive the full fee — check the school's figure, not the parent's total.

---

## 8. Actor C — Teacher enters and submits scores

Signed in as: **teacher**.

| # | Do this | Expected | Observed | Observer notes |
|---|---|---|---|---|
| 8.1 | Look at the sidebar | **Only** Teaching and Reports items. No Finance, no Administration | | |
| 8.2 | **Teaching → Result Entry**, pick the class and subject | A table of the class's pupils with CA1 / CA2 / CA3 / Theory columns | | |
| 8.3 | Type a score into a few rows | Total and Grade update live as you type | | |
| 8.4 | Press **Save All Scores**, then **reload the page** | "Scores saved." — and the scores are still there after the reload | | |
| 8.5 | Enter an impossible score (e.g. 999) | Refused, or capped — never silently accepted | | |
| 8.6 | Try to open another teacher's class by editing the URL | Access denied | | |
| 8.7 | Press **Submit for Approval** | Page reloads with a padlock: results submitted and locked | | |
| 8.8 | Try to edit a score after submitting | Inputs are disabled | | |

> **Why 8.4 insists on a reload:** the save is asynchronous. A page that says
> "Scores saved." while the request actually failed is the most damaging bug this
> system can have, because the teacher has no reason to doubt it.

---

## 9. Actor D — Three-stage approval

Still as staff. The three stages exist so no single person can release results alone.

| # | Do this | Expected | Observed | Observer notes |
|---|---|---|---|---|
| 9.1 | Open **Approvals** (`/tenant/approvals`) | Three clearly separated stages: **Form Teacher — Confirm Classes**, **HOD — Approve Department Subjects**, **Year Group Master — Final Approval** | | |
| 9.2 | Stage 1: confirm the class | Success; the subject shows as confirmed | | |
| 9.3 | Stage 2: approve as HOD | Success | | |
| 9.4 | Stage 3: final-approve as year-group master | Success | | |
| 9.5 | Press **Final Approve** again | Disabled or refused — a second press must not change anything | | |
| 9.6 | Go back to **Result Entry** for that subject | Still locked; editing requires an explicit unlock | | |
| 9.7 | Unlock it, then re-approve | The unlock is recorded, and re-approval works | | |

---

## 9b. Actor D — Form master records attendance and behaviour

Signed in as the **form master** (the teacher assigned to the class). Both duties live in
**My Class**, and both must be finished *before* the results are released — once the
last subject is final-approved the screens become read-only.

| # | Do this | Expected | Observed | Observer notes |
|---|---|---|---|---|
| 9b.1 | Open **My Class** in the sidebar | The class this teacher is form master of, with two completion bars: registers taken and pupils rated | | |
| 9b.2 | Check the register count against the term | It reads *n / school days*, excluding weekends and any holiday on the school calendar | | |
| 9b.3 | Press **Take today's register** | Today's date (or the most recent school day if today is a weekend), every pupil pre-marked **Present** | | |
| 9b.4 | Mark two pupils **Absent**, press **Save register** | "Saved · 39 present, absent…", and a reason field appears for each absent pupil | | |
| 9b.5 | **Reload the page** | The two absences are still there | | |
| 9b.6 | Change one back to **Present** and save | Counts update; the change is recorded (staff, date and time) | | |
| 9b.7 | Open **All days** | Every school day in the term, marked Taken or Missing | | |
| 9b.8 | Delete 3 registers' worth of marking by never taking them, then check the summary | The summary shows *opened* = registers actually taken, **not** the length of the term | | |
| 9b.9 | Enrol a pupil today, then check the summary | That pupil is measured from their enrolment date, not shown as very low attendance | | |
| 9b.10 | Open **Behaviour** | 18 traits (11 affective, 7 psychomotor) for the selected pupil, each with a 1–5 dot scale | | |
| 9b.11 | Set one trait to **Excellent**, then press **All = 4** and adjust | Each change saves immediately; "X of 18" updates | | |
| 9b.12 | Move to the next pupil and leave them unrated | They stay visibly unrated — never defaulted to a middle score | | |
| 9b.13 | As the **parent**, open **My Results** | Attendance block (times school opened, present, absent, %) and both domains as dots; a pupil with no ratings reads **"Not rated"** | | |
| 9b.14 | As a **subject teacher**, try to open My Class | Access denied, or only their own class — a subject teacher may not mark another form's register | | |
| 9b.15 | After the last subject is final-approved, revisit **My Class** | "Released — read only"; attendance and behaviour can no longer be edited | | |

---

## 10. Actor E — Parent reads the result

| # | Do this | Expected | Observed | Observer notes |
|---|---|---|---|---|
| 10.1 | As the **parent**, open **My Results** | The subject, total and grade for the term | | |
| 10.2 | Judge it as a parent would | Can you answer, without clicking anything else: *what did my child get, and is that good?* | | |
| 10.3 | Compare with the class broadsheet (staff → **Reports → form teacher**) | The parent's figures match the school's | | |
| 10.4 | Try to open another pupil's result by changing the id in the URL | Access denied | | |

> **This step is the whole product.** Score the answer to 10.2 honestly — it is the
> input to `docs/results/RESULT-CARD-DESIGN.md`.

---

## 11. Every actor — mobile

Parents use this on a phone, one-handed, on a slow connection. Use the device toolbar.

| # | Do this | Expected | Observed | Observer notes |
|---|---|---|---|---|
| 11.1 | **360px**: parent dashboard | One column, nothing cut off, **no sideways scrolling** | | |
| 11.2 | 360px: **Fees & Payments** | Readable; wide tables scroll inside their box, not the page | | |
| 11.3 | 360px: staff **Applications** queue | Each application is a card, not a squeezed table | | |
| 11.4 | 360px: **Result Entry** | Usable — a teacher must be able to enter scores on a phone | | |
| 11.5 | Tap every primary action | Comfortably tappable, nothing under a fingertip | | |
| 11.6 | Tap a text field | The page must **not** zoom in (that means the field is under 16px) | | |
| 11.7 | Repeat 11.1–11.4 at **1024px** | Layout widens sensibly; still no sideways scrolling | | |

---

## 12. Sign-off

| Question | Answer |
|---|---|
| Tenant / subdomain tested | |
| Date and time | |
| Tester (the person clicking) | |
| Observer (the agent recording differences) | |
| Steps completed (of 78) | |
| Steps that failed outright | |
| Most confusing moment of the run | |
| Could the **admin** set the school up alone? | |
| Could the **bursar** import and admit alone? | |
| Could the **teacher** enter and submit alone? | |
| Could the **parent** register, pay and read the result alone? | |
| Overall: **ready / ready with caveats / not ready** | |

---

## 13. Known rough edges — confirm or clear

Found on a live tenant run. Treat these as *things to check*, not as a script: if the
behaviour has changed, record that rather than assuming the note still holds.

| Where | What was seen | Why it matters to the user |
|---|---|---|
| Bursar: payment slip | School bank name and account number were blank → slip read `Bank Not Set` / `0000000000` | A parent is told to transfer money into an account that does not exist |
| School settings | **No field exists** for bank name or account number | The school cannot fix the above without a developer |
| Parent dashboard | Merely *generating a slip* shows as a **"Last payment"** with a *Verifying…* badge | A parent may think they have paid when they only have instructions |
| Online payment | **Pay online** is hidden unless a gateway key is configured *and* the school opts in | No way to pay online, and no explanation why |
| Result entry | Save and submit once posted to `/entry/undefined` and failed silently while saying "Scores saved." | A teacher loses a whole term's work |
| Approvals | After final approval the **Final Approve** button stayed enabled | A result can be approved twice |
| Result status | Score rows stay `submitted` even after full approval | Anything gating on score status will leak or hide results |
| Seeded demo data | One person can hold Form Teacher + HOD + Year-group Master for one class | The chain demonstrates the mechanism but not separation of duties |
| Email | Development mail goes to Mailpit unless Brevo is configured | Nothing reaches a parent |

---

## Related reading

| Topic | Document |
|---|---|
| The result card this test feeds into | `docs/results/RESULT-CARD-DESIGN.md` |
| Full-platform test (includes landlord setup) | `docs/testing/END-TO-END-ACCEPTANCE-TEST.md` |
| Parent application design | `docs/parent-application/DESIGN.md` |
| Email setup and sender domain | `docs/operations/EMAIL-SETUP.md` |
| Mobile standard | `docs/architecture/MOBILE-FIRST.md` |
| Design system | `docs/architecture/UI-SPEC.md` |
| Payments | `docs/payments/PAYMENT-ARCHITECTURE.md` |
