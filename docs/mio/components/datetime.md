# Date & time (`datetime`)

[Component index](index.md) · [Shared field properties](../forms.md) · [Conditions](../conditions.md)

## Purpose and value

Local date and time input. `minDate` and `maxDate` bound the local datetime; preserve the stored string and do not silently convert its timezone.

Type ID: `datetime`. Palette group: `datetime`. Produces a submitted answer: **yes**. Registered value shape: `string`. Supplies choices: **no**.

## Inspector capabilities

`label`, `placeholder`, `hint`, `required`, `default`, `width`, `css`, `prefill`, `logic`, `mindate`, `maxdate`. Capability names describe inspector controls; YAML property casing is exact (for example `minDate`, `minChoices`, `cssClass`). Type-specific properties sit directly on the field, not inside a `settings` object.

## Type-specific defaults

No additional registered defaults. Shared properties and optional supported constraints are described in forms.md.

## Minimal field

```yaml
id: datetime_question
type: datetime
label: Date & time
```

## Editing and validation

Keep the existing `id` when changing a label or appearance. Preserve unrelated defaults, choices, conditional rules and messages. Before changing a type, review its answer shape and every condition, calculation and notification that references it. Validate the whole editor YAML and correct the returned paths before applying. The validator checks structure and normalization; a successful dry-run does not submit the form or prove that external services will run.
