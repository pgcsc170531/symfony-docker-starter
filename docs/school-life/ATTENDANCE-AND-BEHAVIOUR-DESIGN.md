# Attendance & behaviour — how these two features will work

**Status:** design for review. Nothing built yet.
**Decision it answers:** attendance and the affective/psychomotor domain are **phase 1**,
because both are the form master's own duties and both appear on the report card.
They are also the two things a school will ask about in the demo.

---

## 1. Why these two belong together

They look like different features. They are the same feature twice:

| | Attendance | Behaviour (affective + psychomotor) |
|---|---|---|
| Owned by | the **form master** | the **form master** |
| Scope | one class, one term, one day | one class, one term |
| Shape | a grid: pupils × days | a grid: pupils × traits |
| Entry style | bulk — mark all, then fix exceptions | bulk — rate all, then fix exceptions |
| Lifetime | daily, then frozen | once or twice a term, then frozen |
| Ends up on | the report card attendance block | the report card domain blocks |

Same owner, same grid, same lock. So they get **one home**, not two: a
**My Class** workspace for the form master — the place a form teacher does the
non-teaching half of their job.

```
My Class  (JSS 1A · First Term 2026/2027)
├── Attendance        58 of 62 registers taken   ▸ take today's
├── Behaviour         41 of 41 pupils rated       ▸ finish rating
├── Class confirmation             approved ✓
└── Remarks           41 of 41 written            ▸ continue
```

That blocked list is the single most useful screen we can give a form master: it
turns "have I finished my end-of-term duties?" into a progress bar instead of a
memory test.

---

## 2. Attendance

### 2.1 The vocabulary

| Term | Meaning in this system |
|---|---|
| **Register** | One class, one date, one list of pupils. Created when the form master opens the day. |
| **Entry** | One pupil's status on that register. |
| **School day** | A date the school is open: inside the term, not a weekend, not a `SchoolEvent` holiday. |
| **Times opened** | How many school days have a *taken* register. This is the number that goes on the card. |

### 2.2 Who does what

| Actor | Can | Cannot |
|---|---|---|
| **Form master** | Take and correct today's register for *their* class | See another class's register |
| **Subject teacher** | — | Take a register (it is not their job) |
| **Admin / proprietor** | View any class, reopen a locked day, export | Silently edit a locked entry — every change is attributed |
| **HOD / year-group master** | View their department / year group | Mark |
| **Parent** | See the term summary on the report card | See the day-by-day detail in phase 1 |

### 2.3 The daily flow — three taps, not forty-one

A teacher takes the register while 41 pupils are filing in. If it takes a minute,
it will not happen. So the default is **everybody present**, and the teacher marks
only the exceptions:

1. Open **My Class** → **Take attendance**. Today's date, their class, everyone
   pre-marked **Present**.
2. Tap the two or three pupils who are absent → their status cycles
   **Present → Absent → Late → Excused**.
3. Press **Save**. The header turns green: *"Saved · 39 present, 2 absent"*.

Everything else is optional: a note per pupil (*"travelled for a funeral"*), and a
"take again" for a second sitting if the school marks morning and afternoon.

**Mobile first, because this is a phone job.** Big rows, full-width save, no
horizontal scrolling, 44px minimum, one column. The count in the header updates as
you tap so the teacher can see they have caught everyone.

### 2.4 Statuses — four, and no more

| Status | Counts as | Report card |
|---|---|---|
| **Present** | attended | present |
| **Absent** | not attended, unexplained | absent |
| **Late** | attended, after the bell | present, and counted as late |
| **Excused** | absent with a reason (sick, permitted) | absent, shown separately |

Deliberately no "half day" in phase 1 — it sounds useful, then every school argues
about what counts as half. `Excused` covers the legitimate cases, and the note
carries the reason.

### 2.5 Which days count

- Generated from `Term.startDate` → `Term.endDate`, **minus** weekends, **minus**
  anything flagged as a holiday in `SchoolEvent`.
