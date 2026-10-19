# Edus — UI specification (adopted from PaySync)

> **Status:** adopted 2026-10-17. Covers **layout, font and colour only**.
> Companion to `docs/architecture/MOBILE-FIRST.md` (the phone rules come first and
> still win where the two overlap) and `.github/copilot-instructions.md`.
>
> **Deliberately excluded** (see §7) — i18n keys, RTL, the `Money` kobo helper, and
> the PaySync screen catalogue. Those describe a different product.

---

## 1. The rule

> **Simple, restrained: neutral greys plus EXACTLY ONE accent colour.
> Colour carries MEANING only — never decoration.**

If a new element "needs a colour to look nice", it does not get one.

## 2. Tokens

Tailwind is loaded from the **runtime CDN** in `templates/base.html.twig`, so the
tokens live in an inline `tailwind.config` block there — **not** in a
`tailwind.config.js` (there isn't one).

| Token | Value | Used for |
|---|---|---|
| `accent` 50–900 | `600` = `#2563EB` | Every interactive element, active nav, links, focus rings |
| `ink` 50–900 | — | **The only greys.** `ink-900` text, `ink-200` borders, `ink-50` page background |
| `paid` | `#059669` | Settled · approved · success · active · verified |
| `pending` | `#D97706` | Awaiting action · in flight · draft · outstanding |
| `failed` | `#DC2626` | Failed · rejected · void · overdue · cannot pay |

### 2.1 The rail is a surface, not a neutral

The content column sits on `ink-50`, which is deliberate: cards are the surfaces
there. The **rail does not**: a flat neutral near-black (`ink-900`) in a tall
column reads as a hole cut in the page rather than as something you could touch.
So the rail is painted in `#sidebar` (`base.html.twig`) with two layers —

- a navy base a couple of shades **bluer** than `ink-900`, so it belongs to the
  same family as the accent rather than looking like a grey borrowed from another
  product, and
- a shallow vertical gradient plus a very wide, very faint accent glow at the top
  (16%, 70% radius): a rail with a top and a bottom instead of a slab.

You should notice the rail is **not flat**. You should not notice that there is a
glow, and it must never be read as a signal — it does not mark active, selected or
status. This and the brand mark (§3.2) are the only colour chosen for something
other than meaning.

⚠️ **Anything sticky inside the rail must be translucent + blurred, never a solid
colour.** The rail is a gradient; a solid sticky bar shows up as a patch of the
wrong shade. The filter bar and the section headings both use
`bg-black/20–25` + `backdrop-blur`, which composites with whatever part of the
gradient is behind them.

⚠️ **Hovers in the rail are `bg-white/10`, not `bg-ink-800`.** The neutral grey
hover was a visible square of a different hue on the navy; a translucent white
just lifts the surface that is already there.

There is **no fourth semantic colour.** A state that fits none of the three is neutral.

⚠️ **`bg-accent-600` does nothing unless the inline config is present.** A missing
token fails silently — the class simply produces no CSS. If a colour "isn't
applying", check the config block before anything else.

### Helpers

| Class | Purpose |
|---|---|
| `.money`, `.tabular` | `font-variant-numeric: tabular-nums` — columns of figures align |
| `.badge-paid` / `.badge-pending` / `.badge-failed` / `.badge-neutral` | The status pill. **Status is never colour alone** — the badge text states it |
| `.field-error` | Inline field validation message |
| `--app-header-height`, `--tabbar-height` | One place each, so reserved space cannot drift |
| `body.app-shell` | Locks the signed-in page to the viewport so only `<main>` scrolls |

## 3. Type, surfaces, motion

| Aspect | Rule |
|---|---|
| Font | System stack — set once in the token block. No web fonts |
| Sizing | `rem`, so the OS text-size setting is respected |
| Surfaces | Flat white cards, hairline `ink-200` borders, one quiet shadow (`shadow-card`) |
| Radius | `0.625rem` default, `lg` (0.75rem) for cards |
| Motion | 160 ms. Animation confirms an action; it never decorates |

### 3.0 The type scale — every size is a mobile size first

Tailwind's fixed steps are **fixed**: `text-3xl` is 30px on a 390px phone and 30px on
an 8K monitor. That is why the invoice screen used to print its number at 30px on a
360px handset, where the card is 250px wide. A size class on a page with no `sm:`
neighbour is a desktop assumption hiding in a mobile app.

Use these, and nothing between them:

| Role | Phone (base) | Tablet (`sm:`) | Desk (`lg:`) | Example |
|---|---|---|---|---|
| Page title (`h1`) | `text-xl` | `text-2xl sm:text-3xl` | — | "Fee statement", "Students" |
| Section title (`h2`) | `text-base` / `text-lg` | `text-xl` | — | Card headings |
| Hero figure (balance, quote total) | `text-2xl` | `sm:text-3xl` / `sm:text-4xl` | — | `₦449,000.00` |
| Document reference (invoice no., admission no.) | `text-lg` | `sm:text-2xl` | `lg:text-3xl` | `INV-2026-0001` |
| Body | `text-sm` / `text-base` | — | — | Paragraphs |
| Label / meta | `text-xs` upper-case tracked | — | — | "ADMISSION NO" |
| Control (input, select) | **never under 16px** | — | — | iOS zooms the page otherwise |

Two consequences worth stating as rules:

1. **No text at 26px or more on a 360px phone**, except an icon glyph. A figure that
   needs to shout gets `text-2xl` at base and grows at `sm:`.
2. **A reference string must break, not push.** Anything without spaces — an invoice
   number, admission number, email or URL — gets `break-all` (codes) or `break-words`
   (names) so it wraps inside its card instead of forcing the page sideways.

Audit (run at a true 360px viewport, i.e. 288 CSS px in the integrated browser):

```js
// any text big enough to look broken on a phone
[...document.querySelectorAll('main *')]
  .filter(el => !el.children.length && el.textContent.trim())
  .map(el => ({ t: el.textContent.trim().slice(0, 24), fs: parseFloat(getComputedStyle(el).fontSize) }))
  .filter(x => x.fs >= 26);   // must be empty, or only emoji
```

Last full sweep — 19 Oct 2026, 20 screens: `/finance/invoice/{id}`, `/finance/payment/slip`,
`/students/{id}/profile` and `/subscription` were the only offenders, all four now use
the scale above.

### 3.1 Brand mark & the logo lockup

The mark is the **wordmark `Edus.`** — serif, bold, tight tracking, closed with an
accent full stop — rendered from `_brand.html.twig`, never retyped.

**Three things it must never become** — all three were tried and rejected:

| Rejected | Why |
|---|---|
| A glyph beside the word | Two marks arguing for the same space; "Edus" is only four letters |
| Bars / steps / stack | On half the SaaS logos in existence. Generic, not distinctive |
| **A letter inside a filled tile** | A filled rounded square with a white lowercase "e" **is the Microsoft Edge logo**. Not a resemblance to style around — a collision. This is why the letter-mark was retired |

The one remaining glyph is `edus-favicon.svg` (`E.` on a white tile), because a 16px
tab cannot hold a word. **Never re-draw the mark, and never substitute an emoji** —
the old 🏫 on the login page is what this replaced.

**Placement: the school at the TOP, the brand at the BOTTOM.** They are never in the
same place, and the order is never reversed.

```
┌──────────────────────────────────────────────┐
│  ▢  GOBARAU ACADAMY           [Bursar] [⏻]   │ ← header: the SCHOOL, and only the school
├──────────────────────────────────────────────┤
│                                              │
│  content                                     │
│                                              │
│                                   ▮ Edus     │ ← footer: the BRAND, once
│                   The school operating system │
│  © 2026 · All rights reserved                │
└──────────────────────────────────────────────┘
```

**Why the school owns the header.** A parent using this portal is dealing with their
child's school, not with us. Putting "Edus" first tells them they are using somebody
else's software; their school's name tells them they are on their school's system —
which happens to be powered by us. Edus is the engine, not the product the user
thinks they are using.

**Why the brand is not in the header as well.** Two marks in one viewport is not a
lockup, it is clutter, and it makes the school's own identity compete with ours. The
brand gets the footer, where a colophon belongs.

| Where | School | Brand | Mark |
|---|---|---|---|
| App header (`base.html.twig`) | `school.name` + logo, or its initial | — | the school's own |
| App footer (`base.html.twig`) | — (the sticky header already names it) | `Edus` + "The school operating system", linked to edus.ng | `edus-mark.svg` |
| Login top (`security/login.html.twig`) | name + motto + logo | — | school's, else `edus-mark.svg` |
| Login footer | — | `Powered by edus.ng` | `edus-mark.svg` |
| Marketing header (landlord home) | — (Edus *is* the product here) | `Edus.` wordmark | `edus-mark.svg` |
| Favicon | school logo when uploaded | — | `edus-favicon.svg` |
| Emails (`templates/emails/layout.html.twig`) | `school_name` header bar | "powered by Edus" footer line | — |

⚠️ **Do not put the brand in two places on one screen.** The login page carries the
mark in its footer and the "Powered by" wording — both belong there. What it must
not also have is a second "Edus" up in its header block.

### 3.2 Colour is not decoration

The one place a colour is chosen for identity rather than meaning is the brand mark
itself, and the school's own `primaryColor`, which brands *their* portal (the login
panel, the email header bar). Everything else follows §1: one accent, one grey ramp,
three semantic colours.

