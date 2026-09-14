# Consent (`consent`)

[Component index](index.md) · [Shared field properties](../forms.md) · [Conditions](../conditions.md)

## Purpose and value

An affirmative checkbox with `consentText`, which can include a policy link. Required means the visitor must agree. Preserve the exact consent text unless the user requested changes.

Type ID: `consent`. Palette group: `special`. Produces a submitted answer: **yes**. Registered value shape: `bool`. Supplies choices: **no**.

## Inspector capabilities

`label`, `hint`, `required`, `css`, `logic`, `consenttext`. Capability names describe inspector controls; YAML property casing is exact (for example `minDate`, `minChoices`, `cssClass`). Type-specific properties sit directly on the field, not inside a `settings` object.

## Type-specific defaults

| Property on the field | Default |
|---|---|
| `consentText` | `""` |

## Minimal field

```yaml
id: consent_question
type: consent
label: Consent
```

## Editing and validation

Keep the existing `id` when changing a label or appearance. Preserve unrelated defaults, choices, conditional rules and messages. Before changing a type, review its answer shape and every condition, calculation and notification that references it. Validate the whole editor YAML and correct the returned paths before applying. The validator checks structure and normalization; a successful dry-run does not submit the form or prove that external services will run.
