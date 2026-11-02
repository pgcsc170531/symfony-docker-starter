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

# Part B — A pupil who does not take a subject

> **Revised 2 November 2026, on the owner's instruction.** The first draft proposed
> "choice groups" (Religion = CRK *or* IRS, one of each group). **That is dropped.**
> The ask is simpler than that: pick a pupil, untick the subject they do not offer,
> done — and the reason is concrete: *we must not record a fail for a pupil who
> does not offer IRS.* The arms already do the differentiating (SS 1 Science vs
> SS 1 Arts); what is missing is one tick inside an arm.

## B1. The decision I propose

**An exemption list, and nothing else. In every segment — nursery, primary,
junior, senior.**

The class list stays exactly as it is — `ClassSubject` still says *this arm offers
these subjects*. On top of it, a pupil's record says **which of those subjects they
do not take**. No pupil with an exemption list keeps today's behaviour, byte for
byte.

- **No groups.** No "choose one of these two", no min/max, no alternative sets.
- **No backfill.** No rows means "takes the class list" — every existing pupil,
  every school that never uses this, is untouched.
- **One gesture.** A ticked checklist: everything ticked by default, the form
  teacher unticks the one the child does not offer.
- **No segment gets a special case.** A nursery arm whose pupils do not take
  *Rhymes*, a primary arm that skips *French*, an SS 1 Arts pupil who offers IRS
  instead of CRK — all three are the same untick, on the same screen, with the
  same effect. Nothing asks "what kind of school is this?" before agreeing.

The rule the school actually asked for, stated as a rule:

> **A subject a pupil does not take is not marked, not counted, not printed, and
> cannot produce a fail.**

## B2. Data

```
student_subject_exemption
  id
  student_id           -- the pupil
  class_subject_id     -- the subject *in this arm and this session*
  recorded_by          -- staff id (who unticked it)
  recorded_at
  reason               -- nullable, free text ("offers IRS instead")
  UNIQUE (student_id, class_subject_id)
```

Why `class_subject_id` and not `subject_id`: the class subject already carries the
classroom **and** the session, so an exemption for "IRS in SS 1 Arts, 2025/2026"
cannot leak into another arm, another level or next year. Nothing else needs a
column.

Deleting a `ClassSubject` cascades its exemptions away with it; deleting a pupil
does the same. No other entity is touched.

## B3. Where "this pupil's subjects" is answered today (all must move to one service)

| Caller | Line | Today | Must become |
|---|---|---|---|
| `ResultSheetBuilder` | 54 | class's `ClassSubject` rows | the pupil's list, minus exemptions |
| `ResultTrendBuilder` | 56 | class's rows | same |
| `ApprovalController` | 180, 425, 457 | class's rows | complete when every **taker** is done |
| `TermRelease` | 32, 55 | "every `ClassSubject` approved" | every class subject **with at least one taker** approved |
| `ResultEntryController` | 380 | `findOneBy(classroom, subject, session)` | unchanged — the teacher still enters by class-subject; only **which pupils appear** is filtered |
| `ResultReportController` | 137, 225 | class's rows | broadsheet column stays, cell blank for an exempt pupil (never 0, never F9) |
| `SchoolPerformanceBuilder` | 264 | class's rows | averages over takers only |
| `ResultEntryController::save()` / CSV import | 122+, 243+ | accepts any pupil's row | refuses a mark for an exempt pupil, by name, in the flash |

**One service**, `App\Service\Subjects\PupilSubjects`:

```php
/** The class's subjects, minus the ones this pupil does not take. */
subjectsFor(Student $student, Classroom $classroom, Session $session): list<ClassSubject>

takes(Student $student, ClassSubject $classSubject): bool
```

Every caller above uses it. Honouring an exemption on the card while the HOD's
board still demands the mark is exactly the bug class this codebase has paid for
before (the CA-count sweep).

## B4. Screens

### B4.1 The per-arm grid — where a form teacher will actually do it

One row per pupil, one column per subject the arm offers, every box ticked:

```
             MATH  ENG  BASIC SCI  CRK  IRS  FRENCH
  Zubairu      ☑     ☑      ☑       ☐    ☑     ☑
  Hauwa        ☑     ☑      ☑       ☑    ☐     ☑
```

Untick → that pupil does not take it. One **Save**, one flash: *"2 pupils updated
for SS 1 Arts — 1 no longer takes CRK, 1 no longer takes IRS."* On a phone the
same data is one card per pupil with subject chips that toggle.

This is the screen that matters: a form teacher does the whole arm in one sitting,
the way they do it on paper today.

### B4.2 Pupil profile → "Subjects"
The same list for one pupil, for the case where a parent walks in with a letter.
Everything ticked by default; an unticked subject shows *"not taken"* rather than
an empty mark.

### B4.3 Class-subject screen → "Not taken by"
For the reverse case — *"the eight CRK pupils are exempt from IRS"* — the subject's
own screen has one action: pick the arm, pick the pupils, exempt them all. One
write, one audit row.

### B4.4 Result entry
Unchanged for the teacher: still one sheet per class-subject. The pupil rows shown
are only the takers, with a count line — *"24 of 31 pupils take this subject"* —
so the seven missing names are explained rather than mysteriously absent. The CSV
template for that class-subject contains only takers, and an uploaded row for an
exempt pupil is refused with their name in the message.

### B4.5 Report card and broadsheet
The card lists only the subjects the pupil takes — **the exempt subject is absent,
not printed as "—", not printed as 0, and never as F9.** The broadsheet keeps its
column (other pupils need it) and leaves that pupil's cell blank.

