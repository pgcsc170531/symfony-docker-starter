# The Principal, the Vice Principal and the Head Teacher

**Status:** PROPOSAL — nothing built. Decisions wanted in §13.
**Date:** 1 November 2026
**Also read:** `docs/architecture/UI-SPEC.md` (shell), `docs/results/RESULT-CARD-DESIGN.md` (approvals),
`docs/school-life/ATTENDANCE-AND-BEHAVIOUR-DESIGN.md`.

---

## 1. What the school is asking for

The office that runs the school day — **Head Teacher** (nursery and primary),
**Principal**, **Vice Principal (Academic)** and **Vice Principal (Administration)**
(secondary) — should do its work *in the system*, not on paper:

- grant (or refuse) admissions;
- reshuffle teachers — who teaches what, who has which class;
- appoint academic roles to other teachers;
- approve results, and reopen results that need rework;
- and unblock anything else that currently stops at a signature.

They are **not** the administrator. Today the only way to give a principal
authority is to make them an administrator, which also hands them the fee
schedule, the payment terminal, the store and the P&L — exactly the parts they
say they do not want to deal with.

That last sentence is the whole design: **the principal needs authority over
people and academics, scoped to their own segment, without the money.**

---

## 2. What exists today

| Piece | Today | Note |
|---|---|---|
| Role hierarchy | `ROLE_ADMIN → [ROLE_BURSAR, ROLE_STORE, ROLE_TEACHER]`; `ROLE_TENANT_SUPER_ADMIN → ROLE_ADMIN` | An administrator *is* the bursar *is* the store keeper. There is no middle office. |
| `ROLE_ADMISSION_OFFICER` | Exists, inherits only `ROLE_USER`, opens `/admission` and the application queue | **This is the precedent to copy**: a narrow seat that grants one job and nothing else. |
| Admissions gate | `#[IsGranted("is_granted('ROLE_ADMIN') or is_granted('ROLE_ADMISSION_OFFICER')")]` on `AdmissionController`, `ApplicationReviewController` | A principal would be added here — but *scoped*, see §6. |
| Result approval | `ResultApproval` holds three stages: **Form Teacher → HOD → Year Group Master** | The chain currently ends below the principal. |
| Result release | `TermRelease::isReleased()` — every `ClassSubject` approved for the term | Used by the parent portal and the fee gate. |
| Fee visibility | `ReportAccessGate` withholds a card when the school enables the fee gate | This is the **one** financial fact an academic office needs: *is this pupil cleared?* |
| System / settings / wallet / subscription | `ROLE_TENANT_SUPER_ADMIN` only | Stays that way. |
| Dashboard personas | Super Admin → Administrator → Bursar → Store → HOD → YGM → Form Teacher → Subject Teacher → Parent | There is no principal persona; a principal is rendered as "Administrator". |

**The gap in one line:** the system has *junior* academic roles (form teacher,
HOD, year group master) and an *all-powerful* administrator. It has nothing in
between, and nothing that says "this person's authority is the senior school,
not the whole school".

---

## 3. The proposal

### 3.1 Two axes: office × scope

- **Office** = what you may do (`ROLE_PRINCIPAL`, `ROLE_VICE_PRINCIPAL_ACADEMIC`,
  `ROLE_VICE_PRINCIPAL_ADMIN`, `ROLE_HEAD_TEACHER`).
