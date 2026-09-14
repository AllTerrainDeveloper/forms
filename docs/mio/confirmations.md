# Confirmations

[Knowledge index](index.md) · [Conditions](conditions.md) · [Merge tags](merge-tags.md)

Every confirmation belongs in `schema.confirmations`. The first enabled confirmation whose conditions match is selected, so put specific cases before an unconditional fallback. Types: message shows content, redirect navigates to url, page uses an existing WordPress pageId. Do not invent page IDs. An empty list uses the default success message. Conditions use the condition result (the show/hide action does not invert them).

`success` configures a message confirmation’s appearance; see success-screen.md. Redirect query strings can use merge tags. Preserve destination URLs when the user requested only field edits. Validation can verify local page existence; it does not make an HTTP request to prove a redirect destination works.

## Properties

| Key | Shape |
|---|---|
| `id` | string |
| `name` | string |
| `message` | string |
| `url` | string |
| `query` | string |
| `enabled` | boolean |
| `type` | string (message, redirect, page) |
| `pageId` | integer |
| `success` | success |
| `logic` | logic |

## Example confirmation

```yaml
confirmations:
  - id: thanks
    enabled: true
    name: Thank you
    type: message
    message: '<p>Thank you. We have received your enquiry.</p>'
    success:
      style: simple
      title: Thank you
      showButton: false
```
