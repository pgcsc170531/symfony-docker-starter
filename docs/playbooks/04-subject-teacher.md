# 04 — Subject teacher (result entry)

**Actor:** a teacher assigned to a class × subject. Enters marks, submits them for
approval. Cannot approve their own work.
**Where:** the school's subdomain · **phone first** — this is the one role most likely to
be done standing in a classroom, so run the whole file at 288 CSS px, then spot-check
1024.

---

## Part A — Agent playwright

Sign in with the **subject-teacher-only** account created in `02-tenant-admin.md` step 11
and assigned in step 12.

| # | Do | Expect |
|---|---|---|
| 1 | `/dashboard` | No Finance group in the sidebar; Teaching group present |
| 2 | `/tenant/results/entry` | **Only** the assigned class-subjects are listed |
| 3 | Open one entry form (`/tenant/results/entry/{id}`) | The pupil roster is that class; component columns and a running total |
| 4 | Enter scores for three pupils, click **Save draft** | Reload the page: the three scores are still there |
| 5 | Download the Excel template (`/template`) | Columns match the on-screen entry; the pupil list is the same |
| 6 | Fill the template and import it (`/import`) | Import report: accepted rows only, no row lands on the wrong pupil |
| 7 | Submit (`/submit`) | Status becomes submitted; the form becomes read-only |
| 8 | Try to edit a submitted score | Refused — submission locks the sheet |
| 9 | `/reports/teacher` then the class-subject report | Mark sheet shows every pupil, the same totals as the entry screen |
| 10 | Request another teacher's class-subject by URL (`/tenant/results/entry/{other-id}`) | 403 or 404 — never that teacher's roster |
| 11 | `/tenant/approvals` | Only what this teacher holds an assignment for; a plain subject teacher sees nothing to approve |

### Assertions

- The **total on the report equals the total in the entry screen**, per pupil.
- A score above the maximum is rejected or clamped — record which; either is acceptable
  behaviour, but it must be one of them and must be consistent.
- Missing scores are visibly missing, not silently zero.
- At 288: no horizontal scroll inside the entry table (it may scroll inside its own
  wrapper), every input ≥16px, Save/Submit ≥44px.

---

### Run 2026-10-19 — subject teacher — school `stmarys` (1,200 pupils)

Teacher `teacher@stmarys.ng` (**Mrs. Amina Sani**) · Basic Science × JSS 1 A × First Term ·
assignment created through `/staff-assignments/subject-teacher` by picking her **by name**.

| Step | Result | Note |
|---|---|---|
| 1 `/dashboard` | **pass** | Sidebar is *Dashboard · My Class · Result Entry · Reports* — no Finance, no Administration |
| 2 `/tenant/results/entry` | **pass** | One line: *Basic Science · JSS 1 A · First Term · 2026/2027* |
| 3 Open the sheet | **FAIL → FIXED → pass** | **P-TEACHER-01**: all 67 pupils read *0 · F9* before a single mark was typed. Now blank cells, `—` total, `—` grade |
| 3b Click a score cell | **FAIL → FIXED → pass** | **P-TEACHER-02**: the row's edit modal opened over the table on every cell click. `@click.stop` on the inputs |
| 4 Three pupils, then Save | **FAIL → FIXED → pass** | **P-TEACHER-04**: "Save All Scores" wrote a row of **zeros for all 67 pupils**, so the class read as marked-and-failed. Save now sends only pupils with a mark and only the components in the scheme |
| 5 Template download | **FAIL → FIXED → pass** | **P-TEACHER-03**: template was one fake row (`24/001 John Doe`). Now the real class list — 67 rows, existing marks pre-filled |
| 6 Import filled CSV | **pass** | 3 pupils updated to 91 / 84 / 77, each on the right pupil; page reloads with the new totals |
| 7 Submit | **pass** | Sheet locks: 268 inputs disabled, Save/Submit disabled |
| 8 Edit after submit | **pass** | API answered **423** *"Results are locked. An HOD or Admin must unlock before editing."* |
| 9 Teacher report → class sheet | **pass** | 3 rows, totals 91 / 84 / 77 — identical to the entry screen |
| 10 Another teacher's sheet (`/entry/1`) | **pass** | **403** *"This subject assignment belongs to another teacher."* |
| 11 `/tenant/approvals` | **pass** | *"You have no approval assignments yet…"* — correct empty state, not a blank page |

**Mobile (288 CSS px = real 360):** page does not scroll sideways (0 px overflow); the marks
table scrolls **inside** its own wrapper (330 px of hidden columns); score inputs are 16 px;
Save/Submit are 64 px tall.

**Backlog (not fixed, by design):** *Save All Scores* confirms with a native `alert()` and
*Submit for Approval* asks for no confirmation at all — see B-10.

---

## Part B — User handout

### Where you work

Sign in at your school's address. Your teaching screens are under **Teaching**.

### Entering marks

1. Open **Teaching → Result Entry**. You see only the classes and subjects assigned to
   you. If the list is empty, your assignments are not set up yet — tell the
   administrator; you cannot fix it yourself.
2. Choose the class and subject.
3. Type each pupil's scores. The total and grade calculate as you go; do not type the
   grade yourself.
4. Click **Save draft** as often as you like. A draft is private to you and can be
   corrected.
5. When the whole column is complete, click **Submit for approval**.

### If you prefer Excel

1. On the entry screen, download the template. It already contains your pupil list — do
   not add or remove rows.
2. Fill the score columns and save.
3. Import it back. Anything the system cannot read is listed in the import report; fix
   only those rows and import again.

### What happens after you submit

| Stage | Who | What they check |
|---|---|---|
| 1 | You | Every pupil has a score |
| 2 | Form teacher | The class list is complete across all subjects |
| 3 | Head of department | The marks are academically sound |
| 4 | Year group master | The whole year group is ready |

**Once submitted, you cannot edit.** If you find a mistake, ask the HOD to reject the
sheet — it comes back to you with a reason. Do not ask for your score to be "adjusted"
in conversation; a mark that is not in the system does not exist on the report card.

### The rules that cause the most trouble

- **Do not leave a pupil blank because they scored zero.** Type `0`. Blank means "not
  assessed" and will show as missing on the report card.
- **Do not type a grade or a remark.** The grading scale decides both.
- **Do not copy scores from a previous term.** The term is fixed on the sheet.
- **Do not enter marks for a subject you do not teach.** Even if the URL is guessable, the
  system will refuse — and a mark entered in the wrong column is worse than a missing one.

### When it goes wrong

| What you see | What to do |
|---|---|
| "Nothing assigned to you" | Tell the administrator — the class-subject assignment is missing |
| The sheet is read-only and you did not submit | Someone submitted it, or the term closed. Check with the HOD |
| A pupil is missing from the roster | They are not enrolled in that class for this session; tell the administrator |
| The totals differ from your paper record | Check the weights first; if they still differ, report it — do not overwrite the total |