- The form master can mark **any past school day** in the open term (nobody marks
  on the day a child is sick, and schools catch up on Fridays).
- **Future dates cannot be marked.** The register for tomorrow does not exist yet.
- A day with no register stays *"not taken"*. It is never silently counted as present.

> **The honesty rule.** The card says **"Times school opened"** — the number of
> registers actually taken — not the number of days in the term. If a form master
> forgot three days, the card says 59, not 62, and the admin dashboard says
> *"JSS 1A missing 3 registers"*. We never invent attendance a teacher did not record,
> and we never punish a pupil for the school's missing paperwork.

### 2.6 Corrections and locking

- **Open** while the class's results for that term are unapproved: the form master
  edits freely.
- **Locked** once the class's results are final-approved (the same lock signal the
  reports use — see `RESULT-CARD-DESIGN.md` §9). Locked registers are read-only.
- Admin can **reopen** a locked day. The reason is required, and the day is stamped
  *"reopened by X on date: reason"*.
- Every change after the first save is recorded: old status, new status, who, when.
  A parent dispute is answered by a history, not a memory.

### 2.7 What the report card shows

```
ATTENDANCE      Times school opened  59
                Present              56      Absent  3 (1 excused)      Late 2
                Attendance           94.9%
```

Four lines, no chart. If registers are missing the block still prints — with the
taken count, so it never overstates.

### 2.8 Edge cases (the ones that break naive implementations)

| Case | Behaviour |
|---|---|
| Pupil admitted mid-term | Counts from their **enrolment date**, not from the first day. The card shows `present / opened since admission`. |
| Pupil transfers out | Attendance history is kept; they stop appearing on new registers from the leaving date. |
| Register taken, then a pupil is added | The new pupil shows as *"not marked"* on that day — not absent. |
| Public holiday announced late | Admin marks the date as a `SchoolEvent` holiday; the register for it becomes void everywhere. |
| School closed early (rain, security) | Mark the day as a holiday with a note; it leaves the denominator. |
| Two form masters for one class (mid-term change) | Both may take registers; who marked what is recorded. |
| 300-pupil class | Grid pages by 50; the "mark all present" applies to the page and offers *"apply to all 300"*. |

### 2.9 Data model

```php
AttendanceRegister
    id, classroom, term, date (unique per classroom+date+slot),
    slot (enum: FULL | AM | PM, default FULL),
    takenBy (Staff|null), takenAt, notes,
    lockedAt (nullable), reopenedBy/reopenReason (nullable)

AttendanceEntry
    id, register, student, status (enum: P | A | L | E), note,
    firstMarkedAt, lastChangedAt, lastChangedBy
    unique(register, student)
```

Indexes: `(classroom, date)` on the register, `(register)` on the entry,
`(student, term)` via the register for the card aggregate.

Volume check: 41 pupils × 62 days = **2,542 rows per class per term**. A
300-pupil school with 12 classes is ~30k rows a term — trivial for MySQL if we
aggregate with `SUM(status='P')` rather than loading rows into PHP.

### 2.10 Screens

| Route | What |
|---|---|
| `/my-class` | The workspace: the four completion bars |
| `/my-class/attendance` | Day list for the term — taken / not taken, newest first |
| `/my-class/attendance/{date}` | The register itself (today by default) |
| `/my-class/attendance/summary` | Per-pupil totals for the term, printable |
| `/admin/attendance` | Whole-school completion, missing-register flags |

---

## 3. Affective & psychomotor domain

### 3.1 What the two domains are

Nigerian report cards split "who the child is" from "what the child can do with
their hands and body", and grade each on a five-point scale. The standard trait
lists, which we seed as defaults:

**Affective domain — character and conduct**

| Trait | What the teacher is really judging |
|---|---|
| Punctuality | arrives on time, repeatedly |
| Attentiveness | follows the lesson, does not drift |
| Neatness | uniform, books, handwriting of the person |
| Politeness | manners to staff and peers |
| Honesty | tells the truth, owns mistakes |
| Relationship with others | plays and works without friction |
| Self-control | temper, restraint |
| Spirit of cooperation | group work, helping |
| Attitude to work | effort, homework |
| Perseverance | keeps going when it is hard |
| Leadership | takes responsibility |

