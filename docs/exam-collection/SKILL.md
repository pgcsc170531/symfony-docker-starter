# Edus Exam Collection Module — SKILL Reference

> This document defines the **domain model, decisions, and rules** for the
> Exam Collection module. Follow it before writing any code.

## 1. Scope

Primary and Secondary school result collection only.
Tertiary institution collection is deferred.

## 2. Core principles

1. **Configure once, then auto-generate the entry grid.**
   Teachers never see fields that are not required for their class/subject.
2. **One score, one row.** Every mark lives once in `ResultComponentScore`.
   Totals and grades are computed, never duplicated.
3. **Role-gated access.** A teacher can only enter scores for subjects
   assigned to them. HOD and Year Group Master approvals are explicit.
4. **Frictionless UX.** Alpine.js grid + focus modal + CSV import.
   No full-page reloads during entry.
5. **Consistent with existing modules.** Reuse the existing Tailwind CDN,
   `templates/base.html.twig`, and the ACADEMIC menu grouping.

## 3. Existing entities to reuse

- `User` (tenant) — login account, `ROLE_*` roles, one-to-one `Staff`
- `Session` — academic session, has many `Term`
- `Term` — term with `startDate`, `endDate`, `isActive`
- `Classroom` — a class/section (e.g. `JSS 1A`)
- `Student` — has `firstName`, `middleName`, `lastName`,
  `admissionNumber`, `currentClassroom`
- `School` — tenant settings

## 4. New entities

| Entity | Purpose |
|---|---|
| `Department` | Maths, English, Sciences, Arts, Business |
| `Subject` | Belongs to one `Department` |
| `YearGroup` | A level grouping all arms (JSS 1, JSS 2, SS 1…) |
| `Classroom` (updated) | Belongs to `YearGroup`, has an `arm` (A, B, C…) |
| `ClassSubject` | Maps subject → classroom → session with `category` |
| `StudentSubject` | Individual **elective** registration per student |
| `Staff` | One-to-one with `User`, holds profile fields |
| `DepartmentHead` | Staff assigned as HOD of a department for a session |
| `SubjectTeacher` | Staff assigned to teach a ClassSubject for a term |
| `YearGroupMaster` | Staff in charge of a YearGroup for a session |
| `FormTeacher` | Staff as form teacher of a specific Classroom |
| `GradingScale` | Per-school configurable grade ranges |
| `AssessmentScheme` | CA count, exam mode, and max scores |
| `ResultComponentScore` | One row per student per subject per component |

## 5. Key terminology

- **Classroom** — a single section with students. Has a **Form Teacher**.
- **YearGroup** — all arms at one level (e.g. JSS 1 = JSS 1A, 1B, 1C).
  Has a **YearGroup Master**.
- **Form Teacher** — operates on **one classroom**.
- **YearGroup Master** — operates on **one year group** and supervises
  the form teachers within it.

## 6. Subject availability model

```text
ClassSubject
- subject_id
- classroom_id
- session_id
- category: core | elective
```

- **Core** — registered once per class. Every student in that class is
  automatically enrolled.
- **Elective** — registered once per class, then assigned to individual
  students in one bulk screen (e.g. Technical Drawing for male students,
  Food & Nutrition for female students).

## 7. Assessment scheme

```text
AssessmentScheme
- school_id
- session_id
- term_id
- classroom_id   (nullable = whole school/class default)
- subject_id     (nullable = applies to whole class/school)
- ca_count       (2 or 3)
- exam_mode      (single | dual)
- ca_max         (default 20)
- theory_max     (default 60)
- practical_max  (default 40)
```

Resolution order:

```text
subject-specific override
  → class-specific override
    → school default
```

Grid columns are rendered from the resolved scheme:
- `ca_count = 2` → CA1, CA2
- `ca_count = 3` → CA1, CA2, CA3
- `exam_mode = single` → Theory only
- `exam_mode = dual` → Theory + Practical

## 8. Result score storage

```text
ResultComponentScore
- student_id
- class_subject_id (or subject + classroom + term)
- term_id
- component enum: ca1 | ca2 | ca3 | theory | practical
- score decimal
- entered_by (Staff)
- status: draft | submitted
- created_at, updated_at
```

Total is always computed:
`sum(CA) + theory + practical`

## 9. Grading scale

```text
GradingScale
- school_id
- grade (A1, B2, C4, ...)
- min_score, max_score
- points (used for CGPA / ranking)
- remark (Excellent, Very Good, Credit, Pass, Fail)
- sort_order
```

Schools can configure their scale from WAEC, NECO, or Ministry guidelines.

## 10. Staff hierarchy

- Admin creates all staff accounts centrally.
- `User` holds login + roles (`ROLE_TEACHER`, `ROLE_HOD`, etc.).
- `Staff` holds one-to-one profile details.
- A staff member can hold multiple assignments in different terms.

## 11. Approval workflow

```text
Teacher enters and submits scores
  → Form Teacher confirms their class
    → HOD approves the class/subject
      → YearGroup Master final-approves the whole year group
```

- Only HOD or Admin can unlock a result after submission.
- Editing after submission is blocked until unlocked.

## 12. Reporting levels

1. **Teacher** — one class + subject grid, totals and grades only.
2. **Form Teacher** — one classroom broadsheet, all subjects, confirmation.
3. **HOD** — subject master across all arms, per-teacher performance.
4. **YearGroupMaster** — whole year group statuses, final approval.
5. **Admin** — school-wide analytics and grade distribution.
6. **Student/Parent** — individual result sheet for a session/term.

No reporting data is duplicated; all reports aggregate from
`ResultComponentScore`.