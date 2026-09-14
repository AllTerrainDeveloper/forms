# Form structure and shared field properties

[Knowledge index](index.md) · [Components](components/index.md) · [Conditions](conditions.md)

## Editor document

MIO uses a complete YAML document with two root keys: `title` (nonempty plain text) and `schema` (object). It uses the same form definition as the portable file format. File export adds a versioned package envelope and theme/media dependencies; the MIO editor operates on the current site's dependencies.

```yaml
title: Contact us
schema:
  version: 1
  fields:
    - id: name
      type: text
      label: Name
      required: true
  settings:
    theme: clean
    themeOverrides: {}
  notifications: []
  confirmations: []
  actions: []
```

`schema.version` is 1. `fields` is ordered: moving an item changes its position. `settings` configures the whole form. `notifications`, `confirmations` and `actions` are separate ordered lists. Missing optional properties get site defaults on creation. On updates, retain every unrelated property from begin_form_edit, including nested lists and overrides. Sending only changed fields replaces the form with that incomplete list.

## Field identity and common properties

| Property | Type/default | Behavior |
|---|---|---|
| id | required string | Stable unique ID in its scope, letters/numbers/underscore. Conditions and formulas use this, not the label. |
| type | required string | Exact installed type, such as text, textarea or image_choice. Read the live field registry. |
| label | string, empty | Visible question/legend. Renaming it should retain the ID. |
| placeholder | string, empty | Input hint; not a replacement for a visible accessible label. |
| hint | allowed HTML string, empty | Additional instructions. Unsafe markup is rejected. |
| required | boolean, false | Requires an answer only while the field is visible. Layout components do not collect answers. |
| width | full | full, half, third, two-thirds or quarter. Responsive rendering may stack columns. |
| cssClass | string, empty | One sanitized CSS class name. Theme styling belongs in tokens. |
| default | answer-shaped | Must fit the component value type. Empty numeric answers stay empty. |
| choices | list | Choice records, in display order. See below. |
| logic | object | Visibility conditions; see conditions.md. |
| messages | string map | Per-field validation message overrides; see validation.md. |
| prefill | string, empty | Prefill expression, e.g. a supported user/query source; see merge-tags.md. |

Type-specific settings are **direct field properties**: `rows: 5`, `minRows: 1`, `formula: '{quantity} * 10'`. Do not wrap them in `settings`. The registry's `settings` describes defaults, not an extra layer in the saved field.

Optional bounds include min/max/step, minlength/maxlength, minDate/maxDate, minTime/maxTime, minChoices/maxChoices, pattern. The builder may persist numeric bounds as strings. Optional flags include unique, confirm, other, inline, multiple, searchable and counter; use only those supported for the chosen component. A supplied property that normalization would discard is a validation error, rather than a silent successful edit.

## Choice records

Each choice uses `value` (stable string) and `label` (display text), with optional `price` (number), `image` (existing attachment ID) and `selected` (boolean). Preserve choice values while relabeling: stored submissions and conditions still refer to those values. Quote numeric-looking values (`'001'`, `'10'`) when they are identifiers. Include explicit choices for new choice fields; do not rely on seeded generic options.

```yaml
choices:
  - {value: search, label: Search engine}
  - {value: friend, label: Friend or colleague}
  - {value: other, label: Other}
```

## Nested and multi-step forms

Repeaters put their children in the repeater field’s `fields` array. IDs are unique within that child scope; visibility rules can refer to sibling and enclosing fields. A page_break is a layout item in the top-level ordered list and starts another step. Neither nesting nor pages creates another form post.

## Dependencies and scope

Editor YAML references local theme slugs, attachment IDs, page IDs and integration settings. MIO never exports entries or runs the submission pipeline. New forms are drafts; updating an existing form retains its publication status. Updating a published form therefore changes its live definition. Publishing remains the builder's own action.