**Psychomotor domain — skills**

| Trait |
|---|
| Handwriting |
| Verbal fluency |
| Games & sports |
| Drawing & painting |
| Handling of tools |
| Musical skills |
| Dexterity |

### 3.2 The scale

Five points, rendered as five dots with the number beside them:

| 5 | 4 | 3 | 2 | 1 |
|---|---|---|---|---|
| Excellent | Very good | Good | Fair | Poor |

Why dots rather than a bare number: a parent reads `●●●●○` instantly; `4` needs
the key. The number stays for the school's own records and for sorting.

**A school may rename the labels** (*Excellent/Good/Fair/Poor/Weak*), because
proprietors care about this wording.

### 3.3 Who rates, and when

- **The form master**, once per pupil per term. Not subject teachers — psychomotor
  ratings from eight teachers would be eight opinions, and the form master is the
  one who actually knows the child.
- Typically in the last two weeks of term, alongside remarks.
- The pupil's **subject teachers can view** the ratings once entered, and can flag
  a disagreement to the form master — a note, not a vote.

### 3.4 The entry grid

One screen: rows are pupils, columns are the traits, cells are a five-dot picker.
Same shape as attendance, so it feels familiar.

```
                Punct  Atten  Neat  Polit  Honest  …        done
Sadiq Auwal N.   ●●●●●  ●●●●○  ●●●●● ●●●●○  ●●●●●          ✓
Fatima Yusuf     ●●●○○  ●●●○○  ●●●●○ ●●●●●  ●●●●○          ✓
Ibrahim Bala     —      —      —     —      —              not rated
```

Deliberate behaviours:

- **Save as you go.** Every cell saves on change — a form master interrupted after
  pupil 20 does not lose 20 pupils.
- **"Rate all as 4"** then adjust the handful of pupils who stand out. Rating 41
  pupils × 18 traits by hand is 738 decisions; nobody does that.
- **Keyboard/Tab** works down a column, for a teacher with a laptop.
- **Unrated is visible.** A dash is a task, not a zero. Nothing is ever defaulted
  to 3 — an unrated pupil must look unrated so the completeness bar means something.

### 3.5 Traits are configurable

Seeded with the lists above; the school can **rename, reorder, disable, or add**
traits in settings, and choose which domain each belongs to. Disabled traits keep
their historical ratings so old cards still render correctly — a removed trait is
hidden, never deleted.

This matters more than it looks: schools are emotionally attached to their own
wording, and "Attitude to work" vs "Attitude to learning" is an argument we should
not be having.

### 3.6 What the card shows

```
AFFECTIVE DOMAIN                   5 4 3 2 1   PSYCHOMOTOR            5 4 3 2 1
Punctuality            ● ● ● ● ●              Handwriting           ● ● ● ● ○
Attentiveness          ● ● ● ● ○              Verbal fluency        ● ● ● ● ○
Neatness               ● ● ● ● ●              Games & sports        ● ● ● ● ●
Politeness             ● ● ● ● ○              Drawing & painting    ● ● ● ○ ○
Honesty                ● ● ● ● ●              Handling of tools     ● ● ● ● ○
Relationship w/ others ● ● ● ● ○              Musical skills        ● ● ● ○ ○
```

Two columns, dots filled to the score, and **no average printed**. An average of
the affective domain is meaningless and invites arguments ("why is my child 3.4?").
The individual ratings are the information.

If a pupil is unrated when the card is generated, the block prints *"Not rated"*
rather than a blank grid that looks like zeros.

### 3.7 Completeness and release

The form master's **My Class** bar shows `41 of 41 pupils rated`. The card
generation warns, per class, how many pupils are unrated or partly rated. In
phase 1 this is a **warning, not a hard block** — a school must be able to release
results for a pupil whose traits are missing, or they will hand the whole thing
back to Word. The card simply says *"Not rated"*.

