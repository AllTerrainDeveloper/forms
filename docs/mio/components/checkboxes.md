# Checkboxes (`checkboxes`)

[Component index](index.md) · [Shared field properties](../forms.md) · [Conditions](../conditions.md)

## Purpose and value

Stores a list, even for one checked option. Required means a nonempty answer. `is_not` means no selected option equals the forbidden value. `minChoices` and `maxChoices` control count.

Type ID: `checkboxes`. Palette group: `choice`. Produces a submitted answer: **yes**. Registered value shape: `array`. Supplies choices: **yes**.

## Inspector capabilities

`label`, `placeholder`, `hint`, `required`, `default`, `width`, `css`, `prefill`, `logic`, `choices`, `other`, `inline`, `minchoices`, `maxchoices`. Capability names describe inspector controls; YAML property casing is exact (for example `minDate`, `minChoices`, `cssClass`). Type-specific properties sit directly on the field, not inside a `settings` object.

## Type-specific defaults

No additional registered defaults. Shared properties and optional supported constraints are described in forms.md.

## Minimal field

```yaml
id: checkboxes_question
type: checkboxes
label: Checkboxes
choices:
  - {value: option_a, label: Option A}
  - {value: option_b, label: Option B}
```

## Editing and validation

Keep the existing `id` when changing a label or appearance. Preserve unrelated defaults, choices, conditional rules and messages. Before changing a type, review its answer shape and every condition, calculation and notification that references it. Validate the whole editor YAML and correct the returned paths before applying. The validator checks structure and normalization; a successful dry-run does not submit the form or prove that external services will run.