## 4. Layouts

### 4.1 App shell (`templates/base.html.twig`)

```
body.app-shell  →  column, height 100dvh, overflow hidden
├── header                     56px — menu · brand lockup · role badge · sign out
├── row (flex-1, overflow hidden)
│   ├── aside#sidebar           fixed drawer < md · static rail from md up  (STAFF ONLY)
│   │   └── nav (flex-1) + identity footer
│   └── main (flex-1, overflow-y auto)   ← THE ONLY THING THAT SCROLLS
├── footer                      full screen width — brand · ©
└── nav[aria-label="Primary"]   bottom tab bar, in flow, < md   (PARENTS ONLY)
```

- **Only `<main>` scrolls.** Signed in, the page is locked to the viewport and
  `body`, `header`, the rail and the footer all hold still. Scroll a 40-row
  register and the navigation does not move. When the window was the scroll
  container, the rail and the footer slid off the top of the screen with it.
  `height: 100dvh` (not `100vh`) so the footer is not hidden behind a phone's
  address bar, and `@media print` resets the lock so a report can paginate.
- **Signed out there is no shell.** Marketing pages and the public application
  form scroll normally — that is what a document should do.
- **The header owns the top offset.** The old floating hamburger meant headings
  could sit underneath it, and `<main>` carried a `pt-16` hack to compensate. Both
  are gone.
