# Edus — rules for working in this repository

---

## 📱 MOBILE FIRST — THE MOST IMPORTANT RULE IN THIS FILE

> **THIS IS THE PRIME DIRECTIVE FOR ALL UI WORK. IT IS NOT OPTIONAL, NOT A NICE-TO-HAVE,
> AND NOT SOMETHING TO DO "AT THE END".**

**Parents and teachers use this portal on a mobile phone.** One-handed, standing up,
on a slow connection, often on a cheap Android handset with a small screen. Desktop is
the exception, not the target. A feature that only works comfortably on a laptop is a
broken feature.

### The rules

1. **Build the phone layout first, then widen it.** Never design for desktop and bolt
   responsive classes on afterwards.
2. **Test at 320px and 360px before you call any UI change done.** Not 1280px.
3. **One column by default.** Add `sm:grid-cols-2` / `lg:grid-cols-3` — never start
   from a multi-column grid.
4. **Primary actions are full width and thumb-sized on a phone** (`w-full sm:w-auto`,
   at least 44px tall). A thumb is not a mouse pointer.
5. **Nothing may scroll sideways.** Ever. `overflow-x` on the page is a bug.
6. **Never put a focused control below 16px font-size** — iOS Safari zooms the whole
   page. `base.html.twig` already forces 16px on mobile; don't fight it.
7. **Wrap wide tables** in `<div class="overflow-x-auto">` with `min-w-[640px]` on the
   table itself. Tables are the number one cause of horizontal overflow.
8. **Assume a slow, flaky connection.** Prefer small payloads, avoid heavy libraries,
   and treat offline/retry as a normal case — not an error case (see
   `docs/architecture/REQUEST-RESPONSE-AUDIT.md` §6.4).
9. **Long strings must not break the layout** — references like `APP/2026/0001`,
   emails and URLs need `break-words` / `overflow-wrap: anywhere`.
10. **Respect the notch and the home indicator** — use `env(safe-area-inset-*)`.

### The phones that matter

| Width | Represents | Why it matters |
|---|---|---|
| **320px** | iPhone SE (1st gen), very old Android | The absolute floor. If it survives here, it survives anywhere. |
| **360px** | Most Android phones | **The single most common width in Nigeria / most of Africa.** Design for this one. |
| **390 / 414 / 428px** | iPhone 12–15, larger Androids | Common. |
| 640px (`sm`) · 768px (`md`) · 1024px (`lg`) | Tablets / desktop | Tailwind defaults — **do not invent new breakpoints.** |

### Before finishing any UI task, confirm

- [ ] No horizontal scrolling at 320px and 360px
- [ ] Every button and link is ≥ 44px tall on a phone
- [ ] No input under 16px font-size
- [ ] Primary actions are full width on a phone
- [ ] Content is reachable without pinch-zoom
- [ ] Wide tables are wrapped in an `overflow-x-auto` container
- [ ] Tested with the viewport at 320px **and** 1024px

### How to actually check (do this, don't assume)

The VS Code integrated browser scales its viewport by 1.25×, so to get a true CSS width
request `width / 1.25` (a real 360px viewport = request 288). Then assert on the DOM:

```js
// horizontal overflow + touch targets — run on every page you touch
const vw = window.innerWidth;
const overflow = document.documentElement.scrollWidth - vw;   // must be <= 0
document.querySelectorAll('main button, main a').forEach(el => {
  const r = el.getBoundingClientRect();
  if (r.height < 44) console.warn('small tap target', el.innerText, r.height);
});
```

Full standard, rationale and code patterns: **`docs/architecture/MOBILE-FIRST.md`**.

---

## 🎨 DESIGN SYSTEM — one accent, one grey ramp, colour means something

The design standard is **`docs/architecture/UI-SPEC.md`**. Read it before touching a
view. UI-scoped rules also load automatically for view work
(`.github/instructions/ui.instructions.md`).

The four rules that catch people out:

1. **`accent-*` for every interactive element. `ink-*` for every grey. Nothing else.**
   `paid` / `pending` / `failed` are the only semantic colours, and they mean *status* —
   never decoration. If an element "needs a colour to look nice", it does not get one.
   Do not introduce `indigo-*` or `gray-*` in new or touched markup; converge as you
   edit, don't sweep.
2. **A token that is not in the config produces NO CSS AT ALL.** Tailwind is the
   runtime CDN, so every token lives in `templates/_design_tokens.html.twig`. A
   standalone page that does not `{% include %}` it has *no tokens* — which is exactly
   how the login footer's colour classes silently did nothing.
3. **Custom CSS must out-specify the Tailwind utility it overrides.** Tailwind injects
   its sheet *after* the `<head>` `<style>`, so `main.tabbar-space` beats `py-4` while a
   plain `.tabbar-space` loses, silently and with no warning.
