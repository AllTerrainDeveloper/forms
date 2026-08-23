# JavaScript reference

Four bundles, four schedules, one drag model.

| Bundle | Loaded when | What it is |
|---|---|---|
| `form` | a page contains a form | Enhances the rendered form |
| `builder` | the builder window or admin page opens | Palette, canvas, inspector, Theme Studio |
| `entries` | the entries window opens | The submissions table |
| `widget` | somebody has the widget on their desktop | Recent submissions |

They are separate because a visitor filling in a contact form should not download
the form builder.

---

## `window.allTerrainForms`

The config blob every bundle depends on, printed by PHP as an inline script.

```ts
interface RuntimeConfig {
	restUrl: string;    // …/wp-json/allterrain-forms/v1
	wpRestUrl: string;
	nonce: string;      // empty for a logged-out visitor
	adminUrl: string;
	version: string;
	canEdit: boolean;
	canRead: boolean;
	locale: string;
	i18n: Record< string, string >;
}
```

The nonce is empty when nobody is logged in, deliberately: it is per-user and
therefore uncacheable, and a page cache would otherwise serve one visitor's nonce
to everybody.

Filter it with [`alltfo_script_config`](hooks-reference.md#alltfo_script_config--filter--stable).

---

## Drag payloads

The reason the builder is a native window rather than an iframe. All three ride
`wp.os.dragManager` — the shell's own pointer pipeline, shared with the
wallpaper's file tiles and every other window.

| Payload type | Emitted by | `data` |
|---|---|---|
| `allterrain-forms/field` | the palette, and every field on the canvas | `{ fieldType?, fieldId?, field?, isNew }` |
| `allterrain-forms/form` | the forms list | `{ form }` |
| `allterrain-forms/entry` | every row in the Entries window | `{ entry, formId, formTitle }` |

### Accepting an entry in your own plugin

This is the cross-app story: drop a submission onto your window and decide what
it means to you.

```javascript
wp.os.ready( () => {
	wp.os.dragManager.registerDropTarget( {
		id: 'my-plugin/inbox',
		element: document.querySelector( '#my-inbox' ),
		accept: ( payload ) => payload.type === 'allterrain-forms/entry',
		onEnter: () => inbox.classList.add( 'is-dropping' ),
		onLeave: () => inbox.classList.remove( 'is-dropping' ),
		onDrop: ( session ) => {
			// The whole entry, not just an id — so you can render something
			// meaningful immediately rather than making a REST call mid-drag.
			const { entry } = session.payload.data;

			createTicket( entry.title, entry.fields );
		},
	} );
} );
```

`data.entry` is the same shape `alltfo_prepare_entry()` returns: `id`, `formId`,
`formTitle`, `title`, `status`, `date`, `values`, `fields[]`, `starred`, `quiz`.

### What the builder accepts

`allterrain-forms/field` on the canvas — from its own palette, from its own
canvas, or **from a second builder window**, which is what a shared drag manager
buys and an iframe could not.

Media payloads (`openstation/file` and its older spellings) on an image-choice
option's image well, so a photograph dragged out of WP Explorer becomes that
option's picture.

---

## Events

### `alltfo-submitted`

Fired on `document` after a successful AJAX submission.

```javascript
document.addEventListener( 'alltfo-submitted', ( event ) => {
	const { formId, entryId } = event.detail;
} );
```

### `alltfo-refresh`

Fired **at** the bundle, not by it. Dispatch it when a form arrives in the DOM
after first paint — a modal, an AJAX-loaded page, a block preview — and every
unenhanced form on the page is enhanced. Idempotent: forms already booted are
skipped.

```javascript
document.dispatchEvent( new CustomEvent( 'alltfo-refresh' ) );
```

### OpenStation events the bundles listen for

`os-window-content-loaded` — the shell mounts a native window's markup after the
bundle has already run, so this is what triggers a mount into it.

`os.drag.start` / `os.drag.move` / `os.drag.end` — used to paint the drag source,
and to position the canvas's insertion marker.

`os.alltfo_entry.changed` — a cross-window broadcast the entries window and the
widget subscribe to, so a new submission appears without a refresh.

---

## The title-bar preview button

Registered through the shell's public `registerTitleBarButton` surface as
`allterrain-forms/preview` — the same seam the shell's own editor→preview pairing
uses.

```javascript
wp.os.registerTitleBarButton( {
	id:        'allterrain-forms/preview',
	label:     'Preview this form',
	icon:      'dashicons-visibility',
	placement: 'right',
	order:     90,                    // just before the shell's Related button
	match:     ( w ) => w.id === 'allterrain-forms',
	onClick:   () => openPreview( source ),
	owner:     'allterrain-forms-builder',
} );
```

Pressing it saves any unsaved work first — the preview is a render of the
*stored* form, so previewing without saving would quietly show the last saved
version and look like the builder had lost the edit — then opens
`?alltfo_preview_form=<id>` as a paired window.

Saving again refreshes that window rather than stacking a second copy, which is
what makes the builder-and-preview-side-by-side loop work.

Everything degrades: with no shell there is no title bar, `registerPreviewButton`
returns a no-op teardown, and the builder's own Preview button opens the same URL
in a tab.

---

## The REST API

Namespace `allterrain-forms/v1`. Everything but two routes requires
`alltfo_edit_forms` or `alltfo_read_entries`.

| Route | Method | Needs |
|---|---|---|
| `/submit` | POST | **public** |
| `/track` | POST | **public** |
| `/config` | GET | `alltfo_edit_forms` |
| `/forms` | GET, POST | `alltfo_edit_forms` |
| `/forms/<id>` | GET, POST, DELETE | `alltfo_edit_forms` |
| `/forms/<id>/duplicate` | POST | `alltfo_edit_forms` |
| `/forms/<id>/preview` | POST | `alltfo_edit_forms` |
| `/forms/<id>/merge-tags` | GET | `alltfo_edit_forms` |
| `/forms/<id>/analytics` | GET | `alltfo_read_entries` |
| `/entries` | GET | `alltfo_read_entries` |
| `/entries/<id>` | GET, POST, DELETE | `alltfo_read_entries` / `alltfo_delete_entries` |
| `/entries/export` | GET | `alltfo_read_entries` |
| `/themes` | GET, POST | `alltfo_edit_forms` |
| `/themes/<id>` | DELETE | `alltfo_edit_forms` |
| `/demo` | GET, POST, DELETE | `alltfo_edit_forms` **and** developer mode |

`/config` returns every registered field type with its `supports`, its
`settings` defaults, and — for a composite — the `parts` it can be told to
show, resolved through `alltfo_name_parts` / `alltfo_address_parts`. The builder
draws its controls from that, so a field type registered by a plugin gets the
same inspector the built-ins do without shipping any JavaScript.

`/demo` is the demo-data generator behind the analytics window's developer panel.
`GET` reports what exists, `POST` generates one chunk — call it until `remaining`
reaches zero — and `DELETE` removes every generated form and entry. It answers
**404 when developer mode is off**, which is why the window asks before drawing
the panel rather than drawing it and letting the buttons fail. A user without
`alltfo_edit_forms` gets the authorisation code instead, so a client can tell "not
allowed" from "switched off".

Every submission it makes goes through the ordinary pipeline, and everything it
creates is tagged so removal takes back exactly that and nothing else — a real
submission to the demo form survives being cleaned up.

`/submit` is public **by definition** — it is how a stranger sends a form. It is
the one route with a `permission_callback` returning true, and everything
downstream of it treats its input as hostile. The checks that would normally live
in a permission callback all depend on which form was posted, so they happen
inside the pipeline instead.

`/entries/export` returns the CSV **as a string in a JSON envelope**, not as a
file response. The entries window is a native window inside a single-page shell,
and navigating to a download URL would take the whole desktop with it; the bundle
turns it into a Blob and saves it locally.

Requests are routed through `wp.os.fetch` when the shell is present, which pulses
the window's title-bar activity dot and routes a 401 into the shell's own
re-authentication flow — so a session that expires mid-edit is recovered rather
than silently losing the save.

---

## The twins

`src/shared/logic.ts` and `src/shared/calc.ts` have PHP twins in
`includes/logic.php` and `includes/calc.php`, and **they must agree**.

The browser hides and shows fields as the visitor types; the server decides which
fields were actually required. If they disagree, the visitor is shown a form they
cannot submit, with an error about a field they cannot see — the worst bug this
plugin can have. For calculations, a disagreement means the number somebody was
shown is not the number that was stored, which on an order form is a charge
dispute.

So they are not tested twice. One table each, in
`tests/fixtures/logic-cases.json` and `calc-cases.json`, read by both suites:

```
logic-cases.json ──┬── tests/vitest/logic.test.ts
                   └── tests/phpunit/tests/logic.php
```

A case added to one language is a case added to both. 113 shared cases.

**The server remains the authority.** Nothing the browser decides is trusted:
`alltfo_visible_fields()` recomputes visibility from the submitted values,
`alltfo_apply_calculations()` recomputes every total, and validation only ever runs
against those.

### The calculation evaluator

No `eval()`, no `new Function()`, in either language. A formula is tokenised,
converted to postfix by the shunting-yard algorithm, and evaluated over a stack.
The only things that can come out are numbers.

Functions are a whitelist — `min`, `max`, `sum`, `avg`, `round`, `ceil`, `floor`,
`abs`, `sqrt`, `pow` — and that whitelist is the security boundary. Anything
added through `alltfo_calc_functions` must be pure and numeric.

References resolve before tokenising: `{field}` becomes a numeric literal,
`{repeater}` becomes its row count, and `{repeater.sub}` becomes either one
literal per row (as the sole argument of `sum`/`avg`/`min`/`max`) or the
parenthesised total across rows (anywhere else). See "Repeaters in formulas"
in field-types.md for the grammar; the shared fixture table holds both engines
to it.

---

## Using the drag fallback

`src/dnd.ts` exports the same interface whether or not there is a shell:

```typescript
import { getDragManager, buildPayload, insertionIndex } from './dnd';

element.addEventListener( 'pointerdown', ( event ) => {
	getDragManager().start( {
		payload: buildPayload( 'my-plugin/thing', element, { thing }, event ),
		origin:  event,
		// Called when the press never travelled far enough to be a drag, so one
		// element can be both a button and a drag handle without a click firing
		// after a drop.
		onClickOnly: () => open( thing ),
	} );
} );
```

Inside OpenStation this is `wp.os.dragManager`. Outside it, a smaller
implementation with the same interface — deliberately, so the builder has exactly
one drag code path. A builder with two is a builder where the fallback is broken
and nobody notices, because the people who would notice are all running the
shell.

Pointer events rather than HTML5 drag-and-drop, in both: HTML5 drag has no
programmatic cancel (Escape, alt-tab and system modals all strand the state), and
`setPointerCapture` anywhere in the ancestry silently stops `dragstart` firing at
all.