### 3.8 Edge cases

| Case | Behaviour |
|---|---|
| Pupil admitted mid-term | Rated like everyone else — the form master knows them by the end of term |
| Pupil left mid-term | No card is generated; ratings are kept on their record |
| Trait added mid-term | Existing pupils show as unrated for the new trait only |
| Trait renamed | Historical ratings follow the trait id, so old cards print the new name — acceptable, and better than orphaned data |
| Form master changes | New form master rates; the previous ratings stay, attributed |
| One rating changed after release | Requires an admin unlock, same audit trail as attendance |

### 3.9 Data model

```php
BehaviourTrait
    id, school, domain (enum: AFFECTIVE | PSYCHOMOTOR),
    label, sortOrder, isActive, description (nullable)

BehaviourRating
    id, student, term, trait, score (1..5),
    ratedBy (Staff), ratedAt, updatedBy, updatedAt
    unique(student, term, trait)
```

No summary table: means, counts and completions are `GROUP BY` queries. One fewer
thing to keep in sync.

---

## 4. One lock for the whole term

Attendance and behaviour do **not** get their own locking rules. They freeze when
the class's results for that term are final-approved, which is the moment the
school has declared the term's record complete:

| State | Attendance | Behaviour | Result |
|---|---|---|---|
| Term in progress | editable | editable | enterable |
| Results approved | **read-only** | **read-only** | locked |
| Admin unlock (reason required) | editable | editable | editable |

One concept, one place to explain it, and it means a parent cannot receive a card
whose figures can still change underneath it.

---

## 5. Permissions at a glance

| | Form master (own class) | Subject teacher | HOD | Admin | Parent |
|---|---|---|---|---|---|
| Take attendance | ✅ | — | view | ✅ | — |
| Correct attendance (open) | ✅ | — | — | ✅ | — |
| Reopen locked day | — | — | — | ✅ | — |
| Rate behaviour | ✅ | view | view | view | — |
| Edit traits | — | — | — | ✅ | — |
| See term summary | ✅ | — | ✅ | ✅ | card only |

---

## 6. Deliberately not in phase 1

- **Absence alerts to parents** by SMS/WhatsApp. Valuable, but it needs the SMS
  provider and a "who pays for the SMS" decision. Phase 2.
- **Period-by-period attendance.** Daily (or AM/PM) only. Per-period is a secondary-school
  ask we may never need.
- **Attendance in the parent portal**, day by day. The card summary only.
- **Behaviour comments per domain.** Free-text remarks belong to the card's remark
  block, not to each trait.
- **Trend charts of behaviour across terms.** Phase 3, with the rest of the
  session analysis.

---

## 7. Build order

Each step is separately demonstrable, which matters because you are showing this
to schools before it is finished.

| # | Step | Demonstrable outcome | Status |
|---|---|---|---|
| 1 | `AttendanceRegister` + `AttendanceEntry` + `AttendanceChange` entities and schema | — | ✅ built |
| 2 | My Class workspace with completion bars | A form master sees what is outstanding | ✅ built |
| 3 | The register grid, mark-all-present, save | Take a real register on a phone | ✅ built |
| 4 | Calendar: term days, weekends, holidays | Correct denominators | ✅ built |
| 5 | Corrections with audit + lock on approval | A defensible record | ✅ built |
| 6 | `BehaviourTrait` seed + `BehaviourRating` entities | — | ✅ built |
| 7 | Rating grid with save-as-you-go | Rate a class in minutes | ✅ built |
| 8 | Trait settings (rename/reorder/disable) | A school makes it theirs | ✅ built (`/settings/behaviour-traits`) |
| 9 | Attendance + domain blocks on the card | The reason we built them | ✅ built (screen **and PDF**) |
| 10 | Admin whole-school completion view | The proprietor's oversight screen | ⬜ next |

### What was verified on a live tenant (GAK, 17 Oct 2026)

