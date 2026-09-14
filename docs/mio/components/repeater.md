# Repeater (`repeater`)

[Component index](index.md) · [Shared field properties](../forms.md) · [Conditions](../conditions.md)

## Purpose and value

Nested questions are in this field’s `fields` array, never a separate schema. Child IDs must be unique within their row scope. `minRows`/`maxRows` bound the row count; `addLabel` and `itemLabel` customize row controls. Conditions may reference siblings or enclosing fields.

Type ID: `repeater`. Palette group: `advanced`. Produces a submitted answer: **yes**. Registered value shape: `array`. Supplies choices: **no**.

## Inspector capabilities

`label`, `hint`, `required`, `width`, `css`, `logic`, `minrows`, `maxrows`, `addlabel`, `itemlabel`. Capability names describe inspector controls; YAML property casing is exact (for example `minDate`, `minChoices`, `cssClass`). Type-specific properties sit directly on the field, not inside a `settings` object.

## Type-specific defaults

| Property on the field | Default |
|---|---|
| `fields` | `[]` |
| `minRows` | `1` |
| `maxRows` | `10` |
| `addLabel` | `""` |
| `itemLabel` | `""` |

## Minimal field

```yaml
id: repeater_question
type: repeater
label: Repeater
fields:
  - {id: item_name, type: text, label: Item name}
```

## Editing and validation

Keep the existing `id` when changing a label or appearance. Preserve unrelated defaults, choices, conditional rules and messages. Before changing a type, review its answer shape and every condition, calculation and notification that references it. Validate the whole editor YAML and correct the returned paths before applying. The validator checks structure and normalization; a successful dry-run does not submit the form or prove that external services will run.
