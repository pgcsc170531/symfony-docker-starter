---
description: "UI rules for Edus Twig templates — design tokens, app shell, mobile rules and custom-CSS specificity traps. Load when creating or editing anything under templates/."
applyTo: "templates/**/*.twig"
---

# Edus UI rules (templates)

Full standard: `docs/architecture/UI-SPEC.md`. Mobile prime directive:
`.github/copilot-instructions.md` — it always wins where the two overlap.

## Tokens — use these, not raw Tailwind colours

- `accent-*` (600 = `#2563EB`) for **every** interactive element, active nav, link, focus ring.
- `ink-*` for **all** greys. `ink-900` text, `ink-700` secondary, `ink-200` borders,
  `ink-50` page background.
- `paid` / `pending` / `failed` — the only three semantic colours, and **only** for status.

Do not introduce `indigo-*`, `gray-*` or any other palette in **new or touched** markup.
Existing files still carry them; converge as you edit, don't do a sweep.

Colour is meaning, never decoration. If an element "needs a colour to look nice",
it does not get one.

## ⚠️ Two silent-failure traps

1. **A token that is not in the inline config produces no CSS at all.**
   Tokens are defined in the `tailwind.config` block in `base.html.twig`
   (Tailwind is the runtime CDN — there is no `tailwind.config.js`). If a class
   like `bg-accent-600` appears to do nothing, check that block first.

2. **Custom CSS loses to Tailwind utilities on equal specificity.** Tailwind
   injects its stylesheet *after* the `<style>` block in `<head>`, so a plain
   `.tabbar-space` is beaten by `py-4`. Write `main.tabbar-space` — or any scoped
   selector — when overriding a utility. This is how the bottom tab bar's
   reserved space silently did nothing the first time.

## Layout — the page contract (UI-SPEC §4.1)

**There is exactly one shell: `base.html.twig`.** A page never owns a `<!DOCTYPE>`,
a `<body>`, a header, a nav or a footer.

```twig
{% extends 'base.html.twig' %}

{% block title %}Staff Directory{% endblock %}

{% block body %}
    <div class="px-4 py-4 sm:py-6">{# full width + small gutter #}
        ...
    </div>
{% endblock %}
```

Two blocks: `title` and `body`. **Not `content`.**

- **Only `<main>` scrolls.** Signed in, `<body>` is `app-shell` — locked to
  `100dvh`, `overflow: hidden` — and the header, rail and footer hold still while
  the content column moves. Never put `overflow-y: auto` on `<body>` or `<html>`.
- **Never `min-h-screen` inside `{% block body %}`**, and never a second
  `overflow-y-auto` wrapper. Each one forces a scrollbar on an empty page, and a
  nested scroll container makes every `sticky` inside it stick to the wrong box.
- **The page owns the horizontal gutter** (`px-4`, every width). `<main>` adds
  none, so the old double-padding bug cannot come back.
- **No `max-w-*` container and no `mx-auto` on an app screen.** Content fills the
  column. A `max-w-* mx-auto` wrapper turns a 1312px area into an 896px column
  floating in 208px of dead space, and every table inherits the squeeze.
- **`position: fixed` is for dialogs only.** It is the one thing that cleanly
  escapes the scroll container.
- **A sticky element inside a page needs `top-14`**, not `top-0`: `main`'s scroll
  port starts behind the glass header, so `top-0` parks it where nobody can see it.
- **The header owns the top offset.** Never add `pt-16` to clear the menu button.
- **The bottom tab bar is for parents** and is in normal flow at the bottom of the
  shell. It lists exactly **four** places; a fifth wraps to a second row and eats
  the bottom of every page. Staff navigate with the sidebar drawer.
- **Every app screen has a footer**, a **full screen width** band under both
  columns — a sibling of the sidebar+content row, never a child of `<main>`. It is
  **brand-only**: `▮ Edus` / "The school operating system", which *is* the credit
  and links to `edus.ng`, then `© <year> · All rights reserved`. The header carries
  the school; the footer carries the brand. **Say each thing once** — never repeat
  the school name or the brand in the footer, and never add a second footer to a
  page.
- **Public pages get no shell.** `app.user` decides. Marketing, the application
  form, receipts, ID cards and print sheets are standalone by design.
- **Never draw the mark inside a filled rounded tile** — a filled tile with a white
  lowercase "e" is the Microsoft Edge logo. `edus-mark.svg` is the bare letterform;
  only the favicon is tiled, and it is inverted.
- Breakpoints are Tailwind defaults only (0 / `sm` 640 / `md` 768 / `lg` 1024).
  Never invent one.

## Status and states

- Status pills: `.badge-paid` / `.badge-pending` / `.badge-failed` / `.badge-neutral`.
- **Never signal status by colour alone** — the badge text must state it.
- Money and marks: add `.money` (or `.tabular`) so figures align in columns.
- Screens that load data handle all five states: loading, empty, error, partial,
  not-permitted. An empty state says what *would* be here **and the action that
  creates it**.
- Never render an empty state as `₦0.00` — show the state ("not enrolled",
  "no data yet"). A zero is a claim.

## Before finishing

- [ ] Extends `base.html.twig`, uses `{% block body %}`
- [ ] Own gutter (`px-4`, `sm:px-6`); no `min-h-screen`, no second scroll container
- [ ] No horizontal overflow at **320px** and **360px**
- [ ] Nothing tappable under 44 × 44px on a phone
- [ ] No focused control under 16px font-size
- [ ] New colours are tokens, not a new palette
- [ ] Wide tables in `overflow-x-auto` with `min-w-[640px]` on the table
- [ ] Icon-only buttons have `aria-label`; decorative SVG is `aria-hidden="true"`
