# Themes and advanced CSS settings

[Knowledge index](index.md) · [Form settings](settings.md)

## Base theme and changed tokens

The Theme Studio tabs edit registered CSS custom-property values, including the advanced controls. They are not arbitrary stylesheet text. A form selects an installed theme through `schema.settings.theme` and stores only its local changes under `schema.settings.themeOverrides`. Empty `{}` means inherit. Remove an override to return to the selected theme; do not write every default.

```yaml
settings:
  theme: clean
  themeOverrides:
    accent: '#2457c5'
    radius-field: '9px'
```

Call list_form_options with kind=themes and name="" to list available theme slugs. Read the named theme to inspect `resolved` effective tokens. Call kind=tokens for supported token names and control types. Do not assume a third-party theme exists on another site.

## CSS value rules

Values are strings. Quote hex colours (`#` starts a YAML comment), lengths and complex font-family values. Keys omit `--atf-`; `accent` is correct, `--atf-accent` is not. Raw selectors, style tags, scripts, declaration separators and arbitrary URLs do not belong in token values. The server uses the same token sanitizer as Theme Studio and rejects a draft if a supplied token would be dropped or changed.

Updating MIO YAML changes this form’s overrides and selected theme. It does not modify a shared theme and affect other forms. It preserves the current theme ID on an update unless a different theme was requested. To create or edit shared themes use Theme Studio.

## Portable packages versus editor YAML

The export file contains the full form plus a separate theme definition. The theme stores a built-in base and a sparse token delta; the form can add its own sparse overrides. Import reconstructs a separate theme rather than overwriting the destination’s shared theme. The editor YAML contains only title/schema and uses local installed themes and attachment IDs. Do not paste a portable package into validate_form_yaml; use the builder’s Import for cross-site packages.

## Advanced token reference

- [colour](theme-tokens-1.md)
- [shape](theme-tokens-2.md)
- [shadow](theme-tokens-3.md)
- [fields](theme-tokens-4.md)
- [space](theme-tokens-5.md)
- [type](theme-tokens-6.md)
- [labels](theme-tokens-7.md)
- [button](theme-tokens-8.md)
- [focus](theme-tokens-9.md)
- [motion](theme-tokens-10.md)