- **Parents get the tab bar; staff get the drawer.** A parent on a phone gets one
  thumb-reachable row of places; a bursar on a laptop gets the full menu.
- **The bar lists PLACES, the header holds the verb.** Places = things you go and
  look at. Exactly **four** tabs — a fifth wraps onto a second row, which grows the
  bar and eats the bottom of every page.
- **The tab bar is in normal flow, not `position: fixed`.** In a shell that never
  scrolls there is nothing to fix it against, and being in flow means it can never
  overlap the footer. That is why there is no reserved-space hack any more.
- **`main` adds no horizontal gutter at any width.** Both `main` and the page
  containers used to add 16px, so content sat 32px from each edge on a 360px
  handset — a sixth of the screen. The page owns its gutter (`px-4`, every width).
- **No `max-w-*` container and no `mx-auto`.** An app screen fills the content
  column. A `max-w-4xl mx-auto` wrapper put a 896px column in the middle of a
  1312px area — 208px of dead space on each side, and every table sat in it.
  Full width is the default; a narrow column is a deliberate, rare choice.

### 4.1.1 The footer

**Every app screen carries a footer**, as a band across the bottom of the
application (spec §2.3). It carries the **brand, and only the brand**.

| Rule | Why |
|---|---|
| Rendered only when `app.user` is set | Public and marketing pages own their own footer; a second one would duplicate it |
| **Full screen width**, a sibling of the sidebar+content row — not a child of `<main>` | Inside `<main>` it could only ever be as wide as the content column, so its rule stopped short of the edges while every other rule on screen ran edge to edge — the one band that did not line up |
| **It owns its gutter** (`px-4 sm:px-6`) | `<main>` deliberately contributes none on a phone, so a footer without its own put the brand mark hard against `x = 0` |
| **No `mt-`** | `<main>` is the scroll container and its own bottom padding is the breathing room. A top margin left a 48px strip of page background between the content and the footer's rule |
| **The nav rail ends where the footer begins** | The intended cost of a full-width band. The dark rail does not run off the bottom of the screen |
| **The brand block IS the credit** | It says who we are *and* links to `edus.ng`, so a separate "Powered by edus.ng" row would name the brand twice |
| **The school is not repeated** | The sticky header already names it on every screen. Repeating it here — and again in a © line — was three mentions of the same thing |
| The © line carries **no entity name** | Same reason. `© 2026 · All rights reserved` |
| **One row on a phone** — brand left, © right | Two cramped stacked lines was the reported bug. `flex-wrap` + `gap-x/gap-y` so it wraps *with breathing room* if it genuinely cannot fit |
| The © is **shortened to `© 2026`** below `sm` | The full sentence will not sit beside the brand block on a 320px screen without crowding it. The rest returns from `sm` up |
| No divider line between them on a phone | When they share a row a rule between them is just visual noise |
| No navigation in it | A footer is a colophon, not a menu. Places live in the tab bar / sidebar |
| Never a second footer on a tenant page | Two footers read as a mistake |