4. **The page owns the horizontal gutter**, not `<main>`. Never add a second one —
   doubling them is what put content 32px from each edge on a 360px phone.

### Brand & logo — the wordmark IS the logo

**`Edus.`** — serif, bold, tight tracking, closed with an accent full stop. That
is the whole brand. "Edus" is four letters; a glyph beside it would be a logo and
a caption arguing for the same space, so the letter-mark was retired.

Render it from the macro, never by retyping it:

```twig
{% import '_brand.html.twig' as brand %}
{{ brand.wordmark('md') }}            {# ink on a light background #}
{{ brand.wordmark('lg', 'light') }}   {# white on a dark background #}
{{ brand.lockup('dark') }}            {# wordmark + "The school operating system" #}
```

`edus-mark.svg` and `edus-mark-mono.svg` are **deleted** — do not reintroduce
them. The one remaining glyph is **`edus-favicon.svg`** (`E.` on a white tile),
because a 16 px tab cannot hold a word.

**Placement is absolute: school in the header, brand in the footer. Never both.**
Where the brand stands alone, the wordmark sits on one side and the maker's credit
("Powered by Mu'ammil Web Services Limited") on the other, with space between —
the product is not the company, and "Powered by edus.ng" credited the product to
itself.

```
┌────────────────────────────────────────────┐
│  ▢  GOBARAU ACADAMY          [Bursar] [⏻]  │ ← the SCHOOL, and only the school
├────────────────────────────────────────────┤
│  content                                   │
│                                            │
│  Edus.                          Powered by │ ← footer: brand left, maker right
│  The school operating system  Mu'ammil …   │
└────────────────────────────────────────────┘
```

A parent is dealing with **their child's school**, not with us. The school's name
belongs at the top; Edus is the engine underneath and gets the footer, where a
colophon belongs. Two marks in one viewport is clutter, not a lockup.

**Say each thing once.** The brand block *is* the credit — the wordmark never
appears next to a second "Edus" or an "edus.ng" link. The company that builds the
product is named on the other side of the same footer row.

⚠️ **Never put the wordmark inside a filled rounded tile**, and never set the
letter "e" on its own as a mark. A filled tile with a white lowercase "e" is the
Microsoft Edge logo; that collision is precisely why the letter-mark was retired.

Already wired: the app footer (`base.html.twig`), the login page
(`security/login.html.twig`), the marketing header and footer
(`landlord/home/index.html.twig`, `landlord/home/pricing.html.twig`), the public
verify and error pages, the OG image (`bin/generate-og-image.php`) and the favicon.
The PDF masthead uses the same wordmark — see `.github/skills/pdf-documents`.

---

## Other standing rules

- **Never put a login page behind its own firewall** — it causes an infinite redirect
  loop. Order matters in `security.yaml` (see the comments already in that file).
- **`^/apply` stays entirely `PUBLIC_ACCESS`; `^/parent` stays entirely `ROLE_PARENT`.**
  Authenticated parent screens belong under `/parent`, never under `/apply`.
- **`php -l` after any edit to a file header.** A blank line above `<?php` is a fatal
  *"Namespace declaration statement has to be the very first statement"* error.
- **Run `app:tenant:update-schema` for every tenant** after changing an entity's
  mapping, and check for orphan FK rows first — a failure leaves a tenant half-migrated.
- **Never commit secrets.** `compose.yaml`, `.env.local` and `smtpkey*` are gitignored
  for that reason; server config lives on the server.
- **Delegated design decisions live in `docs/`** — read the relevant doc before
  changing behaviour (e.g. `docs/parent-application/DESIGN.md` has answered decision
  tables in §15 and a status table in §17).

## Where things are

| Area | Location |
|---|---|
| Parent-facing application flow (public) | `src/Controller/Tenant/ApplicationController.php`, `templates/tenant/apply/` |
| Parent portal applications (authenticated) | `src/Controller/Tenant/ParentApplicationController.php`, `templates/tenant/parent/applications/` |
| Shared admission engine (student + invoice + SMS) | `src/Service/AdmissionService.php` |
| Base layout / sidebar / global CSS | `templates/base.html.twig` |
| Design decisions | `docs/parent-application/DESIGN.md` |
| Email setup & troubleshooting | `docs/operations/EMAIL-SETUP.md` |
| Mobile standard | `docs/architecture/MOBILE-FIRST.md` |
| **Design system** (tokens, shells, brand, states) | `docs/architecture/UI-SPEC.md` |
| Design tokens — the single source of truth | `templates/_design_tokens.html.twig` |
| Brand mark | `public/images/brand/edus-mark.svg` |
