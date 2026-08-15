# Architecture

How the pieces fit, and why they are shaped the way they are.

---

## Data model

Nothing lives in a bespoke table.

| Thing | Storage | Key |
|---|---|---|
| Form | post type `atf_form` | schema JSON in `_atf_schema` |
| Entry | post type `atf_entry` | values in `_atf_values`, context in `_atf_context` |
| Entry note | an ordinary **comment** on the entry post | — |
| Theme | post type `atf_theme` | tokens in `_atf_tokens` |
| Entry status | real post statuses | `atf-unread`, `atf-read`, `atf-spam`, `atf-partial` |
| Uploads | attachments, `post_parent` = the entry | `_atf_upload` |

### Why posts

`current_user_can()`, the REST API, revisions, search, the trash, and WordPress's
own privacy exporter and eraser already work on this data. A form's revision
history is version history for free. An entry note is moderated on the Comments
screen without this plugin reimplementing a thread. Deleting an entry takes its
uploads with it because they are its children.

### What it costs

Entries are rows in `wp_posts` alongside content, and a site taking a hundred
thousand submissions will feel that. The mitigations: entries are
`exclude_from_search`, not publicly queryable, and always fetched through
`atf_query_entries()`, which filters on an indexed meta key. If a site ever
outgrows this, the swap is behind that one function.

### Why the schema is one JSON document

A form is only ever read and written **whole** — the builder loads all of it and
saves all of it — so a row per field would buy nothing and a partial write is
always a bug. Storing it as one document also means post revisions give a way
back from a bad edit.

JSON rather than a serialised array so the value stays legible in the database,
survives a `wp db export`, and can be diffed between revisions by eye.

### Why statuses, not a meta flag

`wp_count_posts()` returns the per-status counts the entries table needs as a
single cached query. A meta flag would need a `meta_query` per tab on every page
load.

---

## The submission pipeline

One function, `atf_process_submission()`, and **every** route ends at it: the
REST endpoint the bundle posts to, the plain `POST` a form makes with JavaScript
off, and any programmatic call. One place where a submission is accepted is the
only way to be sure the two paths enforce the same rules.

The order is fixed and each step earns its position:

```
1  Availability   a closed form rejects before anything is parsed
2  Sanitise       every value through its field type, before it is read
3  Uploads        files become attachments, or the submission fails here
4  Calculations   recomputed server-side; the browser's totals are ignored
5  Validate       against the fields conditional logic says were visible
6  Spam           after validation, so a real visitor sees their typo first
7  Store          unless the form is set not to keep entries
8  Actions        create a post, register a user, call a webhook
9  Notify         e-mail, conditionally
10 Confirm        resolve what the visitor is shown next
```

**Visibility before validation** is the one that matters most. A field hidden by
conditional logic is not required and is not validated — checking it first would
reject a submission for not answering a question the visitor was never shown.

**Spam after validation**, so somebody who mistyped their e-mail address is told
about the typo rather than being quietly filed as spam.

**Notifications after storage**, so a mail server that is refusing connections
costs the site an e-mail rather than a submission.

### Sanitising walks the schema, not the request

`atf_sanitize_submission()` iterates the form's fields and reads the request for
each one. A key no field asked for is never read at all, so a forged
`atf[administrator]` reaches nothing.

---

## The two-engine problem

Conditional logic and calculations exist **twice** — once in PHP, once in
TypeScript — because both sides genuinely need them:

- the browser hides and shows fields as the visitor types, and shows a running
  total;
- the server decides what was actually required, and stores the real total.

