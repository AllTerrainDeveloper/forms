# Merge tags, prefilling and calculations

[Knowledge index](index.md) · [Notifications](notifications.md) · [Total component](components/total.md)

## Merge tags

Notification templates, confirmation content and action templates may reference dynamic values. Keep these strings quoted in YAML so braces remain string contents.

| Tag | Meaning |
|---|---|
| `{field:email}` | Form answer for the field ID email |
| `{all_fields}` | All accepted answers formatted for the destination |
| `{form:id}` / `{form:title}` | Form identity |
| `{entry:id}` | Stored entry ID when entry storage is enabled |
| `{admin_email}` / `{site:admin_email}` | Site administrator email |
| `{site:url}` / `{site:name}` | Site URL/name |
| `{user:email}` | Current authenticated user email |
| `{date:Y-m-d}` / `{time:H:i}` | Current date/time formatting |
| `{ip}` / `{referrer}` | Available submission context |
| `{resume_link}` | Resume URL when present |

Field IDs are stable references; labels are display text. Compound field tags can select supported subkeys, and extension tags may exist. Use the builder's merge-tag picker for exact available tokens. Unknown tag syntax is not an instruction. The resolver preserves unrecognized tags; definition validation does not establish that every tag will resolve to a nonempty value on every submission.

## Prefill

The field’s `prefill` string selects a supported query/user source. The builder lists current sources and keys. A hidden campaign value is useful for reporting but remains client-controlled. Do not use query prefilling to grant privileges or establish a trusted price. Preserve existing prefill expressions when editing labels or theme tokens.

## Calculation formulas

Total fields use a numeric expression such as `'{quantity} * 12 + {shipping}'`. This grammar is different from `{field:quantity}` merge tags. Supported functions: min, max, sum, avg, round, ceil, floor, abs, sqrt and pow. Use numeric field IDs inside braces. Choice pricing can contribute numeric values through the calculation resolver. There is no JavaScript, PHP or eval in formulas.

```yaml
- id: quantity
  type: number
  label: Quantity
  min: '1'
  step: '1'
- id: total
  type: total
  label: Total
  formula: '{quantity} * 12'
  decimals: 2
  currency: EUR
  display: output
```

The server recomputes submitted totals and does not trust the browser's computed value. Formula evaluation can fail at runtime (for example an invalid operation); a well-shaped YAML string is not a proof of numeric correctness. Preview representative answers and boundary cases. Do not change formulas during unrelated form edits.
