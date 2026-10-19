# 07 — Year group master

**Actor:** one per year group. The final signature: releases a year group's results and
owns the year-group performance pack.
**Where:** the school's subdomain · desktop 1024.

---

## Part A — Agent playwright

Requires HOD-approved sheets from `06`.

| # | Do | Expect |
|---|---|---|
| 1 | `/reports/year-group` | Each class in the year group, and which subjects are still outstanding |
| 2 | Open a class's status | The list names the exact subjects not yet approved — not just a count |
| 3 | `/tenant/approvals` | The year-group section lists what is ready to release |
| 4 | Try to release **before** every subject is approved | Refused, or the outstanding subjects are listed — record which; either is acceptable, silent release is not |
| 5 | Approve a complete year group (`/tenant/approvals/year-group/{id}/approve`) | Status becomes released; parent portals now show the report card |
| 6 | Open `/reports/performance` | Whole-school/whole-group comparison: classes and subjects |
| 7 | `/reports/performance/classes` and `/subjects` | Break-downs agree with the summary numbers |
| 8 | Export the performance PDF (`/reports/performance/pdf`) | A4 (`%PDF-`, 595.28×841.89), QR present |
| 9 | Create a share link (`/reports/performance/share`) | Tokenised URL opens without a login; it can be revoked |
| 10 | Open the QR's verification page (`/pv/...`) | Confirms issuer, term and year group; shows **no** individual pupil results |
| 11 | Open a single pupil's report card | Available now that the group is released; totals match the class report |

### Assertions

- Releasing is **per year group**, not per pupil: a half-released group is a defect.
- The shared performance link never exposes a named pupil's marks.
- Revoking the share link kills it immediately; the printed QR code still verifies
  (that is the design — the code is permanent, the link is not).

---

### Run 2026-10-19 — year group master — school `stmarys` (1,200 pupils)

Year group master `yearmaster@stmarys.ng` (**Mrs. Grace Okonkwo**) · JSS 1.

| Step | Result | Note |
|---|---|---|
| 1 `/reports/year-group` | **FAIL → FIXED → pass** | **P-YGM-01**: *"No class subjects in this year group"* although JSS 1 A had five. No classroom was linked to a year group — the setup wizard created classes and year groups but never joined them, and nothing said so. Classes are now linked at creation; this school's 18 classes were linked through **Year Groups → Add a class**, after which the screen listed every subject with its form-teacher / HOD / year-group stage and the class average |
| 2 Outstanding subjects named | **pass** | Each row names the exact subject and stage — *Form teacher approved · HOD pending* |
| 3 `/tenant/approvals` | **pass** | *Final Approve* appears only on HOD-approved sheets; the rest read *Awaiting HOD* |
| 4 Release before every subject is approved | **FAIL → FIXED → pass** | First press released a single subject and left the class half-final (**P-YGM-02**), and on a full run the request died at 30 s after queueing 335 emails (**P-YGM-03**). Now one **Release JSS 1** button: it refused with *"Cannot release yet — these subjects still need HOD approval: Mathematics (JSS 1 A), English Language (JSS 1 A), Social Studies (JSS 1 A)."* |
| 4b Empty Actions column | **FAIL → FIXED → pass** | **P-YGM-04**: a column with nothing in it for the year group master. Columns are now *Class · Subject · HOD · Status* |
| 5 Release a complete year group | **pass** | After the remaining sheets were HOD-approved: *"Results for JSS 1 released — 5 subjects final-approved."* Second press returned in **820 ms** with no repeat email |
| 6–10 performance reports / PDF / share / QR | **pass, as admin** | `/reports/performance` is **ROLE_ADMIN** — the year group master uses `/reports/year-group` for their own group. Signed in as the principal: school brief, class and subject break-downs agree; PDF 46,504 bytes, A4 595.28 × 841.89. **Share link** failed with a 400 on the default *All year groups* (**P-YGM-05**, fixed): it now creates a signed 30-day link, the tampered link and a wrong QR code both return **410 Gone** |
| 11 Parent's report card after release | **pass** | Before release: *"Results not released yet"* and the PDF answered **409**. After release: the card renders for Arraghibun Bello and the PDF is a 42,311-byte A4 document |
---

## Part B — User handout

### What you are for

You are the last signature. Before you, the HOD checked the subject. After you, a parent
holds the report card. You are also the person who can answer "how did this year group
do?" with numbers rather than impressions.

### Releasing a year group

1. Sign in and open **Teaching → Approvals → Year group**.
2. Check **Reports → Year Group** first. It lists every class and every subject, and
   names the subjects that are still outstanding.
3. If anything is outstanding, **do not release.** Chase the HOD for that subject. A
   released group with a blank column means reprinting every card.
4. When the list is clear, release the year group. Parents can now see the report card.

### The performance pack

1. Open **Reports → Performance**. You get the year group's overall figures, then the
   per-class and per-subject break-downs.
2. Download the **PDF** for a board meeting or an inspection. It carries a code and a QR
   in the footer.
3. If you need to send it to someone without an account, create a **share link**. It
   expires and you can revoke it. The QR code on the printed copy keeps verifying after
   the link is revoked — that is deliberate: the paper is already out there.

### What you should never do

- **Never release before every subject is approved.** "I will fix it later" is not
  possible: parents will already have downloaded the card.
- **Never share the performance link on a public group.** It is tokenised, but it is
  still a document about your school. Revoke it when the conversation is over.
- **Never use the performance pack to publish individual pupils' marks.** It does not
  contain them, and if it ever does, that is a defect to report.

### When it goes wrong

| What you see | What to do |
|---|---|
| A subject is still outstanding after the HOD approved it | Check the class was confirmed by the form teacher; the chain runs form teacher → HOD → you |
| A parent says the report card is missing | Confirm the **year group** was released, not just the class |
| The performance pack numbers disagree with the class reports | Stop and report — both read the same stored marks |
| The share link is opened by the wrong person | Revoke it; the printed QR stays valid by design |
