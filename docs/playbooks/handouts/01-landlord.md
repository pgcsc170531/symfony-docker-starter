# Handout 01 — Platform operator (landlord)

**Your job:** open schools, keep their subscriptions current, and help a school that is
stuck.
**Where you work:** the platform address, signed in as the landlord operator. This is the
only place that sees every school.

---

## See the platform

1. Open the dashboard.

**What you should see:** the number of schools, subscriptions running, and any school that
needs attention.

## Open a new school

1. **Schools → New school**.
2. Enter the school's name, its subdomain, and the principal's name, email and password.
3. Submit and **wait** — building a school takes 10–20 seconds.

**What you should see:** *"School … created"* and the school in the list, with a database
behind it. If you navigate away too early you end up with a half-built school: the row
exists but its database does not.

## Repair a school

1. Open the school → **Repair schema**.

**What you should see:** a report of what was created or verified, then
*"Schema verified — session …, First Term ready"*. It is safe to run more than once.

This is the button to use after a failed create, and whenever a school reports "database
error" on login.

## Look after subscriptions

1. Open the school → **Subscriptions / Term registrations**.

**What you should see:** the term registered, the declared roll, the invoice and its
status. Registering the same term twice is refused.

When a school's subscription lapses the portal goes **read-only** — records stay, nothing
new can be saved, and paying restores it immediately. Tell the school that, because the
first call is always "we have lost our data".

## Help a school that is stuck

| They say | Look at |
|---|---|
| "We cannot log in" | Is the school active? Does its database exist? **Repair schema** |
| "It says read-only" | Subscription expired — settle the invoice |
| "Parents cannot see results" | Did the year group master release? A half-approved class is never visible |
| "A teacher has no classes" | The school's own staff assignments — not yours to set, but you can confirm where it is |

## What you should never do

- **Never open a school on a hunch about the subdomain** — a wrong subdomain creates a
  second, empty school.
- **Never edit a school's records or results** — you are the platform, not the school.
  Give them a screenshot of what to press instead.
- **Never quote a price from memory.** The pricing on the screen is the source.

## When it goes wrong

| What you see | What to do |
|---|---|
| "registered but its database could not be built" | Open the school → **Repair schema**. If it still fails, record the error text |
| A school appears twice | Delete the half-built one's subscription row, then the school row — after checking it holds no records |
| The school list and the dashboard disagree on the count | Known: the count excludes half-built schools. Trust the list |
| A subscription will not register | A pending invoice for that term already exists — settle or cancel it first |
