# Edus Bursary Training Demo — tenant and logins

> **Internal training tenant.** Every name, phone number and balance is fake.
> Do not enter real parent data here.

**Created:** 14 November 2026
**Subdomain:** `bursarydemo`
**Local URL:** http://bursarydemo.localhost:8082/login
**Database:** `school_bursarydemo`

---

## Logins

| Who | Email | Password | Role |
|---|---|---|---|
| **Bursar (training account)** | `bursar@bursarydemo.ng` | `password123` | `ROLE_BURSAR` |
| Principal / owner | `head@bursarydemo.ng` | `password123` | `ROLE_TENANT_SUPER_ADMIN` |
| Sample parent (family exercise) | `7058813998@edus.ng` | `12345678` | `ROLE_PARENT` |

- The bursar account is a real `Staff` record, renamed for this demo.
- The parent account belongs to **Mrs. Hauwa Bello**, who has three children in
  the demo (JSS 1A). Use it to practise confirming or disputing a family notice.
- The principal account can reverse a posted Fees Import batch if a trainee posts
  it and needs another attempt.
- The demo seeder's original teacher accounts still use `@gakonline.ng` email
  addresses. They are only there so the approval chain and class lists look
  complete; the bursar and principal accounts above are the ones to share.

---

## What is already seeded

| Item | Count / detail |
|---|---|
| Active session / term | 2026/2027 — **First Term** |
| Classes | 9: JSS 1A–JSS 3C (40 pupils each) |
| Pupils | **360** |
| Parents / guardians | **336** — 12 families have 2–3 children |
| Student types | **72 New intake**, **288 Returning** (8 new + 32 returning per class) |
| Invoices | **360**, all for First Term |
| Invoice status | **4 PAID**, **3 PARTIAL**, **353 UNPAID** — one PAID bill is the grand-total scholarship |
| Confirmed cash payments | **6** (₦253,500 total) — visible in the Payment Register |
| Pending transfers | **3** (₦181,500 claimed) — waiting in **Verify Payments** |
| Fee items | Tuition, Development Levy, Books & Stationery, Uniform & Sports Wear |
| Uniform | Marked **one-time** so New intake and Returning have different bills |

### Fee amounts in the demo

| Class | New intake bill | Returning bill |
|---|---:|---:|
| JSS 1 | **₦64,500** | **₦52,500** |
| JSS 2 | **₦69,500** | **₦57,500** |
| JSS 3 | **₦74,500** | **₦62,500** |

The difference is the one-time **Uniform & Sports Wear** (₦12,000). This is the
visible proof of the **Student Type** setting.

---

## Ready-made training scenarios

### 1. Verify Payments (start here)

**Finance → Verify Payments** already contains **3 pending transfers**:

- ADM/2026/0007 — Ayodele Kaltum — ₦64,500
- ADM/2026/0008 — Umar Safiya — ₦64,500
- ADM/2026/0009 — Yakubu Safiya — ₦52,500

The bursar can open one, see the slip code and amount, type the amount seen in
the bank, and approve it. A different amount can be entered to practise the
"amount differs" path.

### 2. Fees Import — a prepared preview batch

**Finance → Fees Import → Recent imports → Batch #2** is a **Preview only**
batch with **12 pupils** and these totals:

| | Amount |
|---|---:|
| Term due | ₦630,000.00 |
| Already collected (Amount Paid) | ₦430,000.00 |
| Outstanding | ₦210,000.00 |

The 12 rows deliberately include:

- **3 full payments** → invoice becomes **PAID**
- **4 half payments** → invoice becomes **PARTIAL**
- **2 zero payments** → invoice stays **UNPAID**
- **2 overpayments** (₦57,500 against a ₦52,500 bill) → **₦5,000 advance credit** each
- **1 full payment** from another pupil

The source file is `docs/manual/bursary-demo-fees-import.csv`. Uploading the same
file again reuses the preview; it does not duplicate the batch.

**Matching rule to demonstrate:** the admission number is the key. Edus then
requires the first name plus either the **middle name** or the **surname**. A
pupil with a blank surname can be accepted when the middle name matches, and the
two columns may be swapped. The roster template now carries First Name, Middle
Name and Surname.

> **One posted batch per term.** If the trainee posts Batch #2 and needs another
> attempt, the principal must **reverse** it first. The preview can be inspected
> as many times as needed before posting.

### 3. Family linking and parent notices

The 12 sibling families include **Mrs. Hauwa Bello** (`7058813998`), with three
children: Arraghibun Bello, Kabir Bello and Ngozi Bello.

- Bursar: **Parents → search `7058813998`** → **Manage family**.
- Add another pupil with **Add a pupil to this family**.
- Sign in as the parent to see the notice and press **This is correct** or
  **This is a mistake — write a note**.
- Bursar: **Parents → Family updates → Reported by parents** to read the note.

The roll also has families whose children are in different classes, so the
"same parent, different class" case can be demonstrated.

### 4. Student Type

Every class has **8 New intake** and **32 Returning** pupils.

- JSS 1A New intake: ₦64,500 (includes uniform)
- JSS 1A Returning: ₦52,500 (no uniform)

Practise changing a pupil in **Students → Edit Profile → Student Type**, but only
on an **unpaid** invoice; a bill with money on it is history.

### 5. Discount rules — edit, delete and the grand-total rule

**Finance → Discounts → Manage Rules** has one prepared rule:

- **Demo 100% Scholarship** — a 100% percentage rule with **Apply to all fee
  items** ticked.
- It is assigned to **Halima Umar (ADM/2026/0242, JSS 3A)**.
- Her bill of **₦74,500** is **₦0** — the rule covers every fee item.
- Use it to show the difference:
  - a 100% rule with the box **unticked** reduces **tuition only**;
  - with the box **ticked** it waives **everything** and settles the bill.
- Press **REVOKE** on the Scholarship Board and the bill returns to ₦74,500.
  Re-assign it to put the ₦0 scholarship back.

The **Discount rules** page now has **Edit** and **Delete**:
- Edit a rule's name, mode or value; every open bill using it is re-priced.
- Delete is only offered for an unused rule. Revoke it from pupils first; an
  assigned rule is protected because deleting it would re-open their bills.

### 6. Reports

With 6 cash payments and 3 pending transfers already present, these screens have
data on day one:

- **Reports → Daily Collections**
- **Reports → Payment Register**
- **Reports → Outstanding Fees**
- **Reports → Class Fee Status**
- **Reports → Online Payments** (empty until an online payment is made)
- **Finance → Invoices**

---

## Suggested training order

1. Log in as the bursar and tour the menu.
2. **Parents** — search `7058813998`, open the Bello family, add a child.
3. Log in as the parent — confirm or report the notice.
4. **Students** — open a pupil, check Student Type and the bill.
5. **Fees Import** — open Batch #2, read the 12 rows, then post it.
6. **Payment Terminal** — take a cash part payment and print the receipt.
7. **Verify Payments** — approve one of the three pending transfers.
8. **Discounts** — open the grand-total 100% rule, revoke it to restore the bill,
   then re-assign it; try Edit, the **Apply to grand total** checkbox on the
   rule, the same box on the **Scholarship Board** (per pupil), and Delete on an
   unused rule.
9. **Reports** — run Daily Collections and Outstanding Fees.

---

## Resetting the demo

There is no one-click reset. If the tenant is used heavily:

- reverse the posted Fees Import batch as the principal, then delete the batch
  rows only if you are rebuilding from scratch;
- or create a fresh tenant with a new subdomain and repeat the same seed steps.

Keep the demo separate from any real school database.
