# Fees per arm & subjects per pupil — design proposal

**Status:** PROPOSAL — nothing built yet. Decisions wanted in §A6 and §B7.
**Date:** 1 November 2026
**Scope:** two related gaps, both about *"the class is not the smallest unit"*.

---

## 1. The two problems, in the school's words

**A. One price per level is wrong when the arms are different products.**
SS 1 Science and SS 1 Arts sit in the same level, and the science arm costs more.
Today a fee is entered once for "SS 1" and copied to every arm, so the school
cannot charge SS 1 Science what it costs to run it. Worse, the fee schedule
*prints* a single SS 1 column, so the number on the page is whichever arm happened
to be read last — silently, with no warning.

**B. A class list is wrong when the pupils inside it study different subjects.**
Two pupils in SS 1 Arts: one takes CRK, the other IRS. Or Home Economics vs Food
& Nutrition; Technical Drawing vs … . Today `ClassSubject` says *this class offers
these subjects*, and every pupil in the class is assumed to take all of them —
including both sides of a choice only one of them can make.

Both are real, and both are cheap to fix if the model is chosen well. That is
what this document is for.

---

## 2. What is true in the code today

| Fact | Where | Consequence |
|---|---|---|
| A fee row is **per arm** already: `fee_structure(classroom_id, term_id, fee_item_id, amount)` | `src/Entity/Tenant/FeeStructure.php` | The database can already hold two different SS 1 prices. Nothing is missing at the storage level. |
| Invoices read the **pupil's own classroom** | `AdmissionService:117`, `InvoiceController:164`, `EnrollmentController:174`, `QuickAdmissionController:540`, `PaymentController:123` | If two arms are priced differently, pupils are already billed correctly. The bug is only in the pricing screen and the schedule report. |
| The fee schedule **collapses arms into one level column** | `FeeController::schedule()`, grouped by `App\Service\Finance\ClassLevels` | `$matrix[$item][$level] = $fs->getAmount()` — last arm wins. Level totals are computed once, so they are wrong too when arms differ. |
| Onboarding writes **one price to every arm** | `OnboardingController` fee step | Fast and right for the common case; it is the *only* way to price today. |
| `fee_structure` has **no unique constraint** on (classroom, term, fee_item) | entity attribute list | Duplicate rows are possible; a per-arm editor must not create a third row for a pair that already has two. |
| A classroom's subjects are `ClassSubject(subject, classroom, session, category core\|elective)` | `src/Entity/Tenant/ClassSubject.php` | The list belongs to the **class**, never to a pupil. |
| Everything that asks "what does this pupil study?" asks the class | `ResultSheetBuilder:54`, `ResultTrendBuilder:56`, `ApprovalController:180/425/457`, `TermRelease:32/55`, `ResultReportController:137/225` | One change point per caller, not one per screen — good news for Part B. |

---

# Part A — Fees per arm

## A1. The decision I propose

**Keep the level price as the default, and allow a per-arm override.**

- Entering a price for a level keeps working exactly as it does now (one box, all
  arms) — that is the 90% case and it must not become a 6-row chore.
- Any arm whose amount differs from its level gets its own row.
- The fee schedule **never hides a difference**: a level whose arms disagree is
  drawn as an expandable group that shows each arm, and the level's summary number
  says "from ₦X" rather than pretending to be one price.

This is the smallest change that makes the science/arts case correct, and it
leaves the onboarding wizard alone.

## A2. Data — no new table

`fee_structure` already stores per-arm amounts, so this is an **editor + report**
change, not a migration. Two small schema items are still worth having:

1. **`UNIQ_fee_structure (classroom_id, term_id, fee_item_id)`** — a fee cannot
   exist twice for the same arm, term and item. Added by
   `app:tenant:update-schema` after de-duplicating existing rows (keep the most
   recently updated).
2. **`fee_structure.is_override`** (`tinyint`, default 0) — *optional*. Marks a
   row a human set deliberately for one arm, so a later "apply to all arms" can
   either respect it or overwrite it knowingly. Without this flag the system must
   guess "differs from the level" by comparison, which is fine, but then a school
   that sets one arm *back* to the level price loses the intent. **I recommend
   adding it**; it is one boolean and it removes a class of surprise.

## A3. Screens

### A3.1 Fee schedule — read view (`/finance/fees/schedule`)

Today: one column per level. Proposed:

