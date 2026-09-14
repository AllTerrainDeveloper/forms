# Portable forms: YAML and JSON

The builder's **Export** downloads a `.yaml` file containing the current editor state, including unsaved edits. **Import** accepts `.yaml`, `.yml` or JSON containing the same versioned model. It validates the document and creates a **new draft**, a separate saved theme and new image attachments. Existing forms and themes are never overwritten. **Validate YAML** checks a file without creating anything.

The format is YAML 1.2: mappings, sequences, strings, numbers, booleans and null. JSON is also accepted. Comments and multiline HTML are supported. Anchors, aliases, explicit tags, merge keys, multiple documents, duplicate keys, non-finite numbers and unsafe integers are rejected. The file limit is 16 MiB; maximum nesting is 32 levels. Parser errors include line/column information; schema and server errors identify the offending property.

## Model

[JSON Schema](../schemas/form-package-v1.schema.json) is the machine-readable contract. [contact-form.yaml](examples/contact-form.yaml) is a complete, importable starting point. The package version (`formatVersion: 1`) is separate from the form schema version (`form.schema.version: 1`) and the source plugin version. Unknown format/schema versions fail before any writes.

```yaml
format: allterrain-forms
formatVersion: 1
form:
  title: Contact us
  schema:
    version: 1
    fields: []
    settings:
      theme: contact-brand
      themeOverrides:
        radius-field: 8px
    notifications: []
    confirmations: []
    actions: []
theme:
  slug: contact-brand
  base: clean
  label: Contact brand
  dark: false
  tokens:
    accent: '#2255cc'
assets: []
pages: []
```

`theme.base` identifies a built-in theme. `theme.tokens` contains **only differences from that base**. `form.schema.settings.themeOverrides` contains **only form-specific differences from the theme**. An unmodified built-in exports an empty token map. A custom/registered theme is expressed relative to the closest built-in (fewest differing tokens), since older saved themes do not record their ancestry. Dark-surface metadata is preserved separately. Every advanced Theme Studio control participates, including typography, gradients, shadows, structural controls and spacing.

Import reconstructs `defaults → built-in base → theme tokens → form overrides`. It assigns a fresh theme slug to avoid conflicts and rewrites the form's theme reference. A different source plugin version produces a warning because built-in defaults may differ. The plugin's renderer and base stylesheet must be installed at the destination; the YAML does not copy executable plugin code or stylesheet files.

The schema includes fields (including repeater children), choices, defaults, validation, logic, calculations, all form settings, notifications, confirmations, success-screen effects and post-submit actions. Field IDs stay stable. Missing optional properties are filled by the existing normalizer; supplied properties that would be dropped or sanitized are rejected by server validation instead of silently losing configuration. Unsupported field types and theme tokens fail with an actionable error. Registered extension fields/settings can be preserved through the existing normalization hooks; schema fields/settings allow extensions, while the server checks whether the installed extensions actually retain them.

`source`, when present, records `pluginVersion` and `siteUrl` for context. It is not an instruction to contact that site.

## Dependencies and limits

- **Image choices:** every referenced attachment, including choices inside repeaters, is embedded once in `assets` with source `id`, `filename`, `mime`, `title`, `alt`, original `url` and base64 `data`. Import checks actual image bytes against the MIME and allowed extension, uploads locally and remaps all choice IDs. Upload permission is required. A missing/unreadable source file or an oversized package fails export explicitly. No remote download is performed.
- **Page confirmations:** `pages` maps source IDs to URLs. Import resolves a URL to a local page where possible; otherwise the confirmation becomes a redirect to the original URL. It does not interpret the old ID as a destination ID or copy entire WordPress pages.
- **Integrations:** action configuration travels in full, including webhook settings. Integrations, MailPoet lists, site roles, external URLs, site-specific IDs and credentials managed by other plugins need the corresponding destination setup. Import surfaces a warning for action configurations and leaves the form in draft.
- **External styling/content:** theme-token changes are included. WordPress Additional CSS, third-party PHP callbacks, external fonts, media referenced only inside arbitrary HTML/URLs, and theme/plugin code are not copied. Their references remain unchanged.
- **Submissions:** entries, uploaded answers, analytics, revisions and site credentials are outside a form-definition package. Entry export remains a separate feature.

Old unversioned `{ plugin, version, title, schema }` JSON exports do not contain theme or media dependencies. Re-export them from their source installation to obtain a complete portable package; they are not accepted as version-1 packages. The existing `/forms` schema creation API remains available to legacy integrations.

## Validation and LLM workflow

1. Export a form, or start with the example. Give the YAML and JSON Schema to the LLM.
2. Ask it to preserve IDs used by logic, calculations and merge tags, quote numeric-looking strings and hex colors, and edit only the intended fields or overrides. Delete an override to inherit its base value.
3. Run `npm run validate:form -- path/to/form.yaml`, or use **Validate YAML** in the builder.
4. Import the validated document. Preview the new draft and review destination integrations before publishing it.

The offline validator uses the shared JSON Schema and checks field IDs/logic references. It cannot know which custom field types or tokens are installed on a site. The builder additionally calls the server's dry-run validator; import repeats the same checks and image-byte validation. A successful syntax/shape check alone does not prove an external integration will work.

YAML is parsed/stringified with [yaml](https://eemeli.org/yaml/), and the JSON Schema is validated in the browser/CLI with Ajv. Runtime JavaScript is bundled into the builder; no YAML PHP extension, Composer runtime install or LLM service is required.

## REST API

All routes are under `allterrain-forms/v1` and require `alltfo_edit_forms`; cookie authentication uses the existing WordPress REST nonce. Transport is JSON, even when the file on disk is YAML. Parse YAML locally and send its decoded model.

| Route | Request | Response |
|---|---|---|
| `GET /forms/{id}/export` | Saved form ID | Portable package |
| `POST /forms/{id}/export` | Optional `title` and `schema` editor snapshot | Portable package; no save |
| `POST /form-packages/validate` | `{ "package": <decoded document> }` | `{ "valid": true, "warnings": [...] }`, or 400 error with a path |
| `POST /form-packages/import` | `{ "package": <decoded document> }` | 201 normal form response plus `importWarnings` |

WordPress sends `{}` for schema-declared empty maps in exported packages. Import validates the entire document before any writes. Failed dependent writes clean up newly created forms, themes, attachments and their files; existing resources are not part of rollback. Validation/filter hooks are documented in [hooks-reference.md](hooks-reference.md).

PHP entry points: `alltfo_export_form_package( $form_id, $schema = null, $title = null )`, `alltfo_validate_form_package( $package )`, and `alltfo_import_form_package( $package )`. PHP uses arrays internally; `alltfo_package_object_maps( $package )` converts declared maps for standards-compliant JSON output. Validation is read-only; callers exposing it must apply their own permission checks. Export/import functions enforce editing capability themselves.
