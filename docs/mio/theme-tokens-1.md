# Theme tokens: colour

[Themes and sparse overrides](themes.md)

Place changed values in `schema.settings.themeOverrides`. Token names omit the `--atf-` CSS prefix. Every value must be a string, including lengths. Defaults below describe the base token registry; selected themes can override them. Query list_form_options with kind=tokens and a token name for the live definition.

| Token | Base default | Control | Meaning / choices |
|---|---|---|
| `bg` | `"transparent"` | color | Bg |
| `surface` | `"#ffffff"` | color | Surface |
| `surface-alt` | `"#f6f7f7"` | color | Surface alt |
| `text` | `"#1e1e1e"` | color | Text |
| `text-muted` | `"#646970"` | color | Text muted |
| `heading` | `"#1e1e1e"` | color | Heading |
| `accent` | `"#2271b1"` | color | Accent |
| `accent-text` | `"#ffffff"` | color | Accent text |
| `accent-soft` | `"rgba( 34, 113, 177, 0.1 )"` | color | Accent soft |
| `error` | `"#d63638"` | color | Error |
| `error-soft` | `"rgba( 214, 54, 56, 0.08 )"` | color | Error soft |
| `success` | `"#008a20"` | color | Success |
| `placeholder` | `"#8c8f94"` | color | Placeholder |
| `backdrop-blur` | `"none"` | text | Backdrop blur |
| `progress-height` | `"4px"` | length | Progress height |
