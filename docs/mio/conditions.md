# Conditions: visibility, notifications, confirmations and actions

[Knowledge index](index.md) · [Conditional contact recipe](recipes/conditional-contact.md)

## One logic shape

```yaml
logic:
  enabled: true
  action: show
  match: all
  rules:
    - field: heard
      operator: is
      value: other
```

`enabled` is a YAML boolean. Disabled logic is unconditional. `action` is show or hide and applies to field visibility. `match` is all (AND) or any (OR). `rules` is a flat list of field/operator/value records; arbitrary nested boolean groups are not supported. Use multiple simple rules or redesign the questions if nested logic is necessary.

`field` is the controlling field's exact ID. `value` is the stored answer/choice value, never its label. In the example the textarea owns this logic; `heard` is the select/radio field it depends on. Keep these as separate fields. Renaming a question does not require changing its ID.

## Operators

| Operator | Meaning |
|---|---|
| is | Exact text equality; selected membership for a list |
| is_not | Inequality; none of the selected values equals the expected value |
| contains | Text substring, or matching member behavior for list answers |
| not_contains | No substring/matching member |
| starts_with | Text begins with expected value |
| ends_with | Text ends with expected value |
| greater / less | Numeric comparison, not alphabetic ordering |
| greater_equal / less_equal | Inclusive numeric comparison |
| empty | Unanswered; rule value may be empty |
| not_empty | Answer exists; rule value may be empty |

Rules store expected values as strings. For a boolean switch use `'1'` for true. Empty/not_empty ignore the expected text. A choice with label “Other (please specify)” can still have value `other`; use `other` in the rule.

## Required and hidden fields

Mark the conditional textarea `required: true`. The browser updates visibility as answers change; the server independently recomputes visibility before validating a submission. A hidden field does not block submission because it is required. Test both branches: Other shows and requires it; another choice hides it and permits leaving it empty.

Do not create self-dependencies or visibility cycles. The definition validator detects missing references, but does not prove every possible combination of conditions is reachable. A successful definition dry-run should be followed by previewing meaningful paths.

## Match and action examples

To show only when heard=other AND consent is true, use match:all with two rules. To show when either of two answers qualifies, use match:any. For a field that should hide under a matching condition, use action:hide. An enabled hide block with no rules would hide unconditionally; do not use an empty rule list as a placeholder for an intended condition.

## Submission behavior

Notifications and actions run when their enabled condition matches. Confirmations select the first enabled matching item. These consumers evaluate the condition result; field visibility's action:hide inversion does not apply. Express “send when X is not Y” with operator:is_not, not action:hide. Put an unconditional confirmation last.

## Scope and repair

Top-level rules reference top-level fields. Repeater child rules can reference sibling and enclosing scope. Notifications, actions and confirmations use top-level references. A “missing logic field” error means the reference is absent in that scope: correct the ID or add the intended controller. Do not remove the condition merely to get validation to pass.
