# Image choice (`image_choice`)

[Component index](index.md) · [Shared field properties](../forms.md) · [Conditions](../conditions.md)

## Purpose and value

Choices use existing WordPress image attachment IDs in `image`; never invent IDs or embed base64 in an editor draft. `columns` controls the grid. `multiple: true` permits several answers. Cross-site file export embeds referenced images separately.

Type ID: `image_choice`. Palette group: `choice`. Produces a submitted answer: **yes**. Registered value shape: `string`. Supplies choices: **yes**.

## Inspector capabilities

`label`, `placeholder`, `hint`, `required`, `default`, `width`, `css`, `prefill`, `logic`, `choices`, `multiple`, `columns`. Capability names describe inspector controls; YAML property casing is exact (for example `minDate`, `minChoices`, `cssClass`). Type-specific properties sit directly on the field, not inside a `settings` object.

## Type-specific defaults

| Property on the field | Default |
|---|---|
| `columns` | `3` |

## Minimal field

```yaml
id: image_choice_question
type: image_choice
label: Image choice
choices:
  - {value: option_a, label: Option A}
  - {value: option_b, label: Option B}
```

## Editing and validation

Keep the existing `id` when changing a label or appearance. Preserve unrelated defaults, choices, conditional rules and messages. Before changing a type, review its answer shape and every condition, calculation and notification that references it. Validate the whole editor YAML and correct the returned paths before applying. The validator checks structure and normalization; a successful dry-run does not submit the form or prove that external services will run.
