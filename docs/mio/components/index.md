# Form components

[Knowledge index](../index.md) · [Shared field properties](../forms.md)

The 37 built-in types below are registered by includes/field-types.php. Extensions can add types. Use list_form_options with kind=fields and name="" for the current site, then the exact type ID for its definition. Never infer a type ID from a display label.

- [File upload — file](file.md): `filetypes` is a list of extension names without leading dots; `maxsize` is megabytes per file; `maxfiles` is a file-count limit.
- [Signature — signature](signature.md): Captures a drawn signature as an image.
- [Star rating — rating](rating.md): Integer star rating up to `max` (default 5).
- [Opinion scale — scale](scale.md): Numeric opinion/NPS scale.
- [Likert matrix — likert](likert.md): Each statement is a row and each column is a shared choice.
- [Slider — range](range.md): Slider with `min`, `max` and `step`.
- [Colour — color](color.md): A colour answer entered by the visitor.
- [Name — name](name.md): Compound name object.
- [Address — address](address.md): Compound address object.
- [Country — country](country.md): Uses the plugin country registry and stores the country code.
- [Repeater — repeater](repeater.md): Nested questions are in this field’s `fields` array, never a separate schema.
- [Dropdown — select](select.md): A single selected choice value is stored.
- [Multi-select — multiselect](multiselect.md): Stores a list of selected values.
- [Radio buttons — radio](radio.md): Stores one choice value.
- [Checkboxes — checkboxes](checkboxes.md): Stores a list, even for one checked option.
- [Image choice — image_choice](image_choice.md): Choices use existing WordPress image attachment IDs in `image`; never invent IDs or embed base64 in an editor draft.
- [Toggle — switch](switch.md): Boolean answer.
- [Date — date](date.md): Date answer uses `YYYY-MM-DD`.
- [Time — time](time.md): Time answer uses a local time string.
- [Date & time — datetime](datetime.md): Local date and time input.
- [Date range — date_range](date_range.md): An object with `from` and `to` date strings, not two unrelated top-level fields.
- [Section heading — heading](heading.md): Layout only, not an answer.
- [HTML block — html](html.md): Layout markup in `content`.
- [Divider — divider](divider.md): Layout separator with no submitted value.
- [Spacer — spacer](spacer.md): Layout spacing; `height` defaults to 24 pixels.
- [Page break — page_break](page_break.md): Divides the ordered field list into steps.
- [Consent — consent](consent.md): An affirmative checkbox with `consentText`, which can include a policy link.
- [Total — total](total.md): Read-only calculated number.
- [Quiz question — quiz](quiz.md): Choice question with `correct` equal to an actual choice value and numeric `points`.
- [Single line — text](text.md): Use for a short answer, including separate name and surname fields.
- [Paragraph — textarea](textarea.md): Use for paragraphs and the conditional Other explanation.
- [Email — email](email.md): The submitted answer must be an email address.
- [Website — url](url.md): Use for a website address.
- [Phone — tel](tel.md): Store telephone numbers as text to retain leading zeroes and international prefixes.
- [Number — number](number.md): Optional unanswered numbers remain empty, not zero.
- [Password — password](password.md): Used for registration.
- [Hidden — hidden](hidden.md): Carries a default or prefilled value without a visible control.