> **Say each thing once.** The redundancy this rule prevents was real: the footer
> read *"GOBARAU ACADAMY / Knowledge is light / Edus / The school operating system /
> © 2026 GOBARAU ACADAMY / Powered by edus.ng"* — the school named twice and the
> brand named twice, in six lines.

### 4.1.2 The navigation rail

| Rule | Why |
|---|---|
| **Line icons in `currentColor`**, never emoji | An emoji is drawn by the OS font — different on every platform, arbitrary colours that break "colour is meaning" (§1), and unable to inherit the active state. One set, one stroke weight (1.75), in `templates/_nav_icons.html.twig` |
| **Active = `bg-accent-600` + `aria-current="page"`** | It replaced three ad-hoc highlights (`gray-800`, `indigo-900`, `blue-900`) for one concept. A colour is not a state to a screen reader |
| **Rows are 44px in the drawer, 40px in the rail** | A thumb operates the drawer; an eye scans the static rail. 40 items × 44px is 1,760px of scrolling for no benefit |
| **A filter sits at the top, `sticky`** | The menu is ~1,600px against a ~600px window. Typing "res" leaves two rows. Sticky so it is reachable after scrolling |
| **Section headings are `sticky`** — at `top-16`, the filter bar's own height | You never lose track of which group the rows around you belong to. While filtering, headings hide: above two narrowed rows a group label is noise |
| **No identity block at the top** | The header already shows the school, its logo and the role. Repeating them in the rail cost ~100px of a column that has to scroll |
| **Identity lives at the bottom**, name + email | The one thing the header does not say is *which account* is signed in. Email, not role — the role badge is already in the header |
| **Rail sign-out is `md:hidden`** | Above `md` the header's sign-out is always on screen. Below `md` the drawer's backdrop (z-35) covers the header (z-30), so the header's copy is unreachable — a drawer with no way out is a trap |

> The rail is `bg-ink-900`, so the header and footer glass (§4.1.4) never sits over
> it: the rail ends where the footer's rule begins.

### 4.1.3 The page contract — how every screen is built

**There is exactly one shell**, `templates/base.html.twig`. A page never owns a
`<!DOCTYPE>`, a `<body>`, a header, a nav or a footer. If a screen seems to need
its own shell, it is not a page of this app — it is either a print document, an
email, or something that should be brought back inside.

#### Start from this

```twig
{% extends 'base.html.twig' %}

{% block title %}Staff Directory{% endblock %}

{% block body %}
    {# Full width, small gutter. No max-width, no centring. #}
    <div class="px-4 py-4 sm:py-6">
        <h1 class="text-2xl font-semibold text-ink-900">Staff Directory</h1>
        ...
    </div>
{% endblock %}
```

Two blocks exist: `title` and `body`. Not `content`. No per-page footer.

#### Rules that follow from the shell

