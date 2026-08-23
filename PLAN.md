# AllTerrain Forms — build plan

> Forms for WordPress, built as an [OpenStation](https://github.com/WordPress/openstation)
> desktop app. Every feature the incumbents sell as "Pro" ships here, free, GPL.

This file is the working plan and the running checklist. It is written before the
code so the shape survives a context reset. Tick items as they land.

---

## 0. The thesis

Three claims, and everything else follows from them.

**1. The premium wall is artificial.** Conditional logic, multi-page forms,
calculations, file uploads, signatures, repeaters, save-and-resume, entry
management, CSV export, conditional notifications, surveys, quizzes, user
registration, front-end post submission, webhooks — every one of these is a
paid tier somewhere. None of them is hard. They ship here, in the free plugin,
on day one.

**2. A form builder is a spatial tool, so it belongs in a spatial shell.**
Dragging a field from a palette onto a canvas is the whole interaction. In
wp-admin that is a rectangle inside a rectangle. In OpenStation the builder is a
*native window* sharing `wp.os.dragManager` with every other window — so a field
can be dragged between two open forms, a media item can be dragged out of WP
Explorer onto an image field, and an entry can be dragged onto AllTerrain Work
to become a task. None of that is reachable from an iframe.

**3. A theme is data, not code.** Ten themes ship. An eleventh is made by
duplicating one in the Theme Studio and moving sliders — no PHP, no CSS, no
build step. Themes are token sets; the renderer only ever reads tokens.

## 1. Non-negotiables

- **OpenStation is required.** `Requires Plugins: desktop-mode` in the header;
  WordPress enforces it at activation and blocks deactivating the shell
  underneath us. The builder is a native shell window — the desktop *is* the
  product. `includes/shell-api.php`'s `function_exists()` gates stay as defense
  in depth (older WP, force-removed shell): admin shows a notice, the front end
  keeps rendering published forms. *(Changed 2026-08-19; was "optional" through
  0.1.0.)*
- **Everything is a post.** A form is a post, an entry is a post, an entry note
  is a *comment*, a theme is a post. No bespoke tables. That buys REST,
  `current_user_can()`, revisions, search, trash, the privacy exporter/eraser
  and every `save_post` integration for free.
- **The front end degrades to HTML.** A form submits and validates with
  JavaScript switched off — real `POST`, real server validation, real errors.
  JS adds conditional logic, calculations, step transitions and inline
  validation on top of a form that already worked.
- **Accessible or it doesn't ship.** Real `<label for>`, `aria-describedby` for
  hints and errors, `aria-invalid`, an error summary that takes focus, fieldsets
  for grouped controls, visible focus, no keyboard trap, WCAG AA contrast in all
  ten themes.
- **WordPress coding standards**, tabs, Yoda conditions, `defined( 'ABSPATH' ) || exit;`,
  full PHPDoc. TypeScript strict.
- **Tests alongside the code.** PHPUnit for PHP, Vitest for TS. A feature
  without tests is not done.

## 2. Data model

| Thing | Storage | Why |
|---|---|---|
| Form | post type `alltfo_form` | Schema (fields + settings) as JSON in `_alltfo_schema`. Revisions give version history free. |
| Entry | post type `alltfo_entry` | One post per submission. Values in `_alltfo_values`. Trash, search, privacy tools free. |
| Entry note | `comment` on the entry post | The Comments screen already moderates these. |
| Theme | post type `alltfo_theme` | Custom themes are user data. Built-ins are code-registered and not posts. |
| Entry status | post status (`alltfo-unread`, `alltfo-read`, `alltfo-spam`) | Counts in the list table come free. |

Post-type keys stay ≤ 20 characters (`register_post_type()` rejects longer), so
`alltfo_` rather than `allterrain-forms-`.

## 3. Field types (the palette)

Grouped as the palette groups them.

**Text** — text, textarea, email, url, tel, number, password, hidden
**Choice** — select, multiselect, radio, checkbox group, image choice, switch
**Date & time** — date, time, datetime, date range
**Advanced** — file upload, signature, rating (stars), scale (1–10 / NPS),
likert matrix, range slider, color, address (composite), name (composite),
country, repeater (a group of fields the user can add rows to)
**Layout** — section heading, HTML block, divider, spacer, page break, column row
**Special** — consent / GDPR checkbox, total (calculated), hidden computed,
honeypot (auto, never rendered in the palette)

Every type is a `Field_Type` registration — `alltfo_register_field_type()` — so a
third-party plugin adds one without touching this codebase.

## 4. Feature checklist (the "all premium, free" list)

### Building
- [x] Drag-and-drop builder on `wp.os.dragManager` (palette → canvas, reorder, cross-window)
- [x] Live preview — the Theme Studio repaints as you move a control; the
      builder's eye opens a **paired preview window** that refreshes on save
      (OpenStation's own editor→preview convention) rather than a pane that
      re-renders per keystroke
- [x] Field settings inspector (label, placeholder, hint, required, default, width, CSS class)
- [x] Multi-column layouts (rows of 1–4 fields)
- [x] Form templates library (contact, RSVP, application, survey, quiz, order, registration, feedback, booking, bug report)
- [x] Import / export a form as JSON
- [x] Duplicate form, duplicate field
- [x] Undo / redo in the builder
- [x] Keyboard-only field insertion and reordering (a11y parity with drag)

### Logic
- [x] Conditional logic: show/hide any field, page or section on any combination of answers (all/any, nested groups)
- [x] **Conditional logic you can see.** Every card on the canvas states its
      condition as separated parts — `SHOWN WHEN` · *Can you make it?* · is ·
      `Yes, I will be there` — rather than as one run-on sentence, because the
      question and the answer are both user text and their punctuation collides
      with the sentence's. The question is a chip that selects that field. The
      controlling question is badged with how many fields it decides, and curves
      are drawn from the controller to each dependent, labelled with the
      trigger. Hovering a card dims every curve it does not
      touch. A rule naming a deleted question is called out in red rather than
      rendered as a blank. Toggled from the toolbar, remembered per browser.
- [x] Conditional logic on notifications and confirmations
- [x] Calculations: arithmetic over field values, referenced by merge tag, with a safe expression evaluator (no `eval`)
- [x] Multi-page forms with a progress bar, step validation, and back/next
- [x] Save and continue later — resume link shown and copyable. **E-mailing it
      is left to `alltfo_partial_saved`**: which address to send it to is a
      per-form question, and guessing it wrongly sends somebody's draft to a
      stranger
- [x] Prefill from URL parameters, the logged-in user, the site and the date.
      *From a previous entry* is not shipped — it needs an identity to look the
      entry up by, which a logged-out visitor does not have
- [x] Unique-value validation (no duplicate e-mail addresses)
- [x] Field-level custom validation rules (min/max, pattern, length, file type/size)

### Submitting
- [x] Server-side validation as the source of truth; client mirrors it
- [x] AJAX submit with graceful non-JS fallback
- [x] File uploads to a protected directory, attached to the entry
- [x] Anti-spam: honeypot, time trap, submission rate limit, word blocklist, optional arithmetic challenge — no third-party captcha required
- [x] Akismet integration when the plugin is present (optional, gated)
- [x] Form scheduling (open/close dates) and submission limits (total, per user, per IP)
- [x] Login-required and role-restricted forms
- [ ] Double opt-in confirmation e-mail to the submitter — **not shipped in 0.1.0.**
      A notification to `{field:<email>}` covers "email the submitter"; a true
      double opt-in needs a confirm-link round trip and its own pending state,
      and half of one is worse than none.

### After submission
- [x] Multiple notifications, each conditional, with merge tags, CC/BCC, reply-to, attachments
- [x] Multiple confirmations, each conditional: inline message, redirect URL, or another page
- [x] **Merge tags nobody has to learn.** Every box that takes them has an
      *Insert a value* picker listing the form's own questions by the label the
      person wrote, grouped with the submitter, the submission, the form and the
      site — each row showing what it resolves to on this site. Under the box,
      the text with the tags filled in. The catalogue is served from PHP
      (`alltfo_merge_tag_catalogue`, `/forms/<id>/merge-tags`) so it cannot drift
      from what actually resolves, and the suite asserts every advertised tag
      does.
- [x] **Pre-fill asked in plain language.** The field inspector's "Pre-fill
      this with" is a grouped list — about the person, the date and time, about
      the site, a parameter in the web address — with a line underneath showing
      what it will actually put in the box on this site. It replaced a free-text
      box under a hint listing five examples of a syntax nobody had been taught,
      two of which (`user:name`, `site:name`) were not real sources at all.
- [x] **"Send it to" asked in plain language** — the site administrator, one of
      the form's own email questions, a specific address, or free text for the
      rest. The common cases need no tags at all.
- [x] Webhooks (POST the entry as JSON to any URL, with a signing secret)
- [x] Actions on submit: create a post, register a user, update user meta
- [x] `alltfo_entry_created` action so any plugin can hook the pipeline

### Managing
- [x] Entries window: table, search, filter by field value, date range, status
- [x] Entry detail view with the full submission, files, and notes
- [x] Bulk actions: read/unread, star, spam, trash, delete, export
- [x] CSV and JSON export, filtered by the current view
- [x] Per-form analytics: views, starts, submissions, conversion rate,
      completion rate, per-field response rate. *Average completion time* is
      not shipped — timing a visitor needs per-visitor state, and this plugin
      deliberately keeps none
- [x] Survey/quiz reporting: per-choice tallies and percentages, numeric
      averages, quiz scoring and pass marks. The API returns the aggregates;
      the entries window shows them as numbers rather than as charts
- [x] GDPR: retention policy with automatic deletion, IP anonymisation, integration with WordPress's own export and erase tools

### Presenting
- [x] Shortcode `[allterrain_form id="…"]`
- [x] Gutenberg block with a theme picker in the inspector
- [x] Ten built-in themes
- [x] Theme Studio: no-code theme creation, duplication and live editing
- [x] Per-form theme override, and a per-block override
- [x] Full RTL support, `prefers-reduced-motion`, `prefers-color-scheme`

## 5. The theme system

A theme is **one flat map of design tokens**. The renderer emits
`class="atf-form atf-theme-<slug>"` and a scoped custom-property block. Nothing
in the renderer knows a theme's name.

Token families — the "anything you can imagine" surface:

| Family | Tokens |
|---|---|
| Colour | `bg`, `surface`, `surface-alt`, `text`, `text-muted`, `heading`, `accent`, `accent-text`, `border`, `border-focus`, `error`, `success`, `overlay` |
| Radius | `radius-field`, `radius-button`, `radius-card`, `radius-check` |
| Shadow | `shadow-field`, `shadow-field-focus`, `shadow-button`, `shadow-button-hover`, `shadow-card` |
| Border | `border-width`, `border-style`, `field-style` (`outline` \| `filled` \| `underline` \| `none`) |
| Space | `gap-fields`, `gap-label`, `pad-field-x`, `pad-field-y`, `pad-card`, `density` |
| Type | `font-family`, `font-family-heading`, `size-base`, `size-label`, `size-hint`, `size-heading`, `weight-label`, `weight-button`, `letter-spacing`, `line-height`, `text-transform-label` |
| Label | `label-position` (`top` \| `inside` \| `floating` \| `left` \| `hidden`) |
| Button | `button-bg`, `button-text`, `button-bg-hover`, `button-width` (`auto` \| `full`), `button-align` |
| Focus | `focus-ring-width`, `focus-ring-color`, `focus-ring-offset` |
| Motion | `transition-duration`, `transition-easing`, `field-lift` |
| Effects | `backdrop-blur`, `field-gradient`, `card-gradient`, `noise` |

The ten built-ins:

1. **Clean** — the neutral default. Outlined fields, soft radius, no shadow.
2. **Midnight** — dark, high contrast, luminous accent.
3. **Glass** — translucent surfaces, backdrop blur, hairline borders.
4. **Brutal** — hard edges, 3px black borders, offset drop shadow, no radius.
5. **Paper** — warm off-white, serif headings, underline fields, print-like.
6. **Neon** — near-black, saturated gradient accents, glow focus rings.
7. **Terminal** — monospace, green on black, square, blinking caret accent.
8. **Soft** — neumorphic; low-contrast surfaces, dual inset/outset shadows.
9. **Editorial** — large serif headings, generous space, minimal chrome, left labels.
10. **Holo** — the OpenStation brand: mesh gradient accents, Pulse focus ring.

No-code expansion: **Theme Studio** is a native window with a control per token
and a live preview. Duplicate a built-in, move sliders, name it, save — it
becomes an `alltfo_theme` post, selectable everywhere a built-in is, exportable as
JSON, importable on another site. `alltfo_themes` / `alltfo_theme_tokens` filters for
developers who *do* want code.

## 6. OpenStation surfaces

| Surface | What it is |
|---|---|
| Native window `allterrain-forms` | The builder — palette, canvas, inspector, preview |
| Native window `allterrain-forms-entries` | Entries table and detail |
| Native window `allterrain-forms-theme-studio` | Token editor with live preview |
| Desktop icon | Opens the builder |
| Dock item | Ditto, with a badge for unread entries |
| Widget | Recent submissions + conversion sparkline |
| Commands | "Forms: new form", "Forms: open entries", "Forms: theme studio" |
| Settings tab | Global defaults, spam, retention |
| File type | A form is a desktop file you can drag around |

**Drag payloads emitted** — other plugins can accept these:
`allterrain-forms/field`, `allterrain-forms/form`, `allterrain-forms/entry`.

**Drops accepted**: media from WP Explorer (onto image-choice and file fields),
`allterrain-forms/field` (palette → canvas, canvas → canvas, across two open
builder windows).

**Cross-app story**: drag an entry from the Entries window onto an AllTerrain
Work column and it becomes a task. That is the North Star demo.

## 7. File layout

```
allterrain-forms.php          bootstrap, constants
includes/
  shell-api.php               function_exists() gate over the shell
  openstation.php             window / icon / widget / command registration
  post-types.php              alltfo_form, alltfo_entry, alltfo_theme, statuses, caps
  fields/                     the field-type registry + one file per group
  schema.php                  form schema normalise + validate
  render.php                  server-side form renderer (accessible HTML)
  submission.php              validate → sanitise → spam → store → notify → confirm
  logic.php                   conditional-logic evaluator (PHP twin of logic.ts)
  calc.php                    safe expression evaluator
  merge-tags.php              {field:3}, {user:email}, {entry:id}, …
  notifications.php           e-mail pipeline
  confirmations.php           post-submit resolution
  entries.php                 query, export, retention
  analytics.php               views / starts / conversions
  themes.php                  built-in tokens + theme registry + CSS emitter
  rest.php                    allterrain-forms/v1 routes
  shortcode.php               [allterrain_form]
  block.php                   Gutenberg block
  admin-page.php              no-shell fallback UI
  assets.php                  handles
  privacy.php                 exporter / eraser / retention
src/
  builder/                    the native window bundle
  form/                       the front-end bundle (logic, calc, steps, validation)
  theme-studio/               the token editor bundle
  widget.ts
  types.ts                    wire shapes, shared with PHP
assets/css/                   form.css, themes.css, builder.css
tests/phpunit/                PHP tests
tests/vitest/                 TS tests
docs/                         hooks reference, field-type API, theme API
```

## 8. Build order

1. Bootstrap, constants, post types, capabilities, shell-api
2. Field-type registry + schema normalisation *(tests)*
3. Themes: tokens, ten built-ins, CSS emitter *(tests)*
4. Server renderer + front-end CSS *(tests)*
5. Submission pipeline: validation, spam, storage *(tests)*
6. Merge tags, notifications, confirmations *(tests)*
7. Logic + calc engines, PHP and TS twins *(tests both sides)*
8. Front-end bundle: logic, calc, steps, inline validation, AJAX
9. REST API *(tests)*
10. Builder window: palette, canvas, drag, inspector, preview
11. Entries window + export + analytics
12. Theme Studio
13. OpenStation registration: icon, dock, widget, commands, drag payloads, file type
14. Shortcode + block + admin fallback page
15. Templates library, import/export
16. Privacy, retention, GDPR
17. Docs, README, readme.txt

## 9. What shipped in 0.1.0

Everything above except the one item marked *not shipped*.

Verified on this machine:

| Check | Result |
|---|---|
| `php -l` over every PHP file | clean |
| `tsc --noEmit` | clean |
| `npm run build` | 4 bundles × 2 modes = 8 |
| `npx vitest run` | 139 passed |
| PHP/TS engine parity (113 shared cases) | 113 passed, 0 failed |
| Theme tokens ↔ stylesheet, both directions | no unused, no undeclared |
| WCAG AA contrast, 8 measurable themes | all pass (lowest 8.01:1) |

PHPUnit (9 files, ~150 cases) is written and lints clean; it needs a WordPress
test library to run, which this machine does not have.

## 10. Definition of done

- `npm run typecheck && npm test` green; PHPUnit green
- The plugin activates on a site with **no** OpenStation and works
- The plugin activates **with** OpenStation and shows the builder as a native window
- A form can be built by dragging, themed, embedded, submitted, and the entry read back
- Ten themes render legibly in light and dark, LTR and RTL
- Docs describe every hook, the field-type API and the theme token surface
