# Handouts — one per actor

Plain-language guides for the person who actually does the job. Written from the
**run records** in this folder, so every path in them was walked end to end on the
`stmarys` demo school (1,200 pupils) on 19 October 2026.

| # | Actor | Handout | Their screen |
|---|---|---|---|
| 01 | Platform operator (landlord) | `01-landlord.md` | schools, subscriptions, schema repair |
| 02 | School administrator / principal | `02-tenant-admin.md` | setup, staff, classes, the approval chain |
| 03 | Bursar | `03-bursar.md` | payments, slips, discounts, debtors, income & expenditure |
| 04 | Subject teacher | `04-subject-teacher.md` | marks and submission |
| 05 | Form teacher | `05-form-teacher.md` | register, behaviour, remarks, class confirmation |
| 06 | HOD | `06-hod.md` | department review and approval |
| 07 | Year group master | `07-year-group-master.md` | final release |
| 08 | Parent | `08-parent.md` | fees, receipts, results, applications |
| 09 | Store keeper | `09-store-keeper.md` | stock, POS, walk-in sales |

**How to hand them out:** one file per person, printed or pasted into a message. They are
deliberately short and repeat the two or three rules that cost money when broken —
never confirm a transfer you have not seen, never share a login, never delete a record
to fix it.

**Screenshots:** none, by design. A numbered path survives a redesign; a screenshot
becomes a lie the first time a label changes.

**What changed while writing these** — every item below was found by walking the handout
and is now fixed; the detail is in `../DEFECT-LOG.md`:

- scholarships now re-price the open bill and name themselves on the invoice;
- the report card is hidden until the year group is released;
- releasing a year group is all-or-nothing and no longer times out;
- the HOD can open a sheet read-only, and must give a reason when sending it back;
- the setup wizard links classes to year groups;
- subjects can be moved between departments without being deleted;
- menu labels say what they open (*Invoices*, *Payment Terminal*, *Income & Expenditure*).
