# Theme tokens: button

[Themes and sparse overrides](themes.md)

Place changed values in `schema.settings.themeOverrides`. Token names omit the `--atf-` CSS prefix. Every value must be a string, including lengths. Defaults below describe the base token registry; selected themes can override them. Query list_form_options with kind=tokens and a token name for the live definition.

| Token | Base default | Control | Meaning / choices |
|---|---|---|
| `button-bg` | `"var( --atf-accent )"` | text | Button bg |
| `button-text` | `"var( --atf-accent-text )"` | text | Button text |
| `button-bg-hover` | `"var( --atf-accent )"` | text | Button bg hover |
| `button-border` | `"transparent"` | text | Button border |
| `button-pad-x` | `"20px"` | length | Button pad x |
| `button-pad-y` | `"11px"` | length | Button pad y |
| `button-width` | `"auto"` | select | Button width — auto, full |
| `button-align` | `"start"` | select | Button align — start, center, end |
| `button-transform` | `"none"` | select | Button transform — none, uppercase, lowercase, capitalize |