A disagreement is the worst class of bug this plugin can have. So the cases live
in `tests/fixtures/*.json` and both suites run the same table. See
[`javascript.md`](javascript.md#the-twins).

The server is always the authority. The browser's copy is a convenience that is
never trusted.

---

## Progressive enhancement

The rendered form is a real `<form>` with a real `action` and `method="post"`.
Submitted with scripting off it posts, validates on the server, and comes back
with errors against the right fields and the visitor's answers still in them.

`atf_handle_post_submission()` runs on `wp` — early enough that a redirect
confirmation can fire before any output — and stashes the result for the
shortcode to render.

The bundle adds conditional logic as you type, live totals, step transitions,
inline validation, repeater rows, the signature pad and an AJAX submit **on top
of a form that already worked**. If the bundle fails to load, or throws, the form
still collects submissions — and `boot()` deliberately leaves a form alone rather
than half-enhancing it when its schema cannot be read.

Multi-page forms degrade to one long page, which is completable. Pages after the
first carry `data-atf-page-hidden` rather than `hidden`, so a slow bundle shows a
usable form rather than one page with no way forward.

---

## Rendering and theming

`render.php` owns the chrome every field shares — wrapper, label, hint, error,
logic attributes. `render-controls.php` owns the control itself, one `case` per
type. Adding a field type touches one of them.

Themes never change the markup. All ten render byte-identical DOM and differ only
in the custom properties emitted inline, scoped to the form's instance id — which
is both what lets two differently-themed forms share a page, and what keeps the
accessibility work done once. `test_themes_do_not_change_the_markup()` asserts it.

---

## OpenStation

Every shell call sits behind a `function_exists()` gate resolved through
`includes/shell-api.php`, which also resolves the **spelling**: the shell was
called Desktop Mode and is now called OpenStation, and
`desktop_mode_register_window()` became `openstation_register_window()`. Asking
for a capability by its bare name means a site mid-upgrade, a fork, or a shell
that renames itself again degrades to "no desktop integration" rather than a
fatal error on every request.

Registration happens on `init` at 20, wired up from `plugins_loaded` at 20 —
never at file scope, because plugins load alphabetically and `allterrain-forms`
runs before `desktop-mode`, so none of the shell's functions exist yet when the
file is first read.

### Why the builder is a native window

Rendering into the shell's own DOM is what gives it `wp.os.dragManager`, one
pointer pipeline shared with every other window. That is the whole difference:

- a field can be dragged between **two open builder windows**;
- an image dragged out of **WP Explorer** can be dropped onto an image-choice
  option;
- an entry dragged out of the Entries window carries
  `allterrain-forms/entry`, and any other plugin can accept it.

None of that is reachable from inside an iframe.

### Surfaces

| Surface | Id |
|---|---|
| Native window — the builder | `allterrain-forms` |
| Native window — entries | `allterrain-forms-entries` |
| Native window — Theme Studio | `allterrain-forms-themes` |
| Native window — the paired preview | `allterrain-forms-preview-<id>` |
| Wallpaper icon | `allterrain-forms` |
| Widget | `allterrain-forms/recent` |
| Title-bar button | `allterrain-forms/preview` |
| Commands | three, one per window |

### The admin-URL handoff

All three surfaces also have admin URLs, and with the shell up those URLs must
not become a second copy of the tool. Two copies of the builder on one page means
two autosave timers writing the same form.

The shell offers no way for a native window to *claim* a URL, and a title-bar
Related item can only be expressed as a URL — the shell opens one as a chromeless
iframe window. So the admin page renders a pointer instead of the tool, and
`src/handoff.ts` finishes the job: it opens the native window, then closes the
iframe window it is itself inside. Reaching the URL any way at all — bookmark,
Related menu, deep link — lands you in the native window.

The pointer's button remains for the case where the automatic path cannot run: no
shell, or a shell that refused. Deactivate the shell and the ordinary admin pages
come back untouched.

---

## Security posture

The places where getting it wrong costs somebody else something, and what stands
in the way.

**Uploads.** A per-field extension whitelist re-checked server-side; a MIME check
against the file's actual bytes, so `payload.php` renamed to `photo.jpg` is
refused; an unconditional forbidden-extension list that a form cannot override;
storage in a directory with a deny rule and an index file; unguessable filenames;
and `private` attachment status so they stay out of public queries.

**Calculations.** No `eval()`. Shunting-yard over a whitelist of pure numeric
functions. A formula is author-supplied, stored, and evaluated on every
submission — `eval()` would be remote code execution wearing a convenience
costume.

**Themes.** Token values land in a `<style>` block, so braces, semicolons, angle
brackets, backslashes, `url(`, `expression(`, `@import` and `javascript:` are
**refused** rather than escaped. There is no legitimate token value that needs
one.

**Exports.** A cell beginning `=`, `+`, `-` or `@` is prefixed with an
apostrophe, because a spreadsheet executes it on open.

**Post-submit actions.** Post types, post statuses and roles are each constrained
by a filter whose default is the narrow answer: `post` and `page`; `publish`
downgraded to `pending`; the site's own default role and nothing else. A form's
settings are editable by anyone with `atf_edit_forms`, which is a lower bar than
"may publish anywhere" or "may hand out roles".

**The client schema.** The front end gets a reduced slice — ids, types, logic,
bounds, choice prices — never notification recipients, webhook secrets, the spam
blocklist or quiz answers. Asserted by
`test_client_schema_leaks_nothing()`.

**Entries.** Not `show_in_rest`. An entry holds whatever the form asked for, and
core's generic handler would expose it to anyone who can read a post. Every read
goes through `atf_prepare_entry()`, which is where the capability check lives.

**The public routes.** `/submit` and `/track` are the only two, and `/track`
accepts one event and can only increment a counter.

---

## File layout

```
allterrain-forms.php          bootstrap, constants, activation
includes/
  shell-api.php               function_exists() gate + name resolution
  openstation.php             windows, icon, widget, commands
  preview.php                 the standalone front-end preview page
  post-types.php              post types, statuses, meta, capabilities
  fields.php                  the field-type registry
  field-types.php             the 37 built-ins
  schema.php                  normalise, store, page-split
  themes.php                  tokens, ten themes, CSS emitter
  logic.php                   conditional logic      ← twin of src/shared/logic.ts
  calc.php                    the expression evaluator ← twin of src/shared/calc.ts
  merge-tags.php              {field:f1}, {all_fields}, quiz scoring
  render.php                  form chrome, client schema
  render-controls.php         one control per field type
  availability.php            scheduling, limits, prefill
  validation.php              server-side validation
  spam.php                    honeypot, time trap, rate limit, Akismet
  uploads.php                 the dangerous one
  submission.php              the pipeline
  notifications.php           e-mail
  confirmations.php           what happens next
  actions.php                 post, user, webhook
  entries.php                 query, export, retention
  analytics.php               counters and per-field rates
  templates.php               the template library
  rest.php                    allterrain-forms/v1
  shortcode.php / block.php   placement
  admin-page.php              the no-shell fallback
  assets.php                  handles and config
  privacy.php                 exporter, eraser, policy text
src/
  form.ts                     the front-end bundle
  builder.ts                  palette, canvas, inspector
  theme-studio.ts             the token editor
  entries.ts                  the submissions window
  widget.ts                   the desktop widget
  preview-button.ts           the eye in the title bar
  logic-map.ts                conditions in words + the curves that draw them
  merge-tags.ts               the Insert-a-value picker
  handoff.ts                  admin URL → native window
  dock.ts                     the one dock tile and its flyout
  relations.ts                the window content graph
  dnd.ts                      drag manager + fallback
  api.ts / ui.ts / types.ts
  shared/logic.ts, shared/calc.ts
tests/
  fixtures/*.json             the shared conformance tables
  vitest/                     TypeScript
  phpunit/tests/              PHP
```
