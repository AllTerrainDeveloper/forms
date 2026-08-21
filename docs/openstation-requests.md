# What AllTerrain Forms needs from OpenStation

A report from one plugin, written after building a full form builder — three
native windows, a dock tile, a widget, a title-bar button, a relations graph and
a drag bridge — entirely on top of the shell.

Everything here is a **concrete pain with a concrete case**, not a wishlist. Each
item says what we tried, what we shipped instead, and what it costs.

Measured against the shell as installed in `wordpress-alcazaba` on 2026-08-15.

> **Update — item 1 is done.** `wp.os.loadComponents( tags? )` shipped, and all
> **59 of 59** tags now register. This plugin has adopted five of them; the
> remaining asks below stand, renumbered around the one that closed.

---

## Summary

| | Before | Now |
|---|---|---|
| `os-*` tags registered at runtime | 23 of 59 | **59 of 59** |
| `os-*` tags AllTerrain Forms uses | 0 | **5** |
| Shell APIs we wanted and could not reach | 3 | 2 |
| Palette tokens we read wrongly | 3 | 0 (ours to fix, fixed) |

The blocker is gone. What follows is what we did with it, and what is left.

---

## 1. A runtime way to load components — **delivered**

**Asked for:** `await wp.os.loadComponents( [ 'os-switch', 'os-number-field' ] )`.
**Shipped:** exactly that, with a better contract than we asked for — no
argument loads the whole kit, unknown tags are reported without stopping the
rest, and a repeat call costs a registry lookup rather than a fetch. Measured at
0 ms on the second call.

**What we did with it.** `ui.ts` now renders `<os-button>`,
`<os-checkbox-label>`, `<os-number-field>` and `<os-select>` / `<os-option>`,
each behind a `hasComponent()` check with the plain-HTML fallback kept — the same
builder still runs on a wp-admin page with no shell, where an unregistered tag
would render as a label with no control around it. `whenComponents()` awaits the
loader once before the first render, because `hasComponent()` decides at the
moment an element is built.

That deleted the entire class of bug described in item 6: 17 checkboxes and 46
selects in the builder are now shadow-DOM components that `forms.css` cannot
reach.

The original problem statement is kept below, because it is the argument for
keeping the loader rather than folding the kit into the boot bundle.

<details>
<summary>Why this was the blocker</summary>

The shell registers a core subset at boot and pre-loads the overlay kit. The
other 36 tags upgrade only when *a loaded bundle has imported their module*.
`use-from-a-plugin.md` gives one route:

```jsonc
{ "dependencies": { "openstation": "file:../openstation" } }
```

That is exactly right for a plugin developed beside the shell in one checkout,
and unavailable to a plugin that ships. AllTerrain Forms installs from a zip onto
a site that has OpenStation somewhere in `wp-content/plugins/` — there is no
`../openstation` relative to our `package.json` at build time on a contributor's
machine, and `file:` resolves at *install* time, not at runtime.

The alternative the doc offers is bundling. That means shipping a second copy of
components the shell already has on the page, in every plugin that wants a
switch. For the full kit the doc says "much more" than 3 KB gzip; our whole
builder bundle is 47 KB.

The externalize-plus-shim route needs `window.openStation` to exist as a module
namespace, and it does not.

**So today the honest options are: use the 23 registered tags, or hand-roll.** We
hand-rolled, and items 6–8 are what that cost.

A one-line async loader would unblock the entire kit for every third-party
plugin, and it is strictly less code than the docs already written about why it
is hard.

</details>

---

## 2. Components we would adopt the day they are reachable

Ranked by how much hand-rolled code they would delete from this plugin.

All of these are reachable now. ✅ marks what we have adopted since the loader
shipped; the rest are ours to get to.

