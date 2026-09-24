# Edus Exam Collection Module — Build Instructions

> Step-by-step implementation plan. Always follow the style and conventions
> already used in the Edus tenant application.

## 1. Consistency rules (match existing modules)

- Use the **same Tailwind CDN** already loaded in `templates/base.html.twig`.
- Keep all tenant pages under `templates/tenant/`.
- Group new ACADEMIC links with existing links in `templates/tenant/base.html.twig`.
- Use Symfony forms, Doctrine entities, and controller patterns identical to
  other tenant modules.
- Never hard-code score columns in templates. Render dynamic columns from the
  resolved `AssessmentScheme`.
- Never store computed totals. Total and grade are calculated at read time.

## 2. Database and schema

Every new entity must be added to `src/Entity/Tenant/` and to the tenant
schema update command:

- `bin/console app:tenant:update-schema`

This loops through all tenant databases and synchronizes the schema.

## 3. Build sequence

### Phase 1 — Foundation
1. Create `Department` entity.
2. Create `Subject` entity, linked to `Department`.
3. Update `Classroom` to belong to `YearGroup` and have an `arm`.
4. Create `YearGroup` entity.

### Phase 2 — Staff & assignments
5. Create `Staff` entity, one-to-one with `User`.
6. Create `DepartmentHead`, `SubjectTeacher`, `YearGroupMaster`, `FormTeacher`.
7. Enhance `User` with new roles where needed.

### Phase 3 — Assessment setup
8. Create `GradingScale` entity.
9. Create `AssessmentScheme` entity.
10. Create `ClassSubject` and `StudentSubject` entities.

### Phase 4 — Result entry
11. Create `ResultComponentScore` entity.
12. Create result entry controller + form.
13. Build the dynamic Alpine.js grid.
14. Build the focus modal (Save & Next).
15. Build CSV template download + upload preview/import.

### Phase 5 — Approvals
16. Form Teacher confirmation.
17. HOD approval.
18. YearGroup Master final approval.
19. HOD/Admin unlock.

### Phase 6 — Reporting
20. Teacher report.
21. Form Teacher broadsheet.
22. HOD subject master + per-teacher report.
23. YearGroupMaster approval dashboard.
24. Admin statistical dashboard.
25. Student/Parent result sheet.

## 4. Naming and field conventions

- Database columns: `snake_case`.
- PHP properties: `camelCase`.
- Date/time columns: `datetime_immutable` or `date_immutable`.
- `*_at` timestamps where useful for auditing.
- Idempotent schema updates: always check `information_schema` before adding
  columns (see existing `UpdateTenantSchemaCommand` pattern).

## 5. Roles

- `ROLE_ADMIN` — full access, unlock.
- `ROLE_HOD` — approve their department, unlock their subjects.
- `ROLE_TEACHER` — enter scores for assigned subjects only.
- Add the HOD/YearGroupMaster/FormTeacher logic through assignment entities,
  not only through Symfony roles.

## 6. Result entry UX patterns

- Page embeds the resolved `AssessmentScheme` as JSON for Alpine.js.
- Grid mode: one row per student, columns auto-rendered.
- Modal mode: click a student row → fill fields → Save & Next.
- All saves use `fetch()` against a JSON endpoint.
- CSV import uses the same template format as existing modules.

## 7. When adding a feature

1. Update this INSTRUCTIONS.md with the new step.
2. Create the entity in `src/Entity/Tenant/`.
3. Add accessors.
4. Add the entity to `app:tenant:update-schema`.
5. Run the schema update and verify one tenant database.
6. Create the controller, form, and Twig template.
7. Add the matching ACADEMIC menu link, role-gated.
8. Test the workflow end-to-end.