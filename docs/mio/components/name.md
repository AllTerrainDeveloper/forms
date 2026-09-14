# Name (`name`)

[Component index](index.md) · [Shared field properties](../forms.md) · [Conditions](../conditions.md)

## Purpose and value

Compound name object. `parts` chooses among prefix, first, middle, last, suffix. For independently positioned name and surname use two text fields instead. Compound values are addressed through merge-tag subkeys.

Type ID: `name`. Palette group: `advanced`. Produces a submitted answer: **yes**. Registered value shape: `object`. Supplies choices: **no**.

## Inspector capabilities

`label`, `placeholder`, `hint`, `required`, `default`, `width`, `css`, `prefill`, `logic`, `parts`. Capability names describe inspector controls; YAML property casing is exact (for example `minDate`, `minChoices`, `cssClass`). Type-specific properties sit directly on the field, not inside a `settings` object.

## Type-specific defaults

| Property on the field | Default |
|---|---|
| `parts` | `["first","last"]` |

Allowed compound parts: `prefix` (Title), `first` (First name), `middle` (Middle name), `last` (Last name), `suffix` (Suffix).

## Minimal field

```yaml
id: name_question
type: name
label: Name
```

## Editing and validation

Keep the existing `id` when changing a label or appearance. Preserve unrelated defaults, choices, conditional rules and messages. Before changing a type, review its answer shape and every condition, calculation and notification that references it. Validate the whole editor YAML and correct the returned paths before applying. The validator checks structure and normalization; a successful dry-run does not submit the form or prove that external services will run.
