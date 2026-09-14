# Spam, storage and analytics

[Knowledge index](index.md) · [Form structure](forms.md)

All paths below are relative to `schema.settings`. These are built-in defaults from includes/schema.php; a site extension may filter them. On an update preserve the settings returned by begin_form_edit. On creation omitted optional values receive the site defaults.

| Path | Default | Behavior |
|---|---|---|
| `spam.honeypot` | `true` | Hidden trap for bots; keep enabled unless requested otherwise. |
| `spam.timeTrap` | `3` | Minimum elapsed seconds from the signed form render time. Zero disables. |
| `spam.rateLimit` | `10` | Submission rate threshold; zero disables. Server uses client IP based rate counting. |
| `spam.blocklist` | `""` | Newline-separated blocked terms. Use a YAML literal block to preserve separate lines. |
| `spam.akismet` | `false` | Off by default. When enabled and configured, sends submission data to Akismet. |
| `spam.challenge` | `false` | Interactive anti-spam challenge switch. |
| `storage.entries` | `true` | Store accepted entries. Turning off changes the availability of entry-based reporting and limits. |
| `storage.ip` | `true` | Record client IP on stored entries. |
| `storage.userAgent` | `true` | Record browser user agent. |
| `storage.retention` | `0` | Retention in days. Zero means retain indefinitely; positive values allow scheduled deletion of old entries. |
| `storage.anonymise` | `false` | Anonymise the stored IP rather than retaining a precise address. |
| `analytics.enabled` | `true` | Aggregate form views/submissions for conversion reporting. |
| `analytics.tech` | `true` | Aggregate device/browser/OS counts. Separate from conversion counters; not per-visitor histories. |

Use actual YAML booleans, numeric values for counters, lists for roles, and objects for grouped settings. The dry-run rejects supplied values that normalization would discard or change. Preserve all unrelated groups; replacing the entire settings map with only a changed key would reset other behavior. Availability is enforced on submission, not just hidden in the browser. MIO changes configuration only: validation never sends mail, creates entries or runs actions.

See also [themes](themes.md), [notifications](notifications.md), [conditions](conditions.md), [validation](validation.md).
