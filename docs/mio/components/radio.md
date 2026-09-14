# Radio buttons (`radio`)

[Component index](index.md) · [Shared field properties](../forms.md) · [Conditions](../conditions.md)

## Purpose and value

Stores one choice value. `inline: true` changes layout. The built-in `other` option is a compact extra answer; for a separately required textarea use an explicit Other choice plus a separate conditional textarea.

Type ID: `radio`. Palette group: `choice`. Produces a submitted answer: **yes**. Registered value shape: `string`. Supplies choices: **yes**.

## Inspector capabilities

`label`, `placeholder`, `hint`, `required`, `default`, `width`, `css`, `prefill`, `logic`, `choices`, `other`, `inline`. Capability names describe inspector controls; YAML property casing is exact (for example `minDate`, `minChoices`, `cssClass`). Type-specific properties sit directly on the field, not inside a `settings` object.

## Type-specific defaults

No additional registered defaults. Shared properties and optional supported constraints are described in forms.md.

## Minimal field

```yaml
id: radio_question
type: radio
label: Radio buttons
choices:
  - {value: option_a, label: Option A}
  - {value: option_b, label: Option B}
```

## Editing and validation

Keep the existing `id` when changing a label or appearance. Preserve unrelated defaults, choices, conditional rules and messages. Before changing a type, review its answer shape and every condition, calculation and notification that references it. Validate the whole editor YAML and correct the returned paths before applying. The validator checks structure and normalization; a successful dry-run does not submit the form or prove that external services will run.
