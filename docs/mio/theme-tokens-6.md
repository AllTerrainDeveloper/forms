# Theme tokens: type

[Themes and sparse overrides](themes.md)

Place changed values in `schema.settings.themeOverrides`. Token names omit the `--atf-` CSS prefix. Every value must be a string, including lengths. Defaults below describe the base token registry; selected themes can override them. Query list_form_options with kind=tokens and a token name for the live definition.

| Token | Base default | Control | Meaning / choices |
|---|---|---|
| `font-family` | `"inherit"` | text | Font family |
| `font-family-heading` | `"inherit"` | text | Font family heading |
| `size-base` | `"16px"` | length | Size base |
| `size-label` | `"14px"` | length | Size label |
| `size-hint` | `"13px"` | length | Size hint |
| `size-heading` | `"20px"` | color | Size heading |
| `size-button` | `"15px"` | length | Size button |
| `weight-label` | `"600"` | select | Weight label — 300, 400, 500, 600, 700, 800 |
| `weight-heading` | `"600"` | color | Weight heading |
| `weight-button` | `"600"` | select | Weight button — 300, 400, 500, 600, 700, 800 |
| `letter-spacing` | `"normal"` | text | Letter spacing |
| `letter-spacing-label` | `"normal"` | text | Letter spacing label |
| `line-height` | `"1.5"` | length | Line height |
| `transform-label` | `"none"` | select | Transform label — none, uppercase, lowercase, capitalize |
