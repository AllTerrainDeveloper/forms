# Validation and correcting MIO edits

[Knowledge index](index.md) · [Workflow](workflow.md) · [Field properties](forms.md)

## Three attempts before any write

1. begin_form_edit returns an editId and the current complete YAML.
2. validate_form_yaml parses the edited YAML, checks its structure and asks the server to validate it. This does not save.
3. If invalid, read every returned error path, message and suggestion. Correct the document and retry with the **same editId**. Up to two correction attempts are available (three validation attempts total). With the recovery API, opening a new edit cannot reset the user-turn budget, and malformed outer tool arguments consume that same budget.
4. Success returns a receipt. apply_form_edit accepts that receipt and saves exactly the validated definition. It does not accept replacement YAML.
5. If the third attempt fails, explain the remaining errors and stop. Do not apply or start another edit to evade the limit.

## Error contract

```json
{
  "ok": false,
  "saved": false,
  "stage": "validation",
  "effect": "none",
  "status": "rejected",
  "attempt": 1,
  "maxAttempts": 3,
  "retryable": true,
  "retriesRemaining": 2,
  "errors": [{
    "code": "type",
    "path": "/schema/fields/0/required",
    "message": "must be boolean",
    "suggestion": "Use boolean; booleans are true/false without quotes."
  }]
}
```

Syntax failures include line and column when available. Structure failures use JSON Pointer paths such as `/schema/fields/0/required` (zero-based array indices). Server semantic errors use dotted paths starting with `form`, sometimes identifying a field by its stable ID, e.g. `form.schema.fields.details`. These are locations within the editor document, not filesystem paths. Client structure checks report up to 20 issues; server semantic checks report the first rejected value per request.

## What the dry-run checks

The parser accepts one YAML 1.2 document containing JSON-compatible data. Duplicate keys, custom tags, aliases/anchors, multiple documents, excessive nesting and unsupported numeric values are rejected. With the recovery API the assistant accepts up to 40,000 UTF-8 bytes and keeps one complete candidate in history, preserving error feedback and save receipts. The original API retains its 16,000-byte limit because it repeats tool arguments. The shell checks the actual serialized request budget; sufficiently large help/conversation context can still exceed it. Full file packages support the larger file limit. Never delete fields to fit this limit: use file import/export for large forms.

The shared JSON Schema checks required properties, types, enums, counters and object/list shapes. Server validation uses installed field types and themes, checks IDs and logic references, verifies image/page references, validates token values, and rejects supplied values that normalization would remove or change. Missing optional keys may receive defaults. Third-party dependencies can add checks using the assistant validation filter.

A valid definition does not prove external mail/webhooks will deliver, every conditional path is reachable, or a payment/account provider is configured. Submission-specific validators still run only when a visitor submits answers. Review integration requirements in actions.md.

## Common repairs

| Error | Repair |
|---|---|
| required must be boolean | Use `required: true`, not `'true'` or `yes` |
| Duplicate mapping key | Keep one key and merge its intended contents |
| Unknown field type | Query live field options; use the exact type ID |
| Missing logic field | Reference the controlling ID in the correct scope |
| Value changed by sanitization | Correct unsupported markup, case, type or token value at the reported path |
| Unknown theme/token | Query live options; keep only supported override keys |
| Expected object | Use `{}` for empty maps, `[]` for empty lists |
| Revision conflict | The stored form changed. Read the current form; do not replay the old receipt |
| Unknown save outcome | Use the read-only operation status on compatible MIO, or reload and inspect. The server might have saved despite the lost response |

## Visitor answer validation

`required`, numeric/date/length bounds, choice membership and file restrictions govern submitted answers. Fields can also have `pattern`, a named `validation` preset or a custom `validationRecipe`. The recipe is serialized JSON text used by the rule editor to recreate its pattern; preserve it when changing unrelated fields. Use the visual rule editor for an unfamiliar recipe rather than inventing its internals. Per-field `messages` customize validation errors; the schema normalizer allowlists message keys. An unsupported key is rejected by strict definition validation.
