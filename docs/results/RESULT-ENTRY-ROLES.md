# Result entry — the role hierarchy, and what each person sees

**Status:** audited and hardened 18 Oct 2026.
This is the working note for the chain that turns a teacher's marks into a report
card a parent can trust. It records the screens, the order of sign-off, and the
holes found in the first audit.

---

## 1. The chain

```
Subject teacher            Form teacher              HOD                    Year group master
──────────────            ────────────              ───                    ─────────────────
enter CA + exam      →    confirm the class    →    approve the subject →  final approval
submit scores             (all subjects must        (cannot act before     (cannot act before
                          have submitted scores)    the form teacher)      the HOD)
```

Nothing skips a step. A result is only final when the last row in
`result_approval` for the class says `year_group_status = approved`.

| Role | Where they work | What they can do |
|---|---|---|
| **Subject teacher** | `/tenant/results/entry` | See only their own subject assignments; enter CA/exam scores; import a CSV; save; submit for approval. |
| **Form teacher** | `/my-class`, `/reports/form-teacher`, `/tenant/approvals` | Attendance, behaviour, form-teacher remarks, the class broadsheet; confirm the whole class once every subject has been submitted. |
| **Subject HOD** | `/reports/hod`, `/tenant/approvals` | Subject-master view across arms; approve or reject a subject; reject returns the scores to the teacher; unlock approved results. |
| **Year group master** | `/reports/year-group`, `/tenant/approvals` | Year-group status board; final approval per subject, which releases the class's results. |
| **Admin / proprietor** | `/reports`, `/reports/performance`, `/settings`, `/staff-assignments` | Everything above, plus assignments, school settings, whole-school analytics and the shareable performance PDF. |

Roles are **assignments, not fixed job titles**: `DepartmentHead`, `FormTeacher`
and `YearGroupMaster` rows bind a staff member to a department/class/year group
for a session. The only hard role gates are `ROLE_TEACHER` and `ROLE_ADMIN`.

---

## 2. What each person sees in the sidebar

- **Every teacher:** My Class, Result Entry, Reports.
- **Result Approvals:** only when the user has a form-teacher, HOD or year-group
  assignment, or is an admin. The approval hub itself is a set of cards that only
  appears when the matching assignment exists.
- **Reports hub:** Teacher Report always; Form Teacher Broadsheet only with a
  form-teacher assignment; HOD Subject Master only with a department; Year Group
  Status only with a year-group assignment; Admin Dashboard and School
  Performance only for admins.

This is deliberate: a subject teacher should not be shown an approval screen they
cannot use, and a form teacher should not have to hunt for the broadsheet.

---

## 3. Holes found and fixed

| # | Problem | Why it mattered | Fix |
|---|---|---|---|
| 1 | `ResultEntryController` accepted any `SubjectTeacher` id | A teacher could open and edit another teacher's class by changing the URL | `assertOwnAssignment()` on entry, save, submit, template and import; admins keep a bypass |
| 2 | Save/import accepted any `student_id` or admission number | A malformed request could write a score for a pupil outside the class | Roster is resolved first; unknown pupils are skipped (save) or reported (CSV) |
| 3 | Result-entry POSTs had no CSRF token | A third-party page could submit scores on a logged-in teacher's behalf | `result_entry` token in the page, JSON save/submit and CSV import |
| 4 | Approval POSTs had no CSRF token | Same risk for confirm/approve/reject/unlock | `approval_action` token on every approval form, validated in the controller |
| 5 | HOD could approve before the form teacher confirmed | The chain could be short-circuited, then final-approved | `hodApprove()` requires `form_teacher_status = approved` (admin bypass) |
| 6 | Year-group master could final-approve before the HOD | Results could be released without subject sign-off | `yearGroupApprove()` requires `hod_status = approved` (admin bypass) |
| 7 | Form teacher could confirm a class with no submitted results | Empty subjects could reach the HOD as “confirmed” | `confirmClass()` refuses and lists the subjects with no submitted scores |
| 8 | Approval hub showed approve buttons before the prerequisite | The UI contradicted the order of the chain | HOD shows “Awaiting form teacher”; year group shows “Awaiting HOD” |

**Verified on 18 Oct 2026**

```text
own entry     → 200
foreign entry → 403
HOD early     → 302 + “form teacher must confirm”
HOD status    → still pending
no CSRF       → 403
```

---

## 4. Remaining observations (not changed)

1. **“Result Entry” is always in the sidebar for teachers**, even when they have
   no subject assignments. The page has an empty state, so this is cosmetic; a
   teacher with no assignments could hide the item.
2. **The approval hub is a legacy light-blue/grey screen**, not the `ink`/`accent`
   design system. It works and is mobile-safe, but it is the next screen to
   converge when the approval flow is revisited visually.
3. **Admin approval visibility**: the hub builds cards from the admin's own
   assignments. An admin without a form-teacher/HOD/year-group assignment sees
   the empty state; they can still reach results through Reports and unlock via
   the routes. If admins routinely sign off for absent staff, give them an
   “all classes” view rather than faking an assignment.
4. **Form-teacher confirmation is class-wide.** A teacher cannot confirm one
   subject early; the class is confirmed as a unit. That matches how the
   broadsheet is used, but it does mean one missing subject blocks the class.
5. **Result-entry screen** still uses `bg-gray-*`/`bg-blue-*` utilities rather than
   the design tokens. Behaviour is correct; styling should be converged later.

---

## 5. Where the code lives

| Concern | File |
|---|---|
| Subject-teacher entry, CSV, submit | `src/Controller/Tenant/ResultEntryController.php` |
| Form-teacher workspace (attendance, behaviour, remarks) | `src/Controller/Tenant/MyClassController.php` |
| Approval chain (confirm, approve, reject, unlock) | `src/Controller/Tenant/ApprovalController.php` |
| Approval hub UI | `templates/tenant/approval/hub.html.twig` |
| Result-entry UI | `templates/tenant/result_entry/entry.html.twig` |
| Broadsheets and role reports | `src/Controller/Tenant/ResultReportController.php`, `templates/tenant/reports/` |
| Assignments | `StaffAssignmentController`, `/staff-assignments/*` |

---

## 6. One-line mental model

> The subject teacher **enters**, the form teacher **confirms**, the HOD
> **approves**, and the year-group master **releases**. Each step is visible to the
> next person only after the previous one is signed, and the backend enforces that
> order — not just the buttons.
