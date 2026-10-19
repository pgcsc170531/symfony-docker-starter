# 06 — Head of department (HOD)

**Actor:** one per department. The academic signature on results: approves or rejects a
subject's marks before the year group master releases them.
**Where:** the school's subdomain · desktop 1024 (this is a reporting job).

---

## Part A — Agent playwright

Requires a submitted + form-teacher-confirmed sheet from `04` and `05`.

| # | Do | Expect |
|---|---|---|
| 1 | `/reports/hod` | Only this department's subjects, across classes |
| 2 | `/reports/hod/{id}` | Subject report: distribution, per-class averages, failures |
| 3 | `/tenant/approvals` | The HOD section lists sheets waiting for **this department only** |
| 4 | Approve one (`/tenant/approvals/hod/{id}/approve`) | Status moves to HOD-approved; it appears for the year group master |
| 5 | On a second sheet, **reject** with a reason (`/hod/{id}/reject`) | Sheet returns to the subject teacher; the reason is visible to that teacher |
| 6 | Approve a sheet from another department by URL | 403 or 404 — never another HOD's queue |
| 7 | `/reports/student` for a pupil in a subject you own | Read-only view opens; you cannot edit marks there |
| 8 | Re-check `/reports/hod` after approvals | Outstanding count falls as sheets are approved |

### Assertions

- A rejection **must** carry a reason; a rejection with no reason is a defect.
- The form teacher's confirmation cannot be skipped: an HOD should never see an
  unconfirmed sheet.
- Approving twice is idempotent, not double-counted.

---

### Run 2026-10-19 — HOD — school `stmarys` (1,200 pupils)

HOD `hod@stmarys.ng` (**Dr. Sadiq Lawal**) · Sciences.

| Step | Result | Note |
|---|---|---|
| 1 `/reports/hod` | **FAIL → FIXED → pass** | First run: *"Sciences — No subjects in this department"* because every subject was filed under Humanities. Subjects could not be edited at all (**P-ADMIN-06**); after the new inline department control the HOD saw exactly *Basic Science, Computer Studies* |
| 2 `/reports/hod/{id}` | **pass** | Subject master: per-pupil total, grade, class average 84 |
| 3 `/tenant/approvals` | **pass** | Only this department's sheets: JSS 1 A · Basic Science and Computer Studies |
| 3b View a sheet read-only | **added** | **HOD request**: a **View** link opens the submitted sheet with **0 inputs** — components, totals, grades, and the counts that matter: *67 pupils · 3 marked · average 84.0 · 3 credits · 0 passes · 0 fails*. The editable entry screen still answers **403** *"This subject assignment belongs to another teacher."* |
| 4 Approve | **pass** | *"Subject results approved."*; the sheet moved to the year group master |
| 5 Reject with a reason | **FAIL → FIXED → pass** | **P-HOD-02**: the reject form carried **only a CSRF token**, so a sheet could be sent back with no reason at all. The reason is now required, stored (`hod_reason`) and shown to the subject teacher as *"Sent back by the HOD — Two pupils are missing CA3 …"* |
| 6 Another department's sheet | **pass** | `/tenant/results/sheet/2` → **403** *"You do not review this class subject."* |
| 7 `/reports/student` | **pass** | Read-only report card; no way to edit marks |
| 8 Re-check the queue | **pass** | Approved sheets leave the HOD's action list |

---

## Part B — User handout

### What you are for

You are the academic check between the teacher who typed the marks and the year group
master who releases them. You see numbers; you do not see money, and you cannot edit a
mark — by design.

### Approving a subject

1. Sign in and open **Teaching → Approvals**.
2. The **HOD** section lists sheets whose subject belongs to your department.
3. Before approving, look at the report for that subject (**Reports → HOD**): class
   averages, the grade distribution, and any pupil with an impossible total.
4. Approve if the marks look right. The sheet moves on to the year group master.

### Rejecting a subject

Reject when something is wrong — a column of identical scores, a pupil above the maximum,
a class average that is out of line with the other arms.

1. Click **Reject**.
2. **Write the reason.** It goes to the subject teacher and nowhere else. "Check JSS 2A
   again" is not a reason; "JSS 2A has 32 scores above 90, verify against scripts" is.
3. The sheet unlocks for that teacher only.

### What you should never do

- **Never approve to clear your queue.** Once the year group master releases the year,
   a parent has the report card.
- **Never ask a teacher to change a mark outside the system.** A change that is not
  recorded is indistinguishable from a fabrication.
- **Never approve a sheet whose form teacher has not confirmed the class.** If you can
  see one, record it as a defect.

### When it goes wrong

| What you see | What to do |
|---|---|
| A sheet is missing from your queue | The form teacher has not confirmed the class, or the subject has no submitted sheet |
| A teacher says they fixed a rejection and you see no change | They must resubmit; a rejection returns the sheet to draft |
| The subject report disagrees with the entry screen | Stop and report it — the report reads the same stored marks, so they cannot legitimately differ |