| Component | Adopted | What it replaces here |
|---|---|---|
| `os-checkbox-label` | ✅ | Every checkbox in the builder and inspector. See item 6 — this one is not cosmetic. |
| `os-color-field` | — | The Theme Studio's colour dials. 69 tokens, ~20 of them colours. |
| `os-range-field` | — | The Theme Studio's Roundness / Density / Depth sliders. |
| `os-number-field` | ✅ | Min / max / step / rows / retention-days — a dozen inspector fields. |
| `os-switch` | — | Every "on/off that applies immediately" setting. |
| `os-badge` / `os-chip` | — | `REQUIRED`, `CONTROLS 2 FIELDS`, and the condition chips. |
| `os-empty-state` | — | "No forms yet", "Drag a field from the left to begin", "Select a field to change it". Three hand-rolled placeholders. |
| `os-panel` / `os-section` | — | The inspector's collapsible Validation / Conditional logic sections. |
| `os-table` | — | The entries list — sortable, filterable, with a selection column. We wrote all of it. |
| `os-swatch-grid` | — | The Theme Studio's ten theme swatches. |
| `os-tag-input` | — | The spam blocklist, currently a textarea. |
| `os-relative-time` | — | Entry timestamps. |
| `os-key` | — | The keyboard shortcuts in tooltips. |
| `os-select` | ✅ | Every text input we own — see item 6. |
| `os-segmented` | — | The Theme Studio's Roundness / Density / Fields / Labels controls, which are segmented controls we drew ourselves. |
| `os-button` | ✅ | ~30 buttons. |
| `os-notice` | — | Inline warnings in Settings. |
| `os-spinner` / `os-progress-bar` | — | Loading states. |

`os-text-field` and `os-textarea` are the one adoption we have **not** made, and
the reason is item 4's last entry: our merge-tag picker inserts at the caret.

---

## 3. Components that do not exist, and that more than one plugin will want

These are the ones we built from scratch that felt like they should have been
somebody else's problem. Each has a general shape, not just ours.

### `os-token-field` — a text field that takes typed tokens

**Our case:** every notification and confirmation box accepts merge tags
(`{field:f2}`, `{all_fields}`). The syntax is undiscoverable, so we built an
*Insert a value* popover listing the form's own questions by label, grouped, each
row showing what it resolves to on this site, plus a live "reads as" preview
under the box.

**The general shape:** a text or multiline field, plus a token catalogue
(`{ group, label, token, sample }[]`), that inserts at the caret and can render
inserted tokens as chips. Anything with templating needs it: email templates,
webhook bodies, redirect URLs, filename patterns, notification text.

We wrote ~330 lines for it. It is the single most reusable thing in this plugin.

### `os-condition-builder` — rule rows

**Our case:** *Show / Hide this field when all / any of these match*, then N rows
of `field · operator · value`, with add and remove.

**The general shape:** a subject list, an operator set, a value control that
changes with the subject, `all`/`any`, and a delete. Conditional logic appears in
forms, automations, notification routing, access rules, pricing rules. Everyone
rebuilds this.

### `os-connector-layer` — curves between elements in a list

**Our case:** drawing which question controls which other question, labelled with
the trigger value, dimming everything a hovered card does not touch.

**The general shape:** given `[ { fromEl, toEl, label, tone } ]`, draw routed
curves in an overlay that survives scroll, resize and reorder. The shell already
draws ties between related *windows* — the maths exists; this is the same idea
one level down.

Ours is ~180 lines and needed three iterations to stop being clipped by the
scroll container. A component would have got the geometry right once.

### `os-field-row` — label, control, hint, error

The inspector's atom. We call it `row()`. Every settings UI in every plugin has
one, and they all differ slightly, which is why settings panels never quite match
each other.

### `os-repeater` — add / remove / reorder a list of rows

Choices, logic rules, notifications, confirmations, post-submit actions. Five
places in this plugin alone, each with its own add button and trash icon.

### `os-media-picker`

Image-choice fields and theme background images. Every plugin that touches the
media library writes this against `wp.media`.

---

## 4. Shell APIs we needed and could not reach

### `registerNativeUrlRemap` is internal

`native-url-remap.ts` documents itself as the answer to "when something points at
admin URL X, open native window Y instead", and says *"Plugins can hook the
public `wp.os.registerNativeUrlRemap()` (added later)"*. It is not on `wp.os`
(`typeof` → `undefined`).

**Our case:** the title bar's **Related** menu offers "Entries for this form".
`RelatedEntityItem` can only express a destination as a URL, so the shell opens
our admin URL as a chromeless iframe window — a second copy of a tool that
already exists as a native window.

