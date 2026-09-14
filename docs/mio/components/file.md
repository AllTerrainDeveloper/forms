# File upload (`file`)

[Component index](index.md) · [Shared field properties](../forms.md) · [Conditions](../conditions.md)

## Purpose and value

`filetypes` is a list of extension names without leading dots; `maxsize` is megabytes per file; `maxfiles` is a file-count limit. Uploads use private plugin storage. MIO edits configuration only and never uploads a visitor file.

Type ID: `file`. Palette group: `advanced`. Produces a submitted answer: **yes**. Registered value shape: `files`. Supplies choices: **no**.

## Inspector capabilities

`label`, `placeholder`, `hint`, `required`, `default`, `width`, `css`, `prefill`, `logic`, `filetypes`, `maxsize`, `maxfiles`. Capability names describe inspector controls; YAML property casing is exact (for example `minDate`, `minChoices`, `cssClass`). Type-specific properties sit directly on the field, not inside a `settings` object.

## Type-specific defaults

| Property on the field | Default |
|---|---|
| `filetypes` | `["jpg","jpeg","png","gif","webp","pdf","doc","docx","txt","csv","zip"]` |
| `maxsize` | `10` |
| `maxfiles` | `1` |

## Minimal field

```yaml
id: file_question
type: file
label: File upload
```

## Editing and validation

Keep the existing `id` when changing a label or appearance. Preserve unrelated defaults, choices, conditional rules and messages. Before changing a type, review its answer shape and every condition, calculation and notification that references it. Validate the whole editor YAML and correct the returned paths before applying. The validator checks structure and normalization; a successful dry-run does not submit the form or prove that external services will run.
