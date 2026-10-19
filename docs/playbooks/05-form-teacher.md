# 05 — Form teacher

**Actor:** the teacher responsible for one class. Keeps the register, rates behaviour,
confirms the class is complete for result approval, and writes the term remark.
**Where:** the school's subdomain · phone 288 first (register is taken on a phone).

---

## Part A — Agent playwright

Sign in with the **form-teacher** account (`/staff-assignments/form-teacher` in
`02-tenant-admin.md`).

| # | Do | Expect |
|---|---|---|
| 1 | `/my-class` | This class only — pupils, counts, links to attendance, behaviour and results |
| 2 | `/my-class/attendance` | Today's register offered; the pupil list is the class roster |
| 3 | `/my-class/attendance/{today}` — mark one present, one absent, one late; save | Saved; reload shows the same marks |
| 4 | Save the same day again with a change | The day is **updated**, not duplicated |
| 5 | `/my-class/attendance-summary` | Days present/absent and percentage per pupil for the term |
| 6 | `/my-class/behaviour` — rate two pupils, save | Reload shows the ratings; they appear on the report card preview |
| 7 | `/reports/remarks` — write a term remark for one pupil | Saved; visible on the report card preview |
| 8 | `/reports/form-teacher` and the class report | Every pupil against every subject; positions shown |
| 9 | `/tenant/approvals` | The form-teacher section lists this class's submitted sheets |
| 10 | Confirm the class (`/tenant/approvals/form-teacher/{id}/confirm`) | Class marked confirmed; the HOD group now shows it |
| 11 | Open a second class's register by URL | Refused — a form teacher only owns one class |

### Assertions

- One register per class **per day**: after step 4 the row count for that day is still 1.
- An amendment keeps the earlier value in the change log.
- Behaviour ratings are stored per **term** — a new term starts blank, the old term stays
  in the pupil's history.
- At 288: the register's Present/Absent/Late controls are ≥44px and the roster does not
  scroll sideways.

---

### Run 2026-10-19 — form teacher — school `stmarys` (1,200 pupils)

Form teacher `formteacher@stmarys.ng` (**Mr. Bello Umar**) · JSS 1 A · First Term · 67 pupils.

| Step | Result | Note |
|---|---|---|
| 1 `/my-class` | **pass** | *JSS 1 A · First Term · 67 students*, links to attendance, behaviour, remarks and printed reports; *"5 subjects are still awaiting final approval"* |
| 2 Register | **pass** | The day's register is offered; the roster is the class roster |
| 3 Mark A + L, save | **pass** | First pupil **absent**, second **late**; header recalculated to *66 present · 1 absent*; reload kept both |
| 4 Amend the same day | **pass** | First pupil back to **present** → *67 present · 0 absent*; the database holds **one** register row for 2026-10-19 and 66 `P` + 1 `L` — updated, not duplicated |
| 5 `/my-class/attendance-summary` | **pass** | 67 rows with days present/late/absent/excused and a percentage |
| 6 Behaviour — rate two pupils | **pass** | 18 traits (11 affective + 7 psychomotor), 5-point scale, saved per trait; *Save & next student* moves on; header went *0 → 1 → 2 of 67 fully rated* |
| 7 Term remark | **pass** | 180-character limit, suggestion offered, saved and attributed to `formteacher@stmarys.ng`; counter *1 of 67 written* |
| 8 `/reports/form-teacher` → broadsheet | **pass** | 67 rows × 5 subjects, positions 1…67, average column; unmarked subjects show `—` rather than 0 |
| 9 `/tenant/approvals` | **FAIL → FIXED → pass** | **P-FORM-01**: every subject read *pending* — a submitted sheet and an untouched subject looked identical. Now *submitted* / *not submitted* / *approved* |
| 10 Confirm the class | **pass (guard)** | Correctly refused: *"Cannot confirm yet — no submitted results for: Mathematics, English Language, Social Studies, Computer Studies."* The happy path could not be exercised because only Basic Science has a submitted sheet in this school |
| 11 Another class by URL (`?classroom=2`) | **pass (safe)** | The page silently rendered **JSS 1 A**, the teacher's own class — no other class's pupils were exposed. Logged as backlog B-11 (say so, or 403) |

**Mobile:** the register's P/L/A/E controls are `min-h-[44px]` and the roster does not scroll the page sideways.

---

## Part B — User handout

### Your three jobs

1. **Take the register.**
2. **Rate behaviour and write the term remark.**
3. **Confirm your class's results** so the HOD can approve them.

### The register

1. Sign in and open **My Class → Attendance**.
2. Pick the date — today by default. A past date can still be corrected.
3. Mark each pupil **Present**, **Absent** or **Late**, then save.
4. There is one register per class per day. Saving again updates that day; it does not
   create a second one, so you can correct a mistake safely.

Attendance has nothing to do with fees. A pupil who owes money is still marked present;
a pupil who is absent is not "unpaid".

### Behaviour and remarks

1. **My Class → Behaviour**: rate each pupil against the traits (Punctuality, Neatness,
   and so on). Ratings are per term.
2. **Teaching → Term Remarks**: write the form teacher's comment for each pupil.
   Do this **before** the results are released — the report card is built from what is
   stored at that moment.

### Confirming results

Your confirmation is the second signature in a four-stage chain. You are not checking the
marks; you are checking **completeness**:

- every pupil in your class is on the sheet,
- every subject the class takes has a submitted sheet,
- nobody has been added or removed since the term started.

Open **Teaching → Approvals**, find your class under the form-teacher section, and
confirm. If a subject is still missing, do not confirm — chase the subject teacher
instead. Confirming early is how a report card goes out with a blank column.

### When it goes wrong

| What you see | What to do |
|---|---|
| A pupil is not on your register | Their enrolment for this session is wrong — tell the administrator |
| You cannot confirm the class | A subject has not been submitted yet; the approvals screen shows which |
| A remark is missing from the report card | It was written after the results were released — tell the administrator before printing |
| You marked the wrong pupil | Change it and save the same day; the change log keeps the earlier value |
