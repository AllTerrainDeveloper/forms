# Address (`address`)

[Component index](index.md) · [Shared field properties](../forms.md) · [Conditions](../conditions.md)

## Purpose and value

Compound address object. `parts` chooses line1, line2, city, region, postcode, country. Preserve existing part keys when editing labels so stored values and downstream integrations keep their meaning.

Type ID: `address`. Palette group: `advanced`. Produces a submitted answer: **yes**. Registered value shape: `object`. Supplies choices: **no**.

## Inspector capabilities

`label`, `placeholder`, `hint`, `required`, `default`, `width`, `css`, `prefill`, `logic`, `parts`. Capability names describe inspector controls; YAML property casing is exact (for example `minDate`, `minChoices`, `cssClass`). Type-specific properties sit directly on the field, not inside a `settings` object.

## Type-specific defaults

| Property on the field | Default |
|---|---|
| `parts` | `["line1","line2","city","region","postcode","country"]` |

Allowed compound parts: `line1` (Address), `line2` (Address line 2), `city` (Town or city), `region` (County or state), `postcode` (Postcode), `country` (Country).

## Minimal field

```yaml
id: address_question
type: address
label: Address
```

## Editing and validation

Keep the existing `id` when changing a label or appearance. Preserve unrelated defaults, choices, conditional rules and messages. Before changing a type, review its answer shape and every condition, calculation and notification that references it. Validate the whole editor YAML and correct the returned paths before applying. The validator checks structure and normalization; a successful dry-run does not submit the form or prove that external services will run.
