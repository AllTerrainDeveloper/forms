# Post-submit actions and integration settings

[Knowledge index](index.md) · [Conditions](conditions.md) · [Merge tags](merge-tags.md)

Actions belong in `schema.actions`. Each object has `id` (unique stable string), `type`, `enabled` (boolean, defaults true), `logic` and a type-specific `settings` map. They run after an accepted submission, independently of notifications. A failed action is recorded on the entry when one is stored; it does not discard the accepted submission. Saving or validating YAML never runs an action.

## create_post

| settings key | Meaning/default |
|---|---|
| postType | Post type slug, default post; runtime allowlist normally post/page |
| status | draft by default; pending/private/publish accepted subject to runtime restrictions |
| title | Merge-tag template for title |
| content | Content template, defaults to `{all_fields}` |
| meta | Map of post-meta key to merge-tag template, subject to runtime protection |

Publishing visitor-created content is separately restricted by site policy. Do not assume setting status:publish bypasses it. This action's status is unrelated to whether the form itself is published.

## register_user

Settings: `email`, `login`, `firstName`, `lastName` are merge-tag templates. `role` is a site-allowed role, defaulting to the site registration role. `passwordField` is the ID of a password field, not a merge-tag password value. `notify` controls the registration notification. Runtime checks constrain roles and handle existing accounts; YAML validation does not reserve a username or create a user.

## update_user_meta

`settings.meta` maps allowed user-meta keys to value templates. Applies to the authenticated submitting user; it is not an arbitrary user-ID update endpoint. Protected keys remain subject to runtime restrictions.

## webhook

`settings.url` is the receiving URL. `settings.secret`, when configured, signs the JSON request body with HMAC-SHA256 in X-ATF-Signature. Runtime uses WordPress safe HTTP behavior and checks the destination. Preserve existing URL/secret during unrelated edits. Do not invent service credentials, add a destination that was not requested, or claim delivery from a definition dry-run.

## mailpoet

Requires the MailPoet integration on this site. `settings.lists` is a list of existing MailPoet list IDs. `email_field`, `first_name_field` and `last_name_field` identify top-level form fields. These values are field IDs, not merge tags. Use the builder's MailPoet controls to select actual lists; the MIO options tool does not enumerate mailing-list IDs. Preserve existing mappings on unrelated edits. Subscription behavior and consent must match the user's request.

## Example: requested draft post creation

```yaml
actions:
  - id: create_submission_post
    type: create_post
    enabled: true
    settings:
      postType: post
      status: draft
      title: '{field:subject}'
      content: '{field:message}'
```

The referenced subject/message fields must exist. This example is a fragment, not a complete editor document. Do not add it to a simple contact form unless the user asked for post creation.

## Extensions and validation limits

Third-party action types run through alltfo_run_action. Their settings are intentionally extensible; the package schema accepts an object and preserves it, not a universal external-service schema. The assistant validation filter lets extensions reject invalid configuration. Structural success is not proof that a custom action is installed or operational. Use the existing builder and integration documentation for unrecognized action types.
