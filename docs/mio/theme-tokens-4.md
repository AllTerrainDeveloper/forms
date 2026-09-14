# Theme tokens: fields

[Themes and sparse overrides](themes.md)

Place changed values in `schema.settings.themeOverrides`. Token names omit the `--atf-` CSS prefix. Every value must be a string, including lengths. Defaults below describe the base token registry; selected themes can override them. Query list_form_options with kind=tokens and a token name for the live definition.

| Token | Base default | Control | Meaning / choices |
|---|---|---|
| `field-style` | `"outline"` | select | Field style — outline, filled, underline, none |
| `field-height` | `"auto"` | text | Field height |
| `field-lift` | `"none"` | text | Field lift |
| `field-gradient` | `"none"` | text | Field gradient |
