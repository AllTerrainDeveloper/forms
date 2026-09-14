# Toggle (`switch`)

[Component index](index.md) · [Shared field properties](../forms.md) · [Conditions](../conditions.md)

## Purpose and value

Boolean answer. Use `true`/`false` as YAML defaults. For conditions the string comparison expects `"1"` for a true switch. Required means affirmative, not merely that the control was rendered.

Type ID: `switch`. Palette group: `choice`. Produces a submitted answer: **yes**. Registered value shape: `bool`. Supplies choices: **no**.

## Inspector capabilities

`label`, `placeholder`, `hint`, `required`, `default`, `width`, `css`, `prefill`, `logic`. Capability names describe inspector controls; YAML property casing is exact (for example `minDate`, `minChoices`, `cssClass`). Type-specific properties sit directly on the field, not inside a `settings` object.

## Type-specific defaults

No additional registered defaults. Shared properties and optional supported constraints are described in forms.md.

## Minimal field

```yaml
id: switch_question
type: switch
label: Toggle
```

## Editing and validation

Keep the existing `id` when changing a label or appearance. Preserve unrelated defaults, choices, conditional rules and messages. Before changing a type, review its answer shape and every condition, calculation and notification that references it. Validate the whole editor YAML and correct the returned paths before applying. The validator checks structure and normalization; a successful dry-run does not submit the form or prove that external services will run.
