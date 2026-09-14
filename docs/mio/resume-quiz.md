# Resume and quiz settings

[Knowledge index](index.md) · [Form structure](forms.md)

All paths below are relative to `schema.settings`. These are built-in defaults from includes/schema.php; a site extension may filter them. On an update preserve the settings returned by begin_form_edit. On creation omitted optional values receive the site defaults.

| Path | Default | Behavior |
|---|---|---|
| `resume.enabled` | `false` | Allow saving and resuming incomplete submissions. |
| `resume.days` | `30` | Days a resume link remains valid; minimum 1. |
| `quiz.enabled` | `false` | Compute quiz scoring from quiz questions and their correct choices. |
| `quiz.passMark` | `0` | Pass threshold percentage; use 0–100 for a meaningful percentage. |
| `quiz.showScore` | `true` | Show the computed score to the visitor. |

Use actual YAML booleans, numeric values for counters, lists for roles, and objects for grouped settings. The dry-run rejects supplied values that normalization would discard or change. Preserve all unrelated groups; replacing the entire settings map with only a changed key would reset other behavior. Availability is enforced on submission, not just hidden in the browser. MIO changes configuration only: validation never sends mail, creates entries or runs actions.

See also [themes](themes.md), [notifications](notifications.md), [conditions](conditions.md), [validation](validation.md).
