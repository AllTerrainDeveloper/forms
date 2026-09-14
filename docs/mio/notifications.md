# Notifications

[Knowledge index](index.md) · [Conditions](conditions.md) · [Merge tags](merge-tags.md)

Every notification belongs in `schema.notifications`, an ordered list. Each has its own enabled flag and condition. **An empty list invokes the built-in administrator notification on submission; it does not disable mail.** To disable mail intentionally, keep a configured notification with enabled:false. All enabled matching notifications run. `logic.action` is not used to invert notification matching; use the desired operators and match mode.

Recipients may be comma-separated addresses or merge tags. Use `{admin_email}` for the site administrator or `{field:email_id}` for the visitor. Use a site-domain From address and visitor Reply-To. Empty message falls back to `{all_fields}`. Allowed HTML is supported. Attachment delivery and actual mail transport are evaluated on submission, never during YAML validation.

## Properties

| Key | Shape |
|---|---|
| `id` | string |
| `name` | string |
| `to` | string |
| `cc` | string |
| `bcc` | string |
| `replyTo` | string |
| `fromName` | string |
| `fromEmail` | string |
| `subject` | string |
| `message` | string |
| `enabled` | boolean |
| `attachFiles` | boolean |
| `logic` | logic |

## Example notification

```yaml
notifications:
  - id: admin_notice
    enabled: true
    name: Enquiry notification
    to: '{admin_email}'
    subject: 'New enquiry: {form:title}'
    message: '{all_fields}'
    attachFiles: false
```

Optional cc, bcc, replyTo, fromName and fromEmail default to empty strings. Use a stable unique id. Do not add a visitor notification unless requested. Form creation and editing do not send any of these messages.