- 70 school days generated for 7 Sep → 11 Dec, weekends excluded; the hub correctly
  offered **Friday 16 Oct** because 17 Oct was a Saturday.
- Register: 41 pupils defaulted to Present, two marked Absent → saved, reloaded,
  still absent. DB: 39 `P`, 2 `A`, `taken_by` and `taken_at` stamped.
- Correction: one Absent → Present produced an `attendance_change` row
  (`A → P`, actor, timestamp). This is the dispute trail.
- Traits seeded lazily: 11 affective + 7 psychomotor. Setting one trait then
  **All = 4** wrote 18 ratings for that pupil; the parent's card rendered 72 filled
  dots and 18 empty ones.
- A pupil with no ratings prints **"Not rated"** — never a zero, never a default 3.
- All five screens: 0 horizontal overflow at 360px, no tap target under 44px, no
  input under 16px.
- **Found while testing:** the seeded active term had **no start/end dates**, so
  attendance had no calendar and correctly refused with a clear message. Dates were
  then set. A school must fill these in during onboarding, or the register cannot
  exist — worth a check in the onboarding wizard.
- **Trait settings verified end to end:** renamed a trait, disabled it — the parent's
  card dropped to 17 traits while all 9 recorded ratings stayed in the database. That
  is the whole point of disabling rather than deleting.

Steps 1–5 make attendance real. Steps 6–8 make behaviour real. Step 9 is where
either becomes worth money.

---

## 8. How we will know it works

Written as tests a school can run, not as unit tests:

**Attendance**
1. A form master takes today's register on a 360px phone in **under 30 seconds**.
2. Marking a pupil absent and reloading still shows absent.
3. Tomorrow's date cannot be marked.
4. A holiday added by the admin disappears from the denominator on the card.
5. A pupil admitted on day 20 shows `present / opened since admission`, not a false 40%.
6. After results are approved, the register is read-only; an admin can reopen it with a reason, and the history shows who changed what.
7. If a form master skips 3 days, the card says 59 and the admin dashboard lists the 3 dates.

**Behaviour**
1. Rating a 41-pupil class takes a teacher under 10 minutes, with nothing lost if the browser closes halfway.
2. A pupil left unrated prints **"Not rated"**, never a zero or a default 3.
3. Renaming a trait changes the card everywhere, including past terms, and loses no rating.
4. Disabling a trait hides it from new cards while old PDFs still render.
5. The dots a parent sees match exactly what the form master entered.

---

## 9. Open questions — with my recommendation

| # | Question | Recommendation |
|---|---|---|
| 1 | Daily register, or morning **and** afternoon? | **Daily**, with the `AM/PM` slot already in the schema so a school that insists can be switched on without a migration. |
| 2 | Should a parent see day-by-day attendance? | **Not in phase 1.** The card summary first. Daily detail is the fastest route to daily arguments. |
| 3 | Does a missing register block a report? | **No.** Warn the form master, flag it to the admin, print the taken count. Blocking would let one lazy teacher hold a whole class hostage. |
| 4 | Is behaviour required before release? | **Warn, don't block** — see 3.7. |
| 5 | Can subject teachers rate psychomotor? | **No.** Form master only, with a flag-a-disagreement note. Multiple raters make the number meaningless. |
| 6 | Show the affective average? | **No.** Individual dots only. |
| 7 | Who can see behaviour ratings? | Form master, HOD, admin. **Subjects teachers view-only**; parents see the card. |

Answer these seven (or say "your recommendations") and this document becomes the
spec I build from.

---

## Related reading

| Topic | Document |
|---|---|
| Where these blocks land on the card | `docs/results/RESULT-CARD-DESIGN.md` |
| The test that will exercise them | `docs/testing/TENANT-ACCEPTANCE-TEST.md` |
| Shape of the screen, tokens, mobile rules | `docs/architecture/UI-SPEC.md`, `docs/architecture/MOBILE-FIRST.md` |
| The three-stage approval that locks the term | `docs/results/RESULT-CARD-DESIGN.md` §9 |
