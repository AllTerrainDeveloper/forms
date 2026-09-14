# Hidden (`hidden`)

[Component index](index.md) · [Shared field properties](../forms.md) · [Conditions](../conditions.md)

## Purpose and value

Carries a default or prefilled value without a visible control. Hidden inputs remain visitor-controlled; they are not an authorization or trusted pricing source.

Type ID: `hidden`. Palette group: `text`. Produces a submitted answer: **yes**. Registered value shape: `string`. Supplies choices: **no**.

## Inspector capabilities

`label`, `default`, `prefill`, `css`. Capability names describe inspector controls; YAML property casing is exact (for example `minDate`, `minChoices`, `cssClass`). Type-specific properties sit directly on the field, not inside a `settings` object.

## Type-specific defaults

No additional registered defaults. Shared properties and optional supported constraints are described in forms.md.

## Minimal field

```yaml
id: hidden_question
type: hidden
label: Hidden
```

## Editing and validation

Keep the existing `id` when changing a label or appearance. Preserve unrelated defaults, choices, conditional rules and messages. Before changing a type, review its answer shape and every condition, calculation and notification that references it. Validate the whole editor YAML and correct the returned paths before applying. The validator checks structure and normalization; a successful dry-run does not submit the form or prove that external services will run.
