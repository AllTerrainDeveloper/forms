# Theme tokens: shape

[Themes and sparse overrides](themes.md)

Place changed values in `schema.settings.themeOverrides`. Token names omit the `--atf-` CSS prefix. Every value must be a string, including lengths. Defaults below describe the base token registry; selected themes can override them. Query list_form_options with kind=tokens and a token name for the live definition.

| Token | Base default | Control | Meaning / choices |
|---|---|---|
| `border` | `"#8c8f94"` | color | Border |
| `border-focus` | `"#2271b1"` | color | Border focus |
| `radius-field` | `"4px"` | length | Radius field |
| `radius-button` | `"4px"` | length | Radius button |
| `radius-card` | `"8px"` | length | Radius card |
| `radius-check` | `"3px"` | length | Radius check |
| `border-width` | `"1px"` | length | Border width |
| `border-style` | `"solid"` | select | Border style — solid, dashed, dotted, double, none |
| `card-gradient` | `"none"` | text | Card gradient |
| `card-border` | `"none"` | text | Card border |