| Rule | Why it is not negotiable |
|---|---|
| **Never `min-h-screen` inside `body`** | `<main>` already fills the viewport and *is* the scroll container. A full-height child forces a scrollbar on every page, even an empty one |
| **Never `overflow-y-auto` on a page wrapper** | That is a second scroll container: two scrollbars, and every `sticky` inside it starts sticking to the wrong box |
| **The page owns `px-4`** — at every width | `main` contributes none. A small gutter is kept on a desktop because tables flush to the glass edge look unfinished |
| **No `max-w-*`, no `mx-auto`** | An app screen fills the column. Centring a `max-w-4xl` in a 1312px area is 208px of dead space per side |
| **`position: fixed` is for dialogs only** | It is the one thing that cleanly escapes the scroll container. Nothing else should try |
| **A sticky element inside a page needs `top-14`** | `main`'s scroll port starts *behind* the glass header, so `top-0` parks the element underneath it where nobody can see it |
| **Public pages get no shell** | `app.user` decides. Marketing, the application form, receipts, ID cards and print sheets are standalone by design, and they paginate normally |
| **Print resets the shell** | `@media print` unlocks the body height, removes the glass and hides the rail — a report must paginate, not clip to one screen |

#### Before calling a page done

- [ ] Extends `base.html.twig` and uses `{% block body %}`
- [ ] Own gutter: `px-4`, `sm:px-6`
- [ ] No `min-h-screen`, no second scroll container, no own header or footer
- [ ] No horizontal scroll at **320px** and **360px**
- [ ] Nothing tappable under 44 × 44px on a phone; no input under 16px
- [ ] Wide tables wrapped in `overflow-x-auto` with `min-w-[640px]` on the table
- [ ] Colours are `accent-*` / `ink-*` — no `gray-*`, no `indigo-*` in touched markup

#### Standalone documents — the complete list

Anything not in this list must extend `base.html.twig`. These are documents, not
screens: they print, they email, they are shown to someone who is not signed in.

| Template | Why |
|---|---|
| `security/login.html.twig`, `landlord/security/login.html.twig`, `landlord/agent/security/login.html.twig` | A login page must not sit behind the app it logs you into |
| `tenant/apply/layout.html.twig` | The public application form — no account, no rail |
| `emails/layout.html.twig` | Email |
| `tenant/admission/print_letter.html.twig`, `tenant/payment/receipt.html.twig`, `tenant/student/print_score_sheet.html.twig`, `tenant/student/print/idcard.html.twig` | Print sheets must paginate, not scroll |
| `landlord/home/manual.html.twig` | A long-form document |

#### Converted: the onboarding wizard

`tenant/onboarding/step1_identity` … `step5_fee_matrix` used to be five independent
documents. Each carried **its own Tailwind CDN** — a second, independent Tailwind
instance with no access to the design tokens, so `bg-accent-*` would have produced
no CSS at all in them — plus its own `<head>`, an Inter web font, `min-h-screen`,
and a `-m-6` that was cancelling `<main>`'s old padding. All five are on the shell
now, and step 6 already was, so the wizard no longer changes appearance halfway.

Three real defects surfaced during the conversion:

| Defect | Why it mattered |
|---|---|
| `position: fixed` submit bars in steps 1 and 4 | `fixed` paints against the **viewport**, so inside the shell they floated over the footer and covered the last field. Now in flow at the end of the card |
| The calendar step's `<title>` said **"Setup Classes"** | Copy-pasted from step 2 — and a `<title>` is visible in the browser tab |
| Step 4's "Back" link was `href="#"` | A dead control in a wizard. Now points at the previous step |

### 4.1.4 Frosted bars

The header and the footer are **glass**, not paint: `rgba(255,255,255,.72)` plus
`backdrop-filter: blur(14px) saturate(140%)`.

- **Content scrolls behind them.** `main.app-scroll` grows past its own row by the
  height of each bar (`--app-header-height`, `--footer-height`) and pays it back as
  padding. Without that the scroll container clips at the bar's edge and there is
  nothing to blur — a translucent bar over nothing is just an off-white bar.
- **72%, not 50%.** Below about 70% the text on the bar fights the content under it,
  and this bar carries the school's name.
- **`@supports not (backdrop-filter)` falls back to 97% opaque.** Half-transparent
  without blur is the one combination that genuinely looks broken.
- **`@media print` removes it.** Paper is not glass.

### 4.2 Auth

The sign-in page is its own split layout (`templates/security/login.html.twig`) and
provides its **own** `px-4`. It does not use the app shell — no header, no nav. The
calmest possible entry.

## 5. Non-negotiable mobile rules