```
                        SS 1 ▾            SS 2            JSS 1
                        from ₦42,000      ₦38,000         ₦30,000
  Tuition               from ₦35,000      ₦32,000         ₦25,000
  Science levy           ₦5,000 ✱         —               —
  Books                  ₦2,000           ₦2,000          ₦2,000
```

- A level whose arms all agree prints **exactly as it does now** — one number, no
  change for the nursery, whose arms are identical.
- A level whose arms disagree prints `from ₦X`, and is **expandable** (the
  collapsible column you described): expanding lists every arm under its own
  sub-column, per item.
- `✱` marks an item that only *some* arms pay — the science levy is the textbook
  case.
- Totals (`Tax / one-time / recurring`) are computed **per arm**, then summarised
  per level as a range. A total is never the sum of "last arm wins".

### A3.2 Fee amounts — write view (new: `/finance/fees/amounts` or an edit mode
on the schedule)

One row per item, one column per level by default. Each level column has a
**"arms differ"** disclosure:

```
  Tuition                           SS 1                    SS 2
  ┌────────────────────────────┐   ┌──────────────────┐    ┌───────────┐
  │ ₦35,000                    │   │ ₦35,000          │    │ ₦32,000   │
  └────────────────────────────┘   └──────────────────┘    └───────────┘
                                    ▾ 2 arms differ
                                      Science   ₦40,000
                                      Arts      ₦35,000 ✓ same as level
```

Rules:

- Editing the level box writes **every arm that is not an override**; arms marked
  as overrides are kept and listed underneath, with a "same as level" reset.
- Editing an arm box writes that arm only and marks it an override.
- On save: one flash line that states what actually happened
  ("Tuition: SS 1 Science set to ₦40,000; 2 arms kept at the level price"), not a
  generic "Saved".

### A3.3 Onboarding

**No change to the flow.** The wizard prices a level and writes all its arms, which
is right for a school starting from nothing. After onboarding, the amounts screen
from A3.2 is where a school differentiates. (The wizard gains a single line of
copy: *"Every arm starts on this amount — you can price an arm differently later
in Finance → Fee amounts."*)

### A3.4 Invoices and receipts

Untouched. They already read the pupil's classroom. **One test to add:** create a
pupil in SS 1 Science and one in SS 1 Arts, generate both invoices, assert the two
totals differ by exactly the override.

## A4. Behaviour rules

| Situation | Rule |
|---|---|
| Level price changed, arms not overridden | All those arms move. Expected: "I changed SS 1's price." |
| Level price changed, an arm is overridden | Override stays; the screen says so before saving. |
| New arm added to a level (Smart Class Creator) | It inherits the level price — no arm is ever born priceless. |
| Pupil promoted / moved to another arm | The **invoice** follows the arm; an already-issued invoice is not recalculated (existing rule: invoices are historical). |
| Same item, same arm, two rows | Prevented by the new unique constraint. |
| Term with no fee rows | Nothing to show; the screen says so plainly rather than printing zeros. |

## A5. Edge cases worth naming now

- **Science levy for one arm only.** Allowed: an arm can have an item its siblings
  do not. The schedule marks it `✱` and the level says "from".
- **Printed fee schedule / PDF.** Must print the arms, not the level, when they
  differ — otherwise the paper disagrees with the invoices.
- **"from ₦X" and totals.** A level total is a *range* when arms differ. I prefer
  a range (`₦42,000 – ₦47,000`) over a single misleading number.
- **Discounts and scholarships** apply to a pupil, unaffected by this change.

## A6. Decisions wanted — Part A

| # | Question | My recommendation |
|---|---|---|
| A1 | Per-arm override, or make *every* price per arm (drop levels)? | **Override.** Levels keep the common case to one box, and level columns stay readable. |
| A2 | Add `is_override`, or infer an override by comparing amounts? | **Add the flag.** It survives a school setting an arm back to the level price. |
| A3 | On a level change, do overrides survive? | **Yes**, and the confirm line names them. |
| A4 | Level totals when arms differ: a range, or the cheapest arm? | **A range** ("₦42,000 – ₦47,000"). A single number is a lie. |
| A5 | Where does the edit live: on the schedule, or its own screen? | **Its own screen** (`/finance/fees/amounts`), with a link from the schedule. The schedule is a report; a report that edits is a trap. |
| A6 | Do fee items themselves need a "only some arms pay this" flag? | **No.** The existence of the row *is* the flag. |

---

# Part B — Subjects per pupil

## B1. The decision I propose

**A pupil's subjects default to their class's list, and can be narrowed per pupil
once a choice group is involved.**

Two new concepts, and nothing else changes for the majority of pupils:

- **`SubjectChoiceGroup`** — a named set of alternatives within a level or arm,
  e.g. *"Religion"* = {CRK, IRS}, *"Vocational"* = {Home Economics, Food &
  Nutrition}, *"Technical"* = {Technical Drawing, Woodwork}. A group states how
  many subjects a pupil must take from it (**exactly one**, default).
- **`StudentSubject`** — the rows that record what a pupil actually takes, for a
  session. **No rows ⇒ the pupil takes the class list** (today's behaviour, zero
  migration, zero risk for the 95% of pupils with no choice).

The class list remains the catalogue. The pupil list is the truth for marking,
cards, approvals and positions.

## B2. Data

```
subject_choice_group
  id, name, session_id (NULL = every session), min_choices (1), max_choices (1), position

subject_choice_group_member
  id, group_id, subject_id            -- CRK and IRS in the same group

student_subject
  id, student_id, subject_id, classroom_id, session_id, source ('class' | 'choice')
  UNIQUE (student_id, subject_id, session_id)
```

Rules the database can hold:

- A subject may belong to **at most one group per session** (a subject cannot be
  both "either CRK or IRS" and "either CRK or French").
- `min_choices = max_choices = 1` is the ordinary case; `0..2` allows
  "pick any two of these five".

Migration: **none needed for existing pupils.** `student_subject` starts empty;
empty means "the class list".

## B3. Where "the pupil's subjects" is answered today (all must move to one service)

| Caller | Line | Today | Must become |
|---|---|---|---|
| `ResultSheetBuilder` | 54 | class's `ClassSubject` rows | the pupil's list |
| `ResultTrendBuilder` | 56 | class's rows | the pupil's list |
| `ApprovalController` | 180, 425, 457 | class's rows | per-pupil completeness, class subject still approvable when every taker is done |
| `TermRelease` | 32, 55 | "every `ClassSubject` approved" | "every *taken* subject approved" |
| `ResultEntryController` | 380 | `findOneBy(classroom, subject, session)` | unchanged — the teacher still enters by class-subject; only **which pupils appear** is filtered |
| `ResultReportController` | 137, 225 | class's rows | broadsheet columns = union of subjects taken, blank where not taken |
| `SchoolPerformanceBuilder` | 264 | class's rows | aggregate over takers, not over the class |

**The design intent is one service** — `App\Service\Subjects\PupilSubjects`:

```php
subjectsFor(Student $student, Classroom $classroom, Session $session): list<ClassSubject>
// rows in student_subject, else the class list
```

Every caller above uses it. That way CRK/IRS cannot be honoured on the card and
ignored in the broadsheet — which is exactly the class of bug this codebase has
paid for before (the CA-count sweep).

## B4. Screens

### B4.1 Pupil profile → "Subjects"
A compact list: core subjects ticked and locked, choice groups shown as a radio
set ("Religion: ○ CRK ○ IRS"), and a "same as the class" badge when nothing has
been chosen. One save button, 44px targets, works at 360px.

### B4.2 Class → "Give a group choice to this arm"
Pick an arm, pick a choice group, then mark each pupil: CRK / IRS. A single
screen with one row per pupil and two radio buttons — the way a form teacher
actually does it, on paper, in one sitting.

### B4.3 Result entry
Unchanged for the teacher: still one sheet per class-subject. But the pupil rows
shown are only those who take it, with a count line — *"24 of 31 pupils take
this subject"* — so a teacher is never confused by the seven missing names.

### B4.4 Report card
Lists only the pupil's subjects. **Position** is computed within the subjects the
pupil takes (already the case) and, for a choice-group subject, **against the
other takers of that subject**, not against the whole class.

## B5. Rules

| Situation | Rule |
|---|---|
| Pupil has no `student_subject` rows | Takes the class list. Nothing to configure. |
| A choice group exists in the arm | The pupil **must** have exactly one member chosen before marks are entered (or the sheet says "subject not chosen" and refuses). |
| A core subject | Always taken; cannot be removed per pupil. |
| Pupil swaps CRK → IRS after CRK marks exist | Marks are **kept but flagged**, and the card refuses to print CRK. A swap is a deliberate act; it is confirmed with the consequence in the sentence. |
| Pupil joins mid-term | Inherits the class list; the form teacher picks their option. |
| Move to another arm | `student_subject` rows are per (pupil, session); moving arms re-resolves and warns if the new arm does not offer a chosen subject. |
| Fees | **Unaffected.** Subjects do not price anything today and should not start. |
| Timetable / teacher assignment | Unaffected: `SubjectTeacher` stays class-level. |

## B6. Edge cases worth naming now

- **Approval completeness.** "Every subject of this class is submitted" becomes
  "every subject of this class is submitted for every pupil who takes it". The
  HOD's board must not demand marks for IRS from a CRK pupil.
- **Broadsheet width.** A class with 3 optional subjects gets 3 extra columns,
  mostly blank. Acceptable; it is what the paper broadsheet does today.
- **Class average per subject.** Must be the average **of takers**.
- **Transitions.** A pupil with no choice rows who later gets rows must not lose
  marks already entered for a subject they no longer take.
- **Deleting a group.** Members are not deleted; pupils keep whatever they chose.

## B7. Decisions wanted — Part B

| # | Question | My recommendation |
|---|---|---|
| B1 | Choice expressed per **pupil** (B1) or per **arm** (SS 1 Science vs Arts as two arms)? | **Per pupil.** Arms already exist for stream differences; a choice inside one arm (CRK/IRS) must not require creating an arm. |
| B2 | Does a choice group belong to a level, an arm, or the session? | **Session-wide**, attached to subjects, with an optional arm restriction later. Simplest thing that covers CRK/IRS and Trade groups. |
| B3 | Materialise the class list into `student_subject` for every pupil, or leave it empty and resolve? | **Leave empty, resolve.** No migration, no 1,000-row writes per term, and the class list keeps working if a school never uses choices. |
| B4 | Where does the form teacher enter the choice — pupil profile, or a per-arm grid? | **Both**, but the **grid** is the one that will be used; build it first. |
| B5 | If a pupil swaps after marks exist, keep or void the marks? | **Keep, flag, and refuse to print.** Deleting marks silently is how a school loses a term. |
| B6 | Should positions be within the subject's takers? | **Yes** — a CRK pupil must not be ranked against IRS marks. |
| B7 | Is this needed for **primary/nursery**? | No, and the screens must not clutter those schools: choice groups only appear once a group exists. |

---

## 3. Phasing

| Phase | Contents | Why this order |
|---|---|---|
| **1** | Part A read view: arms in the fee schedule, "from ₦X", per-arm totals, printed schedule | The report is currently **wrong**, not just limited. Fixing it cannot wait behind an editor. |
| **2** | Part A editor (`/finance/fees/amounts`) + `is_override` + unique constraint + invoice test | Makes the science/arts case actually enterable. |
| **3** | Part B model (`SubjectChoiceGroup`, `StudentSubject`, `PupilSubjects`) with **no UI**, and every caller moved onto the service | The refactor is the risk; do it while nothing can be misconfigured. |
| **4** | Part B UI: per-arm choice grid, pupil profile subjects, result-entry counts, card/broadsheet/approval filters | Now the behaviour is visible and testable end to end. |

Rough size: **1** ≈ half a day, **2** ≈ a day, **3** ≈ a day and a half,
**4** ≈ two days. Nothing here needs a new server or a downtime window.

## 4. What I will not do (so we agree on it now)

- I will not make every fee per-arm. Levels stay, and a school with identical arms
  keeps one box.
- I will not touch invoices, payments or discounts — they already read the arm.
- I will not backfill `student_subject` for existing pupils.
- I will not let a subject choice affect money.
- I will not delete a mark when a pupil swaps subject.

## 5. Decision log — to be filled in before phase 1 starts

| # | Decision | Answer | Date |
|---|---|---|---|
| A1 | Override vs all-per-arm | | |
| A2 | `is_override` column | | |
| A3 | Overrides survive a level change | | |
| A4 | Range vs single number for a differing level | | |
| A5 | Edit on the schedule or its own screen | | |
| B1 | Choice per pupil, not per arm | | |
| B2 | Group scoped to the session | | |
| B3 | Resolve the class list, do not backfill | | |
| B4 | Grid first, pupil profile second | | |
| B5 | Keep and flag marks on a swap | | |
| B6 | Positions within takers | | |