**What we shipped:** the admin page renders a pointer, and a script inside the
iframe opens the native window and then closes the window it is itself inside. It
works, and there is a visible flash of a window that exists only to dismiss
itself. Finding the window to close also needed a 4-second retry loop, because
the shell wires a window's `iframe` to its `Window` object *after* the iframe's
own scripts run.

**Ask:** expose it. One line of surface removes all of that.

### `RelatedEntityItem` cannot name a window

Related items carry `{ id, group, label, url, icon, count }`. A native window has
no URL. Even with the remap exposed, expressing "open *that* window, scoped to
this form" means encoding it in a URL and decoding it back out.

**Ask:** optional `windowId` and `params` on `RelatedEntityItem`, the way
`submenu` rows on a system tile already take `windowId`.

### A native window cannot read its own open-time params

`WindowConfig.params` is documented, and `openWindow( id, { params } )` accepts
them. But a native window declared with a PHP `template` plus a `script` handle
never receives them: there is no render callback to hand them to, and
`wp.os.getWindowConfig( 'allterrain-forms' )` returns `undefined`.

**What we shipped:** `sessionStorage`, written by the handoff and consumed by the
window on mount.

**Ask:** `wp.os.getWindowParams( windowId )`, or put them on the
`os-window-content-loaded` event detail.

---

## 5. Palette gaps

Three tokens we reached for and did not find. All three led to a real visual bug
in this plugin.

### There is no field surface token

`desktop-themes.md` documents `surface`, `surface-elevated`, `surface-sunken` —
nothing for an input. We used `--os-ui-modal-field-bg`, which is a **modal**
token: the palette re-points it inside `<os-modal>` and nowhere else, so outside
a modal it fell through to a hardcoded `#fff`. Every input in the builder was a
bright rectangle on a dark theme.

**Ask:** `--os-ui-field-bg`, `--os-ui-field-border`, `--os-ui-field-fg` in the
documented palette. Light-DOM markup needs them; shadow-DOM components already
resolve them privately.

### `--os-ui-accent-text` does not exist

The name is `--os-ui-fg-on-accent`. We had `accent-text` in a fallback chain and
it silently resolved to nothing. An easy mistake to make and an invisible one to
find — the fallback just wins.

**Ask:** an alias, or a mention in the docs that the name is not what a reader
guesses.

### `--os-ui-badge-*` exists but is not the palette

`--os-ui-badge-warning`, `--os-ui-badge-success` and `--os-ui-badge-danger-bg`
all resolve at runtime, so reading them looks correct — but they are
component-local, and a theme that re-points `--os-ui-warning-fg` without touching
them leaves a plugin's badges on the old colour.

**Ask:** document which `--os-ui-*` names are palette (theme-settable) and which
are component-local. The ~190 component tokens are documented next to their
components; a plugin author reading only `desktop-themes.md` cannot tell the two
families apart, and both resolve.

---

## 6. The raw-control trap, and how much it cost us

`components-reference.md` puts this well:

> A field you had already tokenized still renders as a white core-chrome box …
> **Use them.**

We did not, because most of the fields we wanted were not registered. Here is the
bill, all of it real bugs found in testing:

1. **Checkboxes sat 7.4px above their labels in every theme.** WordPress's
   `forms.css` has `input[type="checkbox"] { margin: -0.25rem … }` at **(0,1,1)**,
   which beats any single class. Fixed by scoping every control rule under
   `.atf-form` to reach (0,2,0).

2. **Then they moved again on the front end only.** A site theme setting
   `input { line-height: 1.65 }` applies a *specified* value, which beats
   inheritance, so the box centred on a 26.4px line while its label sat on a 24px
   one. Fixed by pinning `line-height` on both.

3. **The accent never reached checkboxes or radios.** `accent-color` only works
   while the browser is still painting the control, and `forms.css` sets
   `appearance: none` on every one of them in the admin — so `accent-color` was
   inert and the tick was painted by whichever stylesheet claimed
   `:checked::before`. Inside a window that is the shell, and the mark came out in
   the shell's brand pink whatever accent the user had chosen. We now draw the
   box, the border, the radius and the tick ourselves.

That third one is interesting from your side: the kit hit the same wall and
solved it with `holoCheck`, which the docs say "replaced `accent-color`". A
plugin rendering a light-DOM checkbox inside a window has no equivalent.