Inherited from `MOBILE-FIRST.md` — these are not weakened by anything here.

1. No horizontal page scrolling at 320px or 360px. Ever.
2. Nothing tappable under 44 × 44px on a phone. **Enforced in the shell** for
   `button`, every non-checkbox `input`, `select` and `textarea` — a short select
   is as un-tappable as a short button, and the font-size rule alone only fixed
   the zoom, not the hit area. Checkboxes and radios are exempt; their *label* is
   the tap target.
3. No focused control under 16px font-size (iOS Safari zooms the whole page).
4. Tailwind **default** breakpoints only — 0 / `sm` 640 / `md` 768 / `lg` 1024.
   **Never invent one** (PaySync's `xs` at 400px is deliberately not adopted).
5. Long strings (`APP/2026/0001`, emails, URLs) need `break-words`.
6. `env(safe-area-inset-*)` on anything pinned to the glass edges.

### Derived, not typed

Reserved space is computed from a variable, never hand-written:

```css
:root { --tabbar-height: 3.75rem; }
#sidebar-footer { padding-bottom: calc(.75rem + env(safe-area-inset-bottom)); }
main.tabbar-space { padding-bottom: calc(var(--tabbar-height) + env(safe-area-inset-bottom) + 1rem); }
```

A hand-written `pb-20` silently stops matching the moment the bar's height changes,
and then the last row of every page sits under the bar. That is not hypothetical —
it is how the bug this rule prevents was found.

> The tab bar is in normal flow (§4.1), so it reserves its own space and nothing
> has to be padded for it. `main.tabbar-space` is kept for the one case that still
> needs it: a page that pins something to the bottom itself.

⚠️ **Specificity gotcha.** Tailwind injects its stylesheet *after* the `<style>`
block in `<head>`, so a plain `.tabbar-space` **loses to `py-4`** on equal
specificity and the rule silently never applies. Custom overrides that must beat a
Tailwind utility need higher specificity (`main.tabbar-space`) or they will appear
to be ignored.

## 6. Feedback and screen states

**Channels** — a single action produces **exactly one**:

| Channel | For | Lifetime |
|---|---|---|
| Inline field error | A specific field failed validation | Until corrected |
| Flash banner | The outcome of a whole-page action | One page load |
| Toast | The outcome of an AJAX action that does not reload | ~4 s |

**Every screen that loads data handles five states** — loading (skeletons matching
the final layout, never a bare spinner), empty (what would appear here **and the
action that creates it**), error (what failed and what to do), partial (show what
loaded, mark what did not), not-permitted (redirect to the user's own portal with
an explanation, never a bare 403).

**Never render an empty state as `₦0.00`.** Show the state — "not enrolled",
"no data yet". A zero is a claim; an empty state is the truth.

## 7. What was NOT adopted, and why

| Spec section | Why not |
|---|---|
| §12 i18n keys, "no hardcoded English in a view" | ~140 templates carry literal English. Real work, but it does not move a demo — deferred by decision (2026-10-17) |
| §4 RTL | Depends on i18n being real first. Deferred by decision |
| §5 `money()` / `Money` integer-kobo helper | A genuine refactor of every money read-path. The `.money` CSS class is adopted; the helper is a separate job |
| §9 screen catalogue | Payslips, pension, complaints, disbursements — that is PaySync's product, not a school ERP |
| §10 Alpine component inventory | Those are PaySync's components; Edus has its own |
| `xs` breakpoint (400px) | Conflicts with the standing "Tailwind defaults only" rule |
| §3 "tables become stacked cards on mobile — never a horizontally scrolling table" | **Better than our current rule**, which says to wrap tables in `overflow-x-auto`. Adopting it means rewriting the wide tables (invoices, results, students, mark sheets) rather than wrapping them, so it is a **target, not yet a requirement**. New or touched screens should stack |

## 8. Accessibility checklist (per screen)

1. Real `<label>` bound with `for` — a placeholder is not a label.
2. Visible focus ring on every interactive element (`:focus-visible`, accent 600).
3. Errors linked to their field (`aria-describedby`).
4. **Status never conveyed by colour alone** — the badge text states it.
5. Icon-only buttons carry `aria-label` (the header's sign-out does).
6. Decorative SVG is `aria-hidden="true"`.
7. One `h1` per page; heading levels sequential.