- **Scope** = which classes it applies to (the school's own segments).

An office without scope is the bug we are trying to fix: a principal of the senior
school must not be able to approve a JSS 2 result, and a nursery head teacher
must not be able to reshuffle SS 2 teachers.

### 3.2 Four roles, all teachers first

```yaml
# config/packages/security.yaml (proposed addition)
ROLE_HEAD_TEACHER:            ROLE_TEACHER   # nursery + primary
ROLE_VICE_PRINCIPAL_ACADEMIC: ROLE_TEACHER   # secondary academics
ROLE_VICE_PRINCIPAL_ADMIN:    ROLE_TEACHER   # secondary admissions/staff/students
ROLE_PRINCIPAL:               [ROLE_TEACHER, ROLE_VICE_PRINCIPAL_ACADEMIC, ROLE_VICE_PRINCIPAL_ADMIN]
```

- Every one of them **inherits `ROLE_TEACHER`**, because a principal still teaches
  and still needs `/my-class` and result entry. (The same reason `ROLE_HOD`
  inherits `ROLE_TEACHER` — without it, an HOD could not open their own register.)
- **None of them inherits `ROLE_ADMIN`.** That is the point: no fee schedule, no
  payment terminal, no store, no P&L, no settings.
- **`ROLE_ADMIN` does not need these roles.** An administrator may still do
  everything a principal can; the principal is the *narrower* seat.

### 3.3 Scope lives in a table, not in the role name

```
leadership_office
  id, staff_id, office ('head_teacher' | 'principal' | 'vp_academic' | 'vp_admin'),
  session_id,                          -- authority is per session, like everything else
  segments ('nursery,primary,junior,senior' — one row per segment, or a set),
  appointed_by (user_id), appointed_at, starts_on, ends_on (nullable), is_active
```

- A **Head Teacher** gets `nursery` and/or `primary`.
- A **Principal** gets `junior`, `senior`, or both.
- A school with one principal over the whole secondary school is two rows, or one
  row with both segments — the screen should accept "both" in one tick.
- Segment comes from the class's year group. Proposal: **`year_group.segment`**
  (`nursery|primary|junior|senior`), defaulted by the onboarding wizard from the
  same presets it already uses to generate classes. Existing schools get it
  back-filled from the year-group name (`JSS 1` → junior, `Primary 3` → primary,
  `Nursery 2`/`Pre-Nursery` → nursery, `SS 2` → senior), which is exactly what the
  current `ClassLevels` heuristic already knows how to read.

---

## 4. Capability matrix

`✓` full · `S` scoped to their own segment · `–` none · `log` writes an audit row

| Capability | Super Admin | Admin | Bursar | **Principal** | **VP Acad** | **VP Admin** | **Head Teacher** | HOD | YGM | Form T. | Subject T. |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Accept / refuse an application | ✓ | ✓ | – | **S log** | – | **S log** | **S log** | – | – | – | – |
| Enrol an accepted pupil | ✓ | ✓ | – | **S log** | – | **S log** | **S log** | – | – | – | – |
| Edit a pupil's profile | ✓ | ✓ | ✓ | **S log** | – | **S log** | **S log** | – | – | own class | – |
| Promote / repeat a pupil | ✓ | ✓ | – | **S log** | – | – | **S log** | – | own level | – | – |
| Create a staff account | ✓ | ✓ | – | – | – | – | – | – | – | – | – |
| Give a staff account an **academic** role (form teacher, YGM) | ✓ | ✓ | – | **S log** | **S log** | – | **S log** | – | – | – | – |
| Give **any other** role (admin, bursar, store, principal, super admin) | ✓ | ADM only | – | **–** | – | – | – | – | – | – | – |
| Subject-teacher assignment (reshuffle) | ✓ | ✓ | – | **S log** | **S log** | – | **S log** | dept | – | – | – |
| Appoint an HOD | ✓ | ✓ | – | **–** | – | – | – | – | – | – | – |
| Enter marks | ✓ | ✓ | – | own | own | own | own | own | own | own | own |
| Unlock a submitted sheet | ✓ | ✓ | – | **S log** | **S log** | – | **S log** | dept log | level log | – | – |
| Approve at Form Teacher stage | ✓ | ✓ | – | – | – | – | – | – | – | ✓ | – |
| Approve at HOD stage | ✓ | ✓ | – | – | – | – | – | ✓ | – | – | – |
| Approve at Year Group Master stage | ✓ | ✓ | – | **S log** | – | – | **S log** | – | ✓ | – | – |
| Release a term (cards to parents) | ✓ | ✓ log | – | **S log** | – | – | **S log** | – | – | – | – |
| Reprint / issue a card | ✓ | ✓ | – | **S log** | **S log** | – | **S log** | – | – | – | – |
| Attendance & behaviour oversight | ✓ | ✓ | – | **S** | **S** | – | **S** | – | **S** | own class | – |
| Send an SMS to a class or the segment | ✓ | ✓ | ✓ | **S log** | **S** | **S** | **S log** | – | – | – | – |
| **A pupil's fee status** (cleared / owes) | ✓ | ✓ | ✓ | **S, status only** | **S, status only** | **S, status only** | **S, status only** | – | – | – | – |
| Fee amounts, payments, invoices, discounts | ✓ | ✓ | ✓ | **–** | **–** | **–** | **–** | – | – | – | – |
| Store, expenses, P&L, wallet, subscription | ✓ | ✓ | partly | **–** | **–** | **–** | **–** | – | – | – | – |
| Settings, classes, subjects, terms | ✓ | ✓ | – | **–** | **–** | **–** | **–** | – | – | – | – |

Two rows deserve their own sentence:

- **"A pupil's fee status — status only."** The academic office genuinely needs to
  know whether a pupil is cleared, because the report-card fee gate
  (`ReportAccessGate`) and the exam lists depend on it. It does **not** need the
  ledger, the amount, or the history. So the principal sees a badge — *Cleared /
  Owes* — and nothing that opens Finance.
- **"Give any other role — none."** A principal who can appoint an administrator
  can appoint an accomplice. Academic appointments only, and even those are logged.

---

## 5. What a principal may never do

1. See or change **money**: fee amounts, invoices, payments, discounts, expenses,
   store prices, wallet, subscription.
2. Touch **settings**, classes, subjects, grading scales or terms.
3. Create, suspend or promote an **administrator**, **super admin**, or another
   **principal**.
4. Edit **their own** office, scope or session.
5. Delete a pupil's record (withdraw with a reason instead — the record stays).
6. Act outside their **segment**, in any capability marked `S`.
7. Act in a **session they are not appointed for** (a principal for 2025/2026 does
   not approve 2026/2027 results until re-appointed).

---

## 6. How it is enforced (not just hidden)

Three layers, in order:

1. **Route gate.** `#[IsGranted]` on the controller — `ROLE_ADMIN` stays where it
   is, and the principal roles are added where the capability applies, e.g.
   `is_granted('ROLE_ADMIN') or is_granted('ROLE_PRINCIPAL') or is_granted('ROLE_HEAD_TEACHER')`.
2. **Scope voter.** `LeadershipVoter` / `App\Service\Leadership\Scope`:
   `can($user, 'approve_results', $classroom, $term)` → false if the classroom's
   `yearGroup.segment` is not in the office's segments, or the term is not the
   appointed session. Every `S` in §4 calls it; the controller passes the
   classroom, never the user's word for it.
3. **Audit.** Every `log` action writes to `authority_action_log`:

```
authority_action_log
  id, actor_user_id, capability, target_type, target_id,
  summary (human sentence), reason (nullable), ip, created_at
```

Because the whole reason to move this office off paper is that **the file should
still exist**. A principal can see their own log ("what I did this week"); the
super admin sees every office's log, filterable by segment.

---

## 7. The result chain, and where the principal sits

Today: **Form Teacher → HOD → Year Group Master** → released.

Two options; I recommend the first.

**Option 1 (recommended) — the principal is the safety valve, not a fifth signature.**
- The chain stays four stages. Adding a fifth makes a slow process slower, and
  the Year Group Master already represents the level.
- The principal (and VP Academic) may **unlock** any sheet in their segment, with
  a reason, and may **release** the term. Both are logged.
- What this fixes in practice: "the YGM is on leave and 40 cards are stuck" — the
  exact paper bottleneck they described.

**Option 2 — an optional fifth stage.** `school_settings.principal_signoff`
(default **off**). When on, `ResultApproval` gains
`principal_status/principal/principal_at`, and release requires it. For schools
where the proprietor insists the principal signs, not the YGM.

Either way: **the principal's board is scoped to their segment**, and it shows
*"3 sheets waiting on you"* rather than the whole school's 400.

---

## 8. Their three screens

### 8.1 Admissions desk (scoped)
The application queue for their segment: today's, this week's, waiting list.
Accept → the existing enrolment flow; refuse → a reason (required, shown to the
parent); request more information → the existing "send back" state.

### 8.2 Staff desk (scoped) — "reshuffle"
One screen, three jobs:

- **Who teaches what** — the subject-teacher matrix for their classes, with a
  "swap" action (teacher A ↔ teacher B for one class-subject) that writes both
  sides and logs one row.
- **Who has a class** — form teacher per arm, with "unassigned" called out.
- **Who holds an office** — appoint a form teacher or Year Group Master in their
  segment. Anything beyond that is a button that says *"ask the administrator"*,
  not a hidden 403.

### 8.3 Academic board (scoped)
Approval status by level and subject, unmarked sheets by teacher, classes without
a form teacher, the term release switch, and the quickest route to any one
pupil's card.

Every one of these is a **mobile-first** screen — the head teacher of a nursery
will be standing in a corridor, not sitting at a desk.

---

## 9. What the first screen should say

A principal's dashboard is not the bursar's dashboard with different numbers. It
is a **work queue**, in this order:

1. **Waiting on you** — applications to decide, sheets to unlock or release,
   appointments to confirm.
2. **Stuck elsewhere** — sheets a teacher has not submitted for 5 days, an arm
   with no form teacher, an application open for 3 days.
3. **This term at a glance** — attendance rate for the segment, approval progress
   per level.
4. **What you did** — the last five entries in their own audit log.

If the first screen is right, the office stops using the phone to ask each other
what is happening.

---

## 10. Training handouts (to be written after the feature)

Six one-pagers, one per duty, plus one A4 reference. Each is *"the task, the
screen, the three taps, the mistake to avoid"* — no feature tour.

| # | Handout | The mistake it prevents |
|---|---|---|
| 1 | **Deciding an application** | Accepting before the fee is confirmed, or accepting without a class |
| 2 | **Enrolling from the queue** | Re-typing a pupil who already exists (the duplicate-parent problem) |
| 3 | **Reshuffling a teacher** | Swapping the subject but leaving the old assignment live |
| 4 | **Appointing a form teacher / YGM** | Appointing for the wrong session |
| 5 | **Unlocking a sheet** | Unlocking in term-time and never telling the teacher |
| 6 | **Releasing the term** | Releasing while one subject is still pending |
| A4 | **The week sheet** — Monday queue, Wednesday stuck list, Friday approval sweep | Drifting back to paper because nobody named a weekly rhythm |

The handouts are only written **after** the flows exist, so they can quote the
real screens and the real button names.

---

## 11. Phasing

| Phase | Contents | Why first |
|---|---|---|
| **1** | `year_group.segment` + back-fill; `leadership_office` table; four roles in the hierarchy; super-admin screen to appoint an office | Nothing can be scoped until a class knows its segment |
| **2** | Scope voter + audit log + principal persona dashboard (read-only board) | Proves the shape before granting a single write |
| **3** | Admissions desk + academic board (unlock, release) | The two duties that unblock paper today |
| **4** | Staff desk (reshuffle, academic appointments) + handouts | The riskiest writes, once the audit trail is proven |

Rough size: **1** ≈ 1 day, **2** ≈ 1.5 days, **3** ≈ 1.5 days, **4** ≈ 1.5 days.
No new servers, no downtime.

---

## 12. What I will not do

- I will not give the office any part of Finance beyond a cleared/owes badge.
- I will not make the principal an administrator "but with things hidden" — a
  hidden button is still a permission, and a 403 is not a design.
- I will not let an office edit its own scope.
- I will not add a fifth approval signature unless the school explicitly asks
  (§7, Option 2).
- I will not write the handouts before the screens exist.

---

## 13. Decisions wanted

| # | Question | My recommendation |
|---|---|---|
| D1 | Four roles (Head Teacher, Principal, VP Academic, VP Admin) or one `ROLE_PRINCIPAL` with an office field? | **Four roles** — the sidebar, the badge and the guards all read roles; an office field would leak into every template. |
| D2 | Is the office **per session**? | **Yes.** Re-appoint each session, like form teachers. |
| D3 | Can one person hold two offices (Principal of junior *and* senior)? | **Yes**, two scopes, one login. |
| D4 | Where does "segment" come from? | **`year_group.segment`**, back-filled from the name, editable. |
| D5 | Principal sees a pupil's **amount owed**, or only cleared/owes? | **Only the badge.** |
| D6 | Can a VP (Admin) accept admissions, or only prepare them? | **Accept**, scoped, logged. Otherwise the queue just moves. |
| D7 | Can the principal appoint an HOD? | **No** — HOD spans departments; that stays with the administrator. |
| D8 | Principal can unlock a **released** term? | **No**, that is a super-admin action with a written reason. |
| D9 | Fifth approval stage? | **No** unless asked (§7 Option 2). |
| D10 | Does the principal get SMS-sending rights? | **Yes, scoped to their segment, logged** — it is how they stop calling teachers. |
| D11 | Do teachers see who unlocked their sheet? | **Yes**, in the entry banner: "Unlocked by VP Academic on 12 Nov — 'missing CA2'". |
| D12 | Should the badge read "Principal" or "Administrator"? | **Principal** — the school should be able to see who is who. |

---

## 14. Why this makes the school keep the system

The paper this office handles is not reports — it is **decisions and
signatures**: an admission slip, a swapped timetable, a released result. Every
one of those is a small, logged, reversible action in the system, and each one
currently ends with somebody carrying a file to somebody else's desk.

If the principal can do their three jobs on a phone — decide an application,
move a teacher, release a term — the paper stops being the source of truth, and
everything below them (form teachers, HODs, bursars) follows, because they no
longer have a paper route around the system.
