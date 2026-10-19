# Demo data — 1,200 pupils, JSS 1 to SS 3

The demo tenant should look like a customer, not a sample: 18 classes, two arms of
siblings for the debtors report, and enough rows that pagination, filters and the
export path are exercised for real.

**Everything here is fictional.** Names come from the generator's word lists, phones are
random and unique, and no real pupil data is ever used.

---

## 1. Generate the file

```bash
python3 generate_demo_students.py
# → demo_students_1200.csv
#   pupils   : 1200
#   classes  : 18  (JSS 1A … SS 3C)
#   families : 1008  (192 with more than one child)
#   golden   : Arraghibun Bello · JSS 1A · 7058813998
```

Options:

| Flag | Default | Why you would change it |
|---|---|---|
| `--count` | `1200` | Match a specific school's roll |
| `--levels` | `JSS 1,JSS 2,JSS 3,SS 1,SS 2,SS 3` | A primary school: `--levels "Nursery 1,Nursery 2,Primary 1,Primary 2,Primary 3,Primary 4,Primary 5,Primary 6"` |
| `--arms` | `A,B,C` | A one-arm-per-level school: `--arms A` |
| `--siblings` | `0.18` | `0` makes one guardian per pupil — only if you are *not* demoing the debtors report |
| `--out` | `demo_students_1200.csv` | Keep several rolls side by side |

The columns are exactly the template from **Quick Enrolment → Download CSV template**.
Do not reorder or rename them; blank **Admission Number** means the system assigns one.

### Two deliberate properties

1. **Siblings share a guardian.** 192 families send two children, so the debtors report
   shows one row per family with an expandable child list — the feature it was built for.
2. **The golden-thread family is pinned.** `Arraghibun Bello`, JSS 1A, phone
   `7058813998` is row one, so the parent login `7058813998@edus.ng` / `12345678` keeps
   working after an import.

---

## 2. Import it

1. Sign in as the school administrator.
2. Open **Quick Enrolment**.
3. Choose the **term** the pupils belong to.
4. Tick **Generate bills** — otherwise you get 1,200 pupils with no invoices and every
   fee screen is empty.
5. Choose `demo_students_1200.csv` and upload.
6. Read the import report before doing anything else.

### What the report must say

| Check | Expected |
|---|---|
| Accepted rows | equals the file's data rows (1,200) |
| Rejected rows | **0** — any rejection names the row and the reason |
| Students after import | previous count + accepted |
| Invoices after import | one per pupil for the term, sequential `INV-<year>-NNNN` |
| Guardians | fewer than pupils — because siblings share one |

### Verified 19 Oct 2026 — school `stmarys`

| Metric | Result |
|---|---|
| Students | **1,200** |
| Invoices | **1,200**, numbers `INV-2026-0001` … `INV-2026-1200` |
| Guardians | **1,008** (192 sibling families collapsed correctly) |
| Users | 1,009 (1,008 parents + 1 principal) |
| Enrolments | 1,200, 67 per class |
| Pupils with no guardian | **0** |
| Fees per pupil | ₦62,000 (₦55,000 tuition + ₦5,000 PTA + ₦2,000 exam) |
| Dashboard outstanding | ₦74,400,000 — matches 1,200 × ₦62,000 |

Two defects were found and fixed on the way (P-ADMIN-01 sibling guardians, P-ADMIN-02 memory); both are in `DEFECT-LOG.md` with the causes.


Rejected rows are always the same handful of causes: a class name that does not exist in
this school, a date not in `YYYY-MM-DD`, a gender that is not `M` or `F`, or a phone
number that already belongs to a different name.

---

## 3. Where to import it — and where not to

> ⚠️ **Import into a fresh school, not into `demo`.**
>
> `demo` already holds 362 pupils, including Arraghibun Bello. Importing this file there
> creates a **second** Arraghibun with a new admission number, on the same guardian, and
> every later assertion about "the" pupil becomes ambiguous.

The correct flow is in the playbooks:

1. `01-landlord.md` steps 4–5 — create a new school (e.g. subdomain `stmarys`) and run
   **Fix schema**.
2. `09-onboarding.md` — run the wizard on that school, creating JSS 1–SS 3 with arms
   `A,B,C`.
3. `02-tenant-admin.md` steps 16–18 — set the fee structure, then import this CSV with
   **Generate bills** ticked.

`demo` stays the small, stable golden-thread tenant for the parent and money playbooks;
the 1,200-pupil school is the realistic one for lists, reports and performance.

---

## 4. After the import — verify in SQL

Run against the school's tenant database:

```sql
SELECT COUNT(*) AS pupils FROM student;
SELECT COUNT(*) AS invoices FROM invoice;
SELECT COUNT(*) AS guardians FROM guardian;
SELECT COUNT(*) FROM student s LEFT JOIN guardian g ON g.id = s.guardian_id WHERE g.id IS NULL;  -- must be 0
SELECT MIN(invoice_number), MAX(invoice_number) FROM invoice;                                     -- INV-2026-0001 … NNNN
SELECT class_id, COUNT(*) FROM enrollment GROUP BY class_id ORDER BY class_id;                     -- ~67 per class
```

The orphan-guardian count is the one that matters: a pupil with no guardian cannot be
chased for fees and shows up as "No guardian assigned" on the debtors report.

---

## 5. Resetting

The cleanest reset is the landlord's school page — but deleting a school deletes its data
permanently. For a demo, prefer creating a **new** school with a new subdomain; it costs
one migration and leaves the old one intact for comparison.

If you must reuse a school, delete in this order: `payment` → `invoice_item` → `invoice`
→ `enrollment` → `student` → `guardian`, then re-import. Anything that references an
invoice (payments, receipts) must go first or the delete will be refused.