## B5. Rules

| Situation | Rule |
|---|---|
| Pupil has no exemption rows | Takes the class list. Nothing to configure. |
| Exempt subject, no marks | Nothing to record; no fail, no zero, no average contribution. |
| Exempt subject, marks already exist | Rows are **kept, flagged and excluded** from every figure; the card omits the subject. Re-ticking restores them untouched. |
| All pupils in an arm exempt from one subject | The class subject shows *"no takers"*; it is not required for term release and its teacher sheet is empty. |
| A mark arrives for an exempt pupil (screen or CSV) | **Refused**, by name: *"Zubairu Letter does not take IRS in SS 1 Arts."* |
| Pupil moves to another arm | Exemptions belong to the old arm's class subjects; the new arm's list applies as normal. The move warns if a subject they were exempt from is core in the new arm. |
| Pupil joins mid-term | Inherits the class list; the form teacher untick what does not apply. |
| Fees | **Unaffected.** Subjects do not price anything today and should not start. |
| Timetable / teacher assignment | Unaffected: `SubjectTeacher` stays class-level. |

## B6. Edge cases worth naming now

- **Approval completeness.** "Every subject of this class is submitted" becomes
  "every subject of this class is submitted **for every pupil who takes it**". An
  HOD can no longer be blocked by a pupil who was never in the class for it.
- **Position and average.** A pupil is ranked on the subjects **they** take; a
  subject's class average is the average **of its takers**.
- **Who may exempt.** The form teacher of that arm, the principal/head teacher for
  their segment, and an administrator. Every change writes `authority_action_log`
  (§ principal design) — an exemption is the kind of decision a parent will
  question in March.
- **Bulk is the norm.** Exempting one pupil at a time for a whole arm is the
  exception; B4.3 exists so eight CRK pupils take eight seconds, not eight visits.
- **Undo is free.** Re-ticking re-includes the pupil and their old marks, because
  nothing was deleted.

## B7. Decisions wanted — Part B

| # | Question | My recommendation |
|---|---|---|
| B1 | Exemption list (untick what is not taken) or an explicit list of what *is* taken? | **Exemption list.** It matches "deselect a subject", and an empty list needs no migration. |
| B2 | Is an exemption per **session** or per **term**? | **Per session** — it follows the class subject, which is already session-scoped. A term-level exception is not worth the extra column. |
| B3 | Can a **core** subject be exempted for one pupil? | **Yes**, with a warning. The school decides; a rule that refuses would send the work back to paper. |
| B4 | Keep marks when a pupil is exempted after marking? | **Keep, exclude, restore on undo.** Never delete a mark silently. |
| B5 | Should position be computed among takers only? | **Yes.** |
| B6 | Does the principal/head teacher get this power? | **Yes, scoped to their segment**, and logged. |
| B7 | Do we need the choice-group idea at all? | **No.** Dropped by the owner on 2 Nov. If a school wants "one of these two", it creates the arm or accepts the manual tick. |
| B8 | Which segments get this? | **All four — nursery, primary, junior, senior.** Owner, 2 Nov: "this functionality should be just there". No setting, no gate. |
| B9 | Does a nursery arm need its own *Rhymes* exemption screen? | **No separate screen** — the same per-arm grid, which for a 3-subject nursery arm is three columns. |

---

## 3. Phasing

| Phase | Contents | Why this order |
|---|---|---|
| **1** | Part A read view: arms in the fee schedule, "from ₦X", per-arm totals, printed schedule | The report is currently **wrong**, not just limited. Fixing it cannot wait behind an editor. |
| **2** | Part A editor (`/finance/fees/amounts`) + `is_override` + unique constraint + invoice test | Makes the science/arts case actually enterable. |
| **3** | Part B model (`student_subject_exemption`) + `PupilSubjects::subjectsFor()` with **no UI**, and every caller moved onto it | The refactor is the risk; do it while nothing can be misconfigured. |
| **4** | Part B UI: the per-arm tick grid, the pupil checklist, the "not taken by" action, result-entry counts, card/broadsheet/approval filters | Now the behaviour is visible and testable end to end. |

Rough size: **1** ≈ half a day, **2** ≈ a day, **3** ≈ a day, **4** ≈ two days.
Nothing here needs a new server or a downtime window.

## 4. What I will not do (so we agree on it now)

- I will not make every fee per-arm. Levels stay, and a school with identical arms
  keeps one box.
- I will not touch invoices, payments or discounts — they already read the arm.
- I will not backfill exemptions — an empty list already means "takes the class
  list", for every existing pupil in every segment.
- I will not let a subject exemption affect money.
- I will not delete a mark when a pupil is exempted.

## 5. Decision log — to be filled in before phase 1 starts

| # | Decision | Answer | Date |
|---|---|---|---|
| A1 | Override vs all-per-arm | | |
| A2 | `is_override` column | | |
| A3 | Overrides survive a level change | | |
| A4 | Range vs single number for a differing level | | |
| A5 | Edit on the schedule or its own screen | | |
| B1 | Exemption list, not choice groups | | |
| B2 | Exemption scoped to the session (follows the class subject) | | |
| B3 | Core subjects can be exempted, with a warning | | |
| B4 | Marks kept and excluded when a pupil is exempted | | |
| B5 | Position computed among takers | | |
| B6 | Principal / head teacher may exempt within their segment | | |
| B7 | Choice groups dropped entirely | | |
| B8 | All segments — nursery, primary, junior, senior | | |
| B9 | One grid for every segment, no separate nursery screen | | |
| B6 | Positions within takers | | |
