# Recipe: name, surname and conditional Other textarea

[Knowledge index](../index.md) · [Conditions](../conditions.md) · [Workflow](../workflow.md)

User request: “Create a form with name, surname, how you heard about us, and a textarea if they choose Other.”

Use begin_form_edit with mode:create. The first two questions are independent text fields. The dropdown stores search/friend/other. The textarea owns a show rule referring to heard and becomes required only while visible. Validate the complete YAML below and apply the successful receipt. Creating this definition does not publish it.

```yaml
title: How did you hear about us?
schema:
  version: 1
  fields:
    - id: name
      type: text
      label: Name
      required: true
      width: half
    - id: surname
      type: text
      label: Surname
      required: true
      width: half
    - id: heard
      type: select
      label: How did you hear about us?
      required: true
      placeholder: Choose an option
      choices:
        - {value: search, label: Search engine}
        - {value: friend, label: Friend or colleague}
        - {value: other, label: Other}
    - id: details
      type: textarea
      label: Please tell us how you heard about us
      required: true
      rows: 4
      logic:
        enabled: true
        action: show
        match: all
        rules:
          - {field: heard, operator: is, value: other}
  settings:
    theme: clean
    themeOverrides: {}
  notifications: []
  confirmations: []
  actions: []
```

On an update, merge these requested questions into the returned complete definition and preserve unrelated settings and notifications. Reuse existing IDs where the same question already exists. Do not create duplicate IDs or remove fields just to match the example.

Verify Other shows the textarea and requires an answer; Search engine and Friend hide it and do not require an answer. The dropdown itself is required. Empty notifications invokes the default administrator email on a future submission; creating the form sends no email.
