# WP Explorer — what this plugin needed, and what it still needs

The companion to [`openstation-requests.md`](openstation-requests.md), for the
shell's **My WordPress** window (WP Explorer). It records what the Explorer's
extension surface let us ship, and — for the Explorer's developer — the asks
that would let a forms plugin appear there the way it deserves to.

The vision being asked for, in one sentence: **each form as a folder, with its
preview, its submissions and its report inside** — the way a project reads in
a file manager.

## What the current surface allowed (shipped)

| Surface | What we did with it |
|---|---|
| `openstation_my_wordpress_entities` | Added a **Forms** section at the root — necessary because every type this plugin registers is `show_ui => false` (it has its own windows), and the Explorer only auto-lists `show_ui` types. |
| `rest_prepare_atf_form` (core) | Tiles carry vitals — *12 questions · 493 entries · Clean* — instead of a bare title. (Needed `excerpt` added to the type's `supports`; without it core REST omits the field entirely.) |
| `openstation_my_wordpress_preview_actions` | Three capability-gated actions declared — **Open in the form builder**, **View entries**, **Open the report** — with `onSelect` wired via `os.my-wordpress.preview-actions` in `dock.ts`. Registered correctly on both sides, but see ask 0: the pane never renders them. |

## The asks

### 0. Preview actions never render for a plugin-added section *(bug, with repro)*

Descriptors survive `openstation_my_wordpress_preview_actions` (verified:
`wp eval 'apply_filters(...)'` lists all three with `sections: ['atf-forms']`),
and the JS filter attaches `onSelect` (verified:
`wp.hooks.applyFilters('os.my-wordpress.preview-actions', …)` returns them
wired). Yet the right pane and the tile context menu render only the shell's
defaults — *Explore details / Open in editor / Navigate into / Edit… / Move to
Trash*. Either plugin-added sections aren't matched against `sections`, or the
pane reads a config snapshot taken before plugins filter. Repro: install
AllTerrain Forms, open the Forms section, select a tile.

### 0b. The default "Open in editor" must be overridable per section *(bug)*

On a section whose type has no post-editor screen (`show_ui => false`), **Open
in editor** and **Edit…** try the classic editor URL and fail. Ask: let a
section descriptor declare its own `editAction` (or honor a preview action
flagged as the editor replacement), so a type edited by a native window opens
in it.

### 0c. What the right pane should let a plugin say *(from user testing)*

Three things a person selecting a form immediately wanted, none expressible:

- **A scaled-down live preview** — the section's posts have a front-end render;
  a ~50% iframe of it in the pane beats a title and a date. Ask: a section
  field naming a preview URL per post (we already serve one), rendered scaled
  in the pane.
- **Stat cards above the preview** — submissions, conversion, unread. Ask: a
  descriptor for pane stat tiles fed by REST fields the plugin already exposes.
- The action buttons from ask 0 under them.

### 1. Per-post children — a post as a folder *(the big one)*

The Explorer's model is flat: sections of posts. A form is a *container* — its
submissions belong inside it. Ask: let a section declare a `children` resolver
(REST path template per post, e.g. `.../forms/{id}/entries`), so a tile can
open as a folder of plugin-provided items instead of a preview pane. Everything
below gets better if this lands. (The context menu already offers **Navigate
into** backed by `openstation_my_wordpress_attached_media` — so the window can
render a post's children today; what's missing is letting a plugin supply
children that are not attachments.)

### 2. Virtual items inside a folder

With children, a form's folder wants non-post items too: a **Preview** node
that opens the front-end render, an **Export.csv** node that streams the
export, a **Report** node. Ask: a child-item descriptor with `kind:
'action' | 'file' | 'window'` and an `onOpen` contract, so a folder can hold
things that are not posts.

### 3. Capability-scoped bridged sections for non-REST types

Entries are `show_in_rest => false` **by privacy design** — their values are
whatever the form asked for. The shell's own bridge controller
(`desktop-mode/v1/post-type/{type}`) exists, but only for types the shell
itself lists. Ask: a supported way to request a bridged section for a hidden
type, with a declared capability (`atf_read_entries`) enforced by the bridge —
then submissions could be listed without making them world-REST-readable.

### 4. Custom post statuses in section queries

Entry statuses are real post statuses (`atf-unread`, `atf-read`, `atf-spam`),
registered `exclude_from_search`. A `wp/v2`-backed section lists `publish`
only. Ask: let a section descriptor declare `statuses: [...]` that the window
passes through to its list requests.

### 5. Drag payloads on tiles

This plugin's Entries window emits `allterrain-forms/entry` as a public drag
payload (an entry dragged onto AllTerrain Work becomes a task). Explorer tiles
should be able to carry the same: a section-level `dragPayload( post )`
contract handing `wp.os.dragManager` a typed payload, so anything listed in
the Explorer is draggable everywhere its window equivalent already is.

### 6. The selected entity as an argument to preview actions

`onSelect` on a preview action receives nothing. To deep-link — *open the
builder on this form*, not just open the builder — the click needs the entity
it was clicked on. Ask: call `onSelect( action, entity )`, and document it.
(Half of this is ours: the builder should accept an `openWindow` param naming
the form. Tracked on our side.)

### 7. Count badges on sections

The dock tile shows an unread-entries badge; the Explorer section should be
able to as well. Ask: an optional `badge` callback or REST field on the
section descriptor.

## Status

Ask 0 is a bug with a repro; 0b breaks a shipped default on our types; 0c is what user testing asked for within a day. Asks 1–2 are the vision; 3–4 unlock submissions; 5–7 are polish. None of them
required patching the shell to *want* — every shipped piece above went through
existing filters, which is the discipline working exactly as intended.