**Ask, in descending order of usefulness:**

1. Make `os-checkbox-label` reachable (item 1).
2. Failing that, ship a light-DOM utility class — `.os-check` — that applies the
   same paint to a raw `input[type=checkbox]`. `window-chrome.css` already does
   something like this for admin markup you do not control; this would be the
   version for markup a plugin *does* control but cannot componentise yet.
3. Document the specificity floor. `window-chrome.css` uses
   `.os-window__body :is( input[type], … )`, which weighs **(0,2,1)** — so a
   plugin's own control rules must reach (0,3,0) to win inside a window. That
   number is knowable only by reading the shell's source.

---

## 7. Smaller things

- **`getComputedStyle` inside a background tab.** Not yours, but worth a note in
  the testing docs: any shell UI driven by `requestAnimationFrame` (the window
  manager, our own overlays) simply does not paint while `document.hidden`, which
  makes automated visual checks report empty DOM. Cost us three false
  diagnoses today.

- **`wp.os.windowManager.getAll()[n].iframe` is populated late.** Documented
  nowhere; discovered by polling. A `window.whenIframeReady()` would help.

- **A system tile with no landing page runs its first submenu row on head
  click.** Sensible, and worth documenting — we shipped a tile whose head opened
  the wrong window until we reordered the rows.

---

## What we are fixing on our side regardless

So this does not read as a list of other people's problems:

- **Done:** `button()` now renders `<os-button>` when the tag is registered and a
  plain `<button>` when it is not — the same builder runs on a shell-less admin
  page, where an unregistered tag would render as a label with no button around
  it. Nine buttons in the toolbar upgraded with no other change.
- **Done:** `os-checkbox-label`, `os-number-field`, `os-select` / `os-option`,
  behind `hasComponent()` with the plain-HTML fallback kept. 17 checkboxes and 46
  selects, none of them raw any more.
- **Blocked, and this is the one remaining ask on the component side:**
  `os-text-field` and `os-textarea`.

  Our merge-tag picker inserts a tag **at the caret** — a subject line is usually
  "New enquiry from ‹here›", so appending would make every insertion need a
  cut-and-paste afterwards. That needs `selectionStart`, `selectionEnd` and
  `setSelectionRange` on the element we are handed. The host exposes none of
  them:

  ```js
  'selectionStart' in osTextField          // false
  typeof osTextField.setSelectionRange     // 'undefined'
  osTextField.shadowRoot.querySelector( 'input' )   // works, but is not a contract
  ```

  The shadow root is open, so we *can* reach the inner input — and we would
  rather not, because a plugin reaching into another component's shadow DOM
  breaks the day its internals change, and it would be our fault for having done
  it.

  **Ask:** forward the three caret members on the host, or expose the focusable
  inner control as a documented getter. Any component wrapping a text input that
  another plugin wants to insert into needs this — templating fields, code
  editors, anything with an autocomplete.
- Read `--os-accent` first, `--os-ui-accent` second, for the accent. We had
  been reading `--os-titlebar-bg-focused` on the mistaken belief that
  `--os-ui-accent` was pinned to the brand pink — true of the brand theme and
  of nothing else. On a monochrome theme a title bar is `#1a1721`, so every
  accent in our builder resolved to near-black: unfilled buttons, an active tab
  that looked inactive, invisible chips. **A surface token cannot stand in for
  an accent token**, and that was our error, not the shell's.

  The second lesson took longer: `--os-ui-accent` alone was not right either,
  because the shell writes the user's accent *preference* over it as an inline
  style — a value that sits at the brand's Pulse pink until the person actually
  picks one. Under a worn theme that declares a different accent (Legacy
  declares WordPress blue), reading only the kit token painted our windows pink
  inside blue chrome. `--os-accent` is the accent the *theme* declares, so our
  windows now read theme first, kit second — and re-point the kit tokens for
  the `os-*` components they embed, so a primary button inside our window
  matches the window rather than a switch in OS Settings.
- Read the documented palette names for status colours rather than the
  component-local `badge-*` ones.
- Use `surface-sunken` for rails and `surface-elevated` for bars, instead of
  painting both with `surface` — which several themes set to the same value as
  the window background, making both invisible.
