# Success Screen

[Knowledge index](index.md) · [Conditions](conditions.md) · [Merge tags](merge-tags.md)

A confirmation’s `success` object controls the message screen appearance. It does not send notifications or define a redirect. Omitted properties get defaults: style=simple, title/icon/accent/buttonLabel empty, intensity=medium, showButton=false. The allowed style names come from the plugin’s success-style registry; unknown styles are rejected if normalization would replace them. Use the builder to inspect supported styles when unsure. Accent accepts a hex colour and icon is a short glyph string, not markup.

## Properties

| Key | Shape |
|---|---|
| `style` | string |
| `title` | string |
| `icon` | string |
| `accent` | string |
| `buttonLabel` | string |
| `intensity` | string (low, medium, high) |
| `showButton` | boolean |
