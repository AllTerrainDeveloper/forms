# Form settings and availability

[Knowledge index](index.md) · [Form structure](forms.md)

All paths below are relative to `schema.settings`. These are built-in defaults from includes/schema.php; a site extension may filter them. On an update preserve the settings returned by begin_form_edit. On creation omitted optional values receive the site defaults.

| Path | Default | Behavior |
|---|---|---|
| `theme` | `"clean"` | Installed theme slug. Query live themes; use overrides for changes local to this form. |
| `themeOverrides` | `[]` | Only changed CSS tokens. Empty map {} inherits the selected theme. See themes.md. |
| `submitLabel` | `"Send"` | Visible submit button text. |
| `labelPosition` | `""` | Empty inherits theme. Supported layout modes are described in themes.md; do not invent a CSS declaration here. |
| `ajax` | `true` | Submit asynchronously when true. False uses normal form submission. |
| `progressBar` | `"steps"` | For multi-step forms: steps, bar or none. |
| `requireLogin` | `false` | Restricts access to signed-in users. |
| `roles` | `[]` | Allowed WordPress role slugs, used with the login requirement; empty has no additional role restriction. |
| `loginMessage` | `""` | Message when login is required; empty uses the built-in message. |
| `schedule.start` | `""` | Opening date/time in the site timezone; empty means no lower bound. |
| `schedule.end` | `""` | Closing date/time in the site timezone; empty means no upper bound. |
| `schedule.message` | `""` | Message outside the schedule; empty uses the built-in wording. |
| `limit.total` | `0` | Maximum stored submissions for the form. Zero disables the total limit. |
| `limit.perUser` | `0` | Maximum stored submissions per signed-in user. Zero disables this limit; it does not identify anonymous visitors. |
| `limit.message` | `""` | Message when a submission limit is reached. |

Use actual YAML booleans, numeric values for counters, lists for roles, and objects for grouped settings. The dry-run rejects supplied values that normalization would discard or change. Preserve all unrelated groups; replacing the entire settings map with only a changed key would reset other behavior. Availability is enforced on submission, not just hidden in the browser. MIO changes configuration only: validation never sends mail, creates entries or runs actions.

See also [themes](themes.md), [notifications](notifications.md), [conditions](conditions.md), [validation](validation.md).
