# Handout 02 — School administrator (principal / proprietor)

**Your job:** set the school up once, keep its people and classes right, and hand out the
right access.
**Where you work:** your school's Edus address. The **Administration** group is yours
alone; the phone layout hides it behind the menu.

---

## First day: set the school up

1. Sign in — the setup wizard opens.
2. Work through the steps in order: school identity, classes, sessions and terms, fee
   items, fee amounts, summary.
3. On the **Structure** step, tick the class levels and type the arms (e.g. `A,B,C`), then
   generate.

**What you should see:** one class per level and arm, and each class linked to its year
group (JSS 1 A belongs to JSS 1). If the year group master's screen is empty later, this
is the first thing to check.

**Do not clear the level list** — the wizard refuses, because a school with no classes
cannot bill or register anyone.

## Add your people

1. **Administration → Staff** — add each teacher with a name and a login.
2. **Administration → Subjects** — create the subjects and pick the department for each
   one in the form.
3. **Administration → Departments** — one per subject area.
4. Assign the four roles from the **Staff assignments** screens:
   - **Subject Teachers** — who marks which class and subject;
   - **Form Teacher** — who owns each class;
   - **HOD** — who approves each department (assign one per department);
   - **Year Group Master** — who releases each year group.

**What you should see:** every assignment listed with the person's **name**, the
class/subject and the term.

A subject with the wrong department leaves its HOD with an empty queue. Fix it in
**Administration → Subjects** — each row has a department selector and a **Save** button;
you do not have to delete and recreate the subject.

## Bring the pupils in

1. **Admissions → Bulk Import**, choose your CSV.
2. Check the preview, then confirm.

**What you should see:** a report with the number of pupils, guardians and bills created.
Admission numbers, one guardian per family (siblings share the guardian) and one bill per
pupil are created automatically.

## Let parents in

1. Parents receive a login using the phone number on their child's record.
2. To reach a family yourself, open **Administration → Parents**.

## Close a term

The chain has four signatures and it will not skip one:

1. Subject teacher **submits** each sheet.
2. Form teacher **confirms the class** (all subjects must be submitted).
3. HOD **approves** their department's sheets — or sends them back with a reason.
4. Year group master presses **Release**. Every subject must be HOD-approved; the release
   is all-or-nothing, and parents only see the report card after it.

## Decide how admission numbers are written

1. Open **Settings → General**.
2. Fill **Admission number pattern** — for example `SMC/{YY}/{SEQ:3}`.
3. Save.

`{YEAR}` is 2026, `{YY}` is 26, `{SEQ}` is the next number and `{SEQ:3}` pads it
to three digits, so the next admissions become `SMC/26/001`, `SMC/26/002`. Leave
it empty for `ADM/{YEAR}/{SEQ}`.

A number typed in a bulk-import file is kept exactly as typed. If it is already
on roll, that **row** is skipped and named in the report — the rest of the file
still imports.

## Turn online payment off (your own school)

1. **Settings → General → School fees collection**.
2. Choose **Offline** to stop offering "Pay online" to parents, or **Both** to
   offer both. New schools start on **Both**.

If the platform operator has switched online collection off, a notice at the top
of the page says so and your choice will not take effect until they switch it
back. Transfers with proof of payment keep working throughout.

## What you should never do

- **Never share the administrator login** — it can see every child's money and marks.
- **Never delete a subject, class or pupil to fix a mistake** — it takes their bills and
  marks with it. Change the record instead.
- **Never approve results yourself to save time** — an admin *can*, but the signatures
  exist so the teacher, the class and the department each stand behind the marks.

## When it goes wrong

| What you see | What to do |
|---|---|
| A teacher sees no classes or subjects | Their **Subject Teacher** assignment is missing, or was given to the wrong person |
| An HOD's queue is empty | The subjects are filed under another department |
| The year group screen is empty | Classes are not linked to the year group — **Administration → Year Groups → Add a class** |
| An import stops on a duplicate | A pupil's admission number or a sibling's guardian already exists — open that pupil and correct it |
| The portal has gone read-only | The school's subscription has expired. **My Subscription** → settle it |
