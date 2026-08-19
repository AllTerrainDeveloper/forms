# Hooks reference

Every action and filter AllTerrain Forms fires. 55 filters, 15 actions.

The rule the plugin follows: **if a function decides something, wrap it in a
filter; if it does something, fire an action.** A behaviour with no hook is a
behaviour a site cannot adapt, and the difference between a plugin somebody can
live with and one they end up forking.

**Status** — *Stable* means the signature will not change inside 0.x without a
note in the changelog. *Experimental* means it may.

---

## Lifecycle

### `atf_loaded` — Action — *Stable*

```php
do_action( 'atf_loaded' );
```

Fires on `plugins_loaded` at 20, once the plugin's registries are open. The place
to call `atf_register_field_type()` and `atf_register_theme()`.

### `atf_register_field_types` — Action — *Stable*

```php
do_action( 'atf_register_field_types' );
```

Fires after the built-in field types are registered, the first time the registry
is read. The last safe moment to add a field type — later than `atf_loaded`, so
it also catches a plugin that loaded after this one.

---

## Field types

### `atf_register_field_type` — Filter — *Stable*

```php
apply_filters( 'atf_register_field_type', array $definition, string $type );
```

A type's definition as it is registered. Lets a site add a setting to a built-in
without unregistering and re-registering it.

```php
add_filter( 'atf_register_field_type', function ( $definition, $type ) {
	if ( 'textarea' === $type ) {
		$definition['settings']['counter'] = true;
	}

	return $definition;
}, 10, 2 );
```

### `atf_field_types` — Filter — *Stable*

```php
apply_filters( 'atf_field_types', array $types );
```

The whole table, just before it is used. The place to remove types wholesale — a
site that never wants file uploads drops the type here and it disappears from the
palette, the validator and the renderer at once.

### `atf_field_groups` — Filter — *Stable*

```php
apply_filters( 'atf_field_groups', array $groups );
```

The palette's groups, as `slug => label`. A plugin adding several related types
can give them a group rather than scattering them through `advanced`.

### `atf_name_parts` / `atf_address_parts` — Filters — *Stable*

```php
apply_filters( 'atf_name_parts', array $parts );
apply_filters( 'atf_address_parts', array $parts );
```

The sub-fields a composite offers, as `key => array( 'label' => …, 'autocomplete' => … )`.

Both are read by the renderer *and* sent to the builder — the `/config` route
returns the resolved list as each type's `parts`, so the tick boxes offering
which parts to ask for are your filtered set rather than a copy of the
defaults. Add a part here and it appears in the builder without further work.

### `atf_countries` — Filter — *Stable*

```php
apply_filters( 'atf_countries', array $countries );
```

ISO 3166-1 alpha-2 code => name. Replace for localised names or a subset.

### `atf_autocomplete_token` — Filter — *Stable*

```php
apply_filters( 'atf_autocomplete_token', string $token, array $field );
```

The `autocomplete` attribute for a field. Filling this in is a WCAG 1.3.5
requirement as well as a usability win.

---

## Schema

### `atf_default_schema` — Filter — *Stable*

```php
apply_filters( 'atf_default_schema', array $schema );
```

What a brand-new form starts from. A site that always wants a particular theme,
or IP storage off, sets it here once rather than on every form.

### `atf_normalize_schema` — Filter — *Stable*

```php
apply_filters( 'atf_normalize_schema', array $schema, mixed $raw );
```

Runs on every read *and* every write, so anything added here is present
everywhere — the renderer, the validator, the builder and the export.

### `atf_normalize_field` — Filter — *Stable*

```php
apply_filters( 'atf_normalize_field', array $field, mixed $raw );
```

### `atf_schema_saved` — Action — *Stable*

```php
do_action( 'atf_schema_saved', int $form_id, array $schema );
```

### `atf_form_created` / `atf_form_deleted` — Actions — *Stable*

```php
do_action( 'atf_form_created', int $form_id, string $template_slug );
do_action( 'atf_form_deleted', int $form_id );
```

### `atf_form_templates` — Filter — *Stable*

```php
apply_filters( 'atf_form_templates', array $templates );
```

`slug => array( 'label', 'description', 'icon', 'schema' )`. A template *is* a
schema — there is no separate format — so a site turns one of its own forms into
a template by exporting its schema and adding it here.

---

## Rendering

### `atf_form_classes` — Filter — *Stable*

```php
apply_filters( 'atf_form_classes', array $classes, int $form_id, array $schema );
```

### `atf_pre_render_field` — Filter — *Stable*

```php
apply_filters( 'atf_pre_render_field', ?string $html, array $field, array $schema, array $values );
```

Returning a string replaces the field's markup entirely. Return `null` to render
normally.

### `atf_rendered_field` / `atf_rendered_form` — Filters — *Stable*

```php
apply_filters( 'atf_rendered_field', string $html, array $field, mixed $value, array $schema );
apply_filters( 'atf_rendered_form',  string $html, int $form_id, array $schema );
```

### `atf_client_schema` — Filter — *Stable*

```php
apply_filters( 'atf_client_schema', array $payload, array $schema );
```

The slice of the schema handed to the front-end bundle, printed as JSON in the
page.

> **Anything added here is readable by every visitor**, including in the page
> source of a cached page. The default payload deliberately excludes notification
> recipients, webhook secrets, the spam blocklist and quiz answers. Treat it as
> public.

### `atf_prefill_values` / `atf_resolve_prefill` — Filters — *Stable*

```php
apply_filters( 'atf_prefill_values', array $values, array $schema );
apply_filters( 'atf_resolve_prefill', string $value, string $source, array $field );
```

`atf_resolve_prefill` resolves a source this plugin does not know. The built-in
sources are a closed set, because the builder offers them as a list rather than
as a text box:

| Source | Fills with |
|---|---|
| `query:<name>` | A parameter from the URL the visitor arrived on, sanitised by the field's own type |
| `user:email` | The signed-in visitor's account email |
| `user:display_name` | Their name (any unknown key falls back to this) |
| `user:first_name` / `user:last_name` / `user:login` / `user:id` | The rest of the account |
| `site` | The site's name |
| `site:url` | The site's address |
| `site:admin_email` | The address in Settings → General |
| `date:today` | Today, as `Y-m-d`. A bare `date:` means the same |
| `date:now` | The time now, as `H:i` |
| `date:<format>` | Any other key is used as a PHP date format |

Unlike a merge tag, an **unresolved source fills nothing** rather than printing
itself. Brace-shaped text is often not a tag; a prefill source is never anything
else, and echoing `myplugin:whatever` into a visitor's name box would be worse
than leaving it empty.

A plugin adding a source should expect people to reach it through **Something
else (advanced)** in the picker, since the list is built from the built-ins.

---

## Availability

### `atf_form_availability` — Filter — *Stable*

```php
apply_filters( 'atf_form_availability', array $open, int $form_id, array $schema );
```

`array( 'open' => bool, 'reason' => string, 'message' => string )`.

Called for **both** the render and the submit, which is the property that makes
it safe to add a condition without also having to guard the handler.

---

## Validation

### `atf_url_schemes` — Filter — *Stable*

```php
apply_filters( 'atf_url_schemes', array $schemes );
```

Which URL schemes a **Website** field accepts. `http` and `https` by default.

A website field checks the *shape* of an address, not whether the site exists —
no DNS lookup, no reachability test, no private-range refusal. Those belong to
`wp_http_validate_url()`, which decides whether the *server* may fetch a URL, and
using it here rejects `https://example.com` on any install without outbound DNS,
refuses the intranet address a staff form legitimately collects, and lets every
visitor ask the server to resolve a hostname of their choosing.

If you add a scheme, add one people can safely follow. `javascript:` and `data:`
both parse as valid URLs, and the value ends up in an `href` in a notification
email and on the entries screen.

### `atf_validation_errors` — Filter — *Stable*

```php
apply_filters( 'atf_validation_errors', array $errors, array $schema, array $values, array $context );
```

Field id => message. Where a site adds a cross-field rule — "the end date must be
after the start date", "at least one contact method" — that no single field can
express on its own.

### `atf_validate_field` — Filter — *Stable*

```php
apply_filters( 'atf_validate_field', string $error, array $field, mixed $value, array $schema );
```

### `atf_validation_message` — Filter — *Stable*

```php
apply_filters( 'atf_validation_message', string $message );
```

The form-level message shown above a form that failed validation.

### `atf_unique_scan_limit` — Filter — *Stable*

```php
apply_filters( 'atf_unique_scan_limit', int $limit, array $field, int $form_id );
```

How many past entries a uniqueness check scans. Raising it makes the check more
thorough and every submission slower.

### `atf_sanitized_values` — Filter — *Stable*

```php
apply_filters( 'atf_sanitized_values', array $values, array $schema, array $raw );
```

---

## Logic and calculations

### `atf_visible_fields` — Filter — *Stable*

```php
apply_filters( 'atf_visible_fields', array $visible, array $schema, array $values );
```

Field id => bool. **This decides what is validated**, so anything hidden here is
also not required.

### `atf_calc_functions` — Filter — *Stable*

```php
apply_filters( 'atf_calc_functions', array $functions );
```

Name => arity, or `-1` for variadic.

> Anything added must be **pure and numeric**. A function with a side effect, or
> one that can reach the filesystem or the database, turns the formula field into
> an execution surface for anybody who can edit a form. The whitelist is the
> security boundary that makes the evaluator safe.

### `atf_calc_apply_function` — Filter — *Stable*

```php
apply_filters( 'atf_calc_apply_function', ?float $result, string $name, array $args );
```

Implements a function added through `atf_calc_functions`.

### `atf_calculation_result` — Filter — *Stable*

```php
apply_filters( 'atf_calculation_result', float $result, string $formula, array $values );
```

---

## Merge tags

### `atf_resolve_merge_tag` — Filter — *Stable*

```php
apply_filters( 'atf_resolve_merge_tag', ?string $value, string $tag, string $argument, array $context );
```

Resolves a tag this plugin does not know. Return `null` to leave it alone — an
unrecognised tag returns *itself* rather than an empty string, so brace-shaped
text that was never a tag survives.

### `atf_merge_tags_replaced` — Filter — *Stable*

```php
apply_filters( 'atf_merge_tags_replaced', string $text, array $context );
```

### `atf_merge_tag_catalogue` — Filter — *Stable*

```php
apply_filters( 'atf_merge_tag_catalogue', array $groups, int $form_id );
```

The list the builder's **Insert a value** picker shows. `atf_resolve_merge_tag`
makes a tag *work*; this makes it *findable*. A plugin that adds one and not the
other has built something nobody will discover.

Each group is `array( 'id', 'label', 'items' )`, optionally with `empty` — a line
shown in place of an empty list. Each item needs all four keys:

```php
add_filter(
	'atf_merge_tag_catalogue',
	function ( $groups, $form_id ) {
		$groups[] = array(
			'id'    => 'crm',
			'label' => __( 'Our CRM', 'my-plugin' ),
			'items' => array(
				array(
					'tag'    => '{crm_ref}',
					// What the value *is*, in the words of somebody writing an
					// email — never the tag restated.
					'label'  => __( 'The CRM reference', 'my-plugin' ),
					'hint'   => __( 'Empty until the record syncs.', 'my-plugin' ),
					// What it resolves to on *this* site. The picker shows this
					// under the label, and it is the reason the catalogue is
					// built on the server rather than in JavaScript.
					'sample' => 'CRM-1234',
				),
			),
		);

		return $groups;
	},
	10,
	2
);
```

A tag advertised here that `atf_resolve_merge_tag` does not resolve will print as
literal braces in somebody's email — an unknown tag returns itself. The plugin's
own suite asserts that every catalogued tag resolves; a plugin adding tags is
worth testing the same way.

---

## Spam

### `atf_spam_verdict` — Filter — *Stable*

```php
apply_filters( 'atf_spam_verdict', array $verdict, array $schema, array $values, array $request );
```

`array( 'spam' => bool, 'reason' => string )`. Returning `spam => true` files the
entry under spam rather than rejecting it, so a wrong answer here is always
recoverable from the Entries window.

The five checks are the honeypot, a **signed** time trap, a per-address rate
limit, a word blocklist and Akismet. A sixth — an arithmetic challenge — is off
by default and is the only one that asks the visitor to do anything.

The challenge's answer is signed with `atf_sign_challenge()` and never sent to
the browser. A challenge whose expected answer travels in the page alongside the
question is decoration. The signature also binds the hour it was issued, and the
verifier accepts the current and previous hour only — so a rendered form has
between one and two hours to be submitted, and a harvested (answer, signature)
pair stops replaying when its hour ages out.

### `atf_client_ip` — Filter — *Stable*

```php
apply_filters( 'atf_client_ip', string $ip );
```

`REMOTE_ADDR` only, by default. The forwarded-for headers are trivially spoofed
by the client, so trusting them would let a spammer defeat the rate limiter by
sending a different one each time.

> Only name a header here if your proxy is **guaranteed** to overwrite it on the
> way in — otherwise the client sets it themselves.

---

## Submission

### `atf_before_submission` — Action — *Stable*

```php
do_action( 'atf_before_submission', int $form_id, array $request, array $schema );
```

### `atf_entry_created` — Action — *Stable*

```php
do_action( 'atf_entry_created', int $entry_id, int $form_id, array $values, array $schema );
```

**The main integration point.** Actions, notifications and webhooks all hang off
this, so anything hooked here sees the same entry they do. `$entry_id` is 0 when
the form is set not to store entries.

### `atf_submission_spam` — Action — *Stable*

```php
do_action( 'atf_submission_spam', int $entry_id, int $form_id, array $spam );
```

### `atf_entry_status_changed` — Action — *Stable*

```php
do_action( 'atf_entry_status_changed', int $entry_id, string $status, string $was );
```

### `atf_partial_saved` — Action — *Stable*

```php
do_action( 'atf_partial_saved', int $entry_id, int $form_id, string $url, array $values );
```

Fires when somebody saves a half-finished form. The plugin shows the resume link
on screen; **e-mailing it is deliberately left here**, because which address to
send it to is a per-form question and guessing it wrongly sends somebody's draft
to a stranger.

```php
add_action( 'atf_partial_saved', function ( $entry_id, $form_id, $url, $values ) {
	// Only when they have actually given you an address to send it to.
	$email = $values['f3'] ?? '';

	if ( is_email( $email ) ) {
		wp_mail(
			$email,
			__( 'Your saved form', 'my-plugin' ),
			sprintf( __( 'Pick up where you left off: %s', 'my-plugin' ), $url )
		);
	}
}, 10, 4 );
```

> The URL is a bearer credential — anyone holding it can read the half-finished
> answers. Send it only to an address the person themselves typed in.

### `atf_partial_rate_limit` — Filter — *Experimental*

```php
apply_filters( 'atf_partial_rate_limit', int $limit, int $form_id );
```

How many **new** partials one IP address may create per hour through the public
`/resume` endpoint. Default `30`; zero or less removes the cap. Updates to an
existing partial are never counted — an update needs a token, and the save that
minted the token spent a slot. Logged-in users are exempt for the same reason
the submission rate limit skips them.

Past the limit the save fails with the `atf_partial_rate_limited` error code
(HTTP 429).

### `atf_upload_overrides` — Filter — *Experimental*

```php
apply_filters( 'atf_upload_overrides', array $overrides, array $field, int $form_id );
```

The overrides array handed to Core's `wp_handle_upload()` for a file field's
upload. The default carries `test_form => false` and the field's own MIME
whitelist; a site that needs to loosen or tighten what Core will accept for one
form can do it here without reimplementing the upload path.

---

## Notifications and confirmations

### `atf_default_notification` — Filter — *Stable*

```php
apply_filters( 'atf_default_notification', array $notification, int $form_id );
```

What a form with no notifications configured sends. The default is one e-mail to
the administrator with every answer — because a form that collects an enquiry and
tells nobody is the most common way a forms plugin fails in production, and it
fails silently.

### `atf_notification_email` — Filter — *Stable*

```php
apply_filters( 'atf_notification_email', array $mail, array $notification, array $context );
```

`array( 'to', 'subject', 'message', 'headers', 'attachments' )`. **Returning an
empty `to` cancels the send.**

### `atf_notification_sent` — Action — *Stable*

```php
do_action( 'atf_notification_sent', bool $sent, array $mail, array $notification, array $context );
```

### `atf_email_html` — Filter — *Stable*

```php
apply_filters( 'atf_email_html', string $html, string $body, array $context );
```

The complete document a notification is wrapped in.

### `atf_confirmation` / `atf_default_confirmation` — Filters — *Stable*

```php
apply_filters( 'atf_confirmation', array $resolved, array $schema, array $values, int $entry_id );
apply_filters( 'atf_default_confirmation', array $confirmation );
```

---

## Post-submit actions

### `atf_run_action` — Filter — *Stable*

```php
apply_filters( 'atf_run_action', mixed $result, array $action, array $context );
```

Runs an action type this plugin does not know — push to a CRM, create a calendar
event, charge a card. Return a `WP_Error` to have the failure recorded against
the entry.

### `atf_creatable_post_types` — Filter — *Stable*

```php
apply_filters( 'atf_creatable_post_types', array $types, array $context );
```

Defaults to `post` and `page`.

> A form's settings are editable by anyone with `atf_edit_forms`, which is a
> lower bar than "may publish to any post type". Without this constraint an
> editor could build a form that publishes to a type they cannot otherwise touch.

### `atf_allow_direct_publish` — Filter — *Stable*

```php
apply_filters( 'atf_allow_direct_publish', bool $allow, array $context );
```

Defaults to **false**: a submission that asks for `publish` is downgraded to
`pending`. A form that publishes straight to the front page is a defacement
waiting to happen.

### `atf_assignable_roles` — Filter — *Stable*

```php
apply_filters( 'atf_assignable_roles', array $roles, array $context );
```

Defaults to the site's own default role **and nothing else**.

> Anything added here is a role a stranger can obtain by filling in a form.

### `atf_allow_registration` — Filter — *Stable*

```php
apply_filters( 'atf_allow_registration', bool $allow, array $context );
```

Whether a form may register users while `users_can_register` is off. Defaults to
false.

### `atf_webhook_payload` — Filter — *Stable*

```php
apply_filters( 'atf_webhook_payload', array $payload, array $context );
```

### `atf_post_created` / `atf_user_registered` / `atf_file_uploaded` — Actions — *Stable*

```php
do_action( 'atf_post_created', int $post_id, array $context );
do_action( 'atf_user_registered', int $user_id, array $context );
do_action( 'atf_file_uploaded', int $attachment_id, array $field, int $form_id );
```

---

## Entries, export and analytics

### `atf_entry_statuses` — Filter — *Stable*

```php
apply_filters( 'atf_entry_statuses', array $statuses, bool $include_trash );
```

Every status an entry can hold. Use it — and `atf_entry_statuses()` — for any
query over entries.

`'post_status' => 'any'` does **not** work here, and the way it fails is the
problem. Entry statuses are all registered `exclude_from_search` so that no
theme, feed or site search can surface somebody's submission, and `'any'` means
"every status *not* excluded from search". A query written that way matches no
entry at all: it finds nothing, throws nothing, and returns success. That is how
the retention sweep came to delete nothing on sites that had asked it to, and how
a privacy export came back empty for somebody with a dozen submissions.

```php
$entries = get_posts(
	array(
		'post_type'   => ATF_ENTRY_TYPE,
		'post_status' => atf_entry_statuses(),
	)
);
```

### `atf_prepare_entry` — Filter — *Stable*

```php
apply_filters( 'atf_prepare_entry', array $record, WP_Post $post, array $schema );
```

### `atf_export_columns` / `atf_export_cell` — Filters — *Stable*

```php
apply_filters( 'atf_export_columns', array $columns, array $schema );
apply_filters( 'atf_export_cell', string $cell, string $key, array $entry );
```

### `atf_form_analytics` — Filter — *Stable*

```php
apply_filters( 'atf_form_analytics', array $report, int $form_id );
```

The whole report. Alongside the headline counts and `fields`, it carries:

| Key | What it is |
|---|---|
| `sampled` | How many entries the report was computed from. |
| `timeline` | `{ date, count }` per day, oldest first, **including the empty days**. |
| `dimensions` | `{ id, label }` for each field the report can be grouped by. |
| `breakdown` | Every numeric answer grouped by one of them, or `null`. |

Each numeric field in `fields` also gains `numbers` (count, mean, median, min,
max and the full distribution) and, for a 0–10 scale, `nps`.

### `atf_analytics_dimensions` — Filter — *Stable*

```php
apply_filters( 'atf_analytics_dimensions', array $dimensions, array $schema );
```

The fields a report offers to group by. By default the choice fields with between
two and twelve options — more than that is not a breakdown, it is the raw data
with extra steps. Add your own if a field of yours is categorical in a way the
default rule cannot see.

### `atf_record_view` — Filter — *Stable*

```php
apply_filters( 'atf_record_view', bool $record, int $form_id );
```

Views by anyone who can edit forms are never counted, so building and previewing
does not inflate a form's own conversion rate.

### `atf_analytics_sample_size` — Filter — *Stable*

```php
apply_filters( 'atf_analytics_sample_size', int $limit, int $form_id );
```

How many entries a report reads, 500 by default. The cap is what keeps a report
on a form with a hundred thousand entries answering in a moment; raising it trades
that for exactness. Spam and partials are never included.

### `atf_developer_mode` — Filter — *Stable*

```php
apply_filters( 'atf_developer_mode', bool $enabled, int $user_id );
```

Whether the developer surfaces are shown — currently the demo-data tools in the
analytics window and the dock. It reads OpenStation's own per-user **Developer
mode** preference (Preferences → Features), so somebody who has turned developer
tools on once gets them everywhere; without OpenStation there is no switch to read
and it is false.

Switch it on without OpenStation:

```php
add_filter( 'atf_developer_mode', function ( $on ) {
	return $on || current_user_can( 'manage_options' );
} );
```

**This is not a permission.** Returning true grants nothing on its own: it decides
what is *shown*, and every route it reveals checks `atf_edit_forms` as well. A
preference lives in user meta, so treating it as authorisation would mean anybody
who can write their own meta could seed a database.

### `atf_retention_applied` — Action — *Stable*

```php
do_action( 'atf_retention_applied', int $deleted );
```

---

## Importers

### `atf_importers` — Filter — *Experimental*

```php
apply_filters( 'atf_importers', array $importers );
```

The registry behind **Forms → Import**. Each entry is keyed by importer id and
holds four required things: a `label`, and three callables — `available()` (does
the source plugin's data exist on this site), `forms()` (source id => title, for
the picker), and `import( $source_id )` (returns the new form id or a
`WP_Error`). The built-in importers register through this same filter, exactly
as a third-party source would.

Importers read the source plugin's *data*, not its API — the moment somebody
most wants to import is right after deactivating the old plugin.

```php
add_filter( 'atf_importers', function ( $importers ) {
	$importers['my-forms'] = array(
		'label'     => 'My Forms',
		'available' => fn() => (bool) get_option( 'my_forms' ),
		'forms'     => fn() => wp_list_pluck( get_option( 'my_forms' ), 'title', 'id' ),
		'import'    => 'my_forms_import_one',
	);

	return $importers;
} );
```

#### Bringing the stored submissions too

An importer may add two more callables. They are optional, and they come as a
pair: an importer that can count submissions but not import them would put a
number on screen with no button behind it, so offering only one of the two drops
both.

```php
'entries'        => fn( $source_id, $form_id = 0 ) => 412,
'import_entries' => 'my_forms_import_entries', // ( $source_id, $form_id, $limit )
```

- **`entries( $source_id, $form_id = 0 )`** — how many stored submissions the
  source holds for that form. Given a `$form_id`, report what is *still waiting*
  rather than the total, so the offer disappears when the migration is finished.
- **`import_entries( $source_id, $form_id, $limit )`** — brings at most `$limit`
  of them across and returns `{ imported, skipped, done, remaining }`, or a
  `WP_Error`. Called repeatedly until `done`; a form that has been live for years
  is the normal case, not the exceptional one.

Store each one with **`atf_import_entry( $form_id, $args )`**, which handles the
parts every source gets wrong the same way:

```php
atf_import_entry( $form_id, array(
	'values'       => array( 'your-name' => 'Elena Ruiz' ), // keyed by SOURCE field name
	'importer'     => 'my-forms',
	'record'       => '4182',        // the source's id for this submission
	'submitted_at' => 1741080900,    // unix; the entry keeps this date, not today's
	'spam'         => false,
	'ip'           => '203.0.113.7',
	'user_agent'   => 'Mozilla/5.0 …',
) );
```

Three things it guarantees, each of which is a way migrations normally go wrong:

- **Values are sanitised but deliberately not validated.** Validation enforces
  *today's* rules — required fields, choice whitelists, bounds — against answers
  given years ago under a form that may since have lost the option somebody
  picked. Anything unsafe is stripped; anything merely unexpected is kept, because
  a migration that dropped it would be deleting the history it was asked to move.
- **The entry keeps its original date.** A history that all arrives today is not
  a history.
- **Running it twice imports nothing twice.** Each entry records
  `{ importer, record }`, and a second pass skips what the first brought across —
  which matters, because the natural response to a migration that looks incomplete
  is to run it again.

Reading the values requires knowing which source field became which new field.
`atf_create_imported_form()` takes that map as its fifth argument and keeps it on
the form; pass it when you import, or the submissions are unreadable afterwards.
`atf_form_import_source()` and `atf_form_import_map()` read both back.

> **Watch the post statuses.** Both sides of this migration hide records behind
> `exclude_from_search` — Flamingo's spam status on the way in, every AllTerrain
> entry status on the way out. A query using `post_status => 'any'` means "every
> status *not* excluded from search", so it silently omits all of them. Name the
> statuses explicitly.

### `atf_imported_schema` — Filter — *Experimental*

```php
apply_filters( 'atf_imported_schema', array $schema, string $importer_id, string $source_id );
```

The converted schema, before it is normalised and saved. The place to correct
a mapping the converter got wrong for your data — rename a field, add a
notification — without forking the importer.

### `atf_show_import_notice` — Filter — *Experimental*

```php
apply_filters( 'atf_show_import_notice', bool $show );
```

Whether the plugin offers the import at all. When forms are found in another
plugin, an administrator sees one notice — on this plugin's own screens and on
the Plugins screen, where an activation lands — with a button that imports
every one of them. Return `false` to introduce the importer your own way rather
than teaching each administrator to dismiss it.

The offer already stops on its own: "Not now" is remembered per user
(`atf_import_notice_dismissed` user meta), and nothing is offered once anything
has been imported (`atf_has_imported` option). What the survey found is cached
for twelve hours and dropped whenever a form is imported or a plugin is
activated or deactivated.

### `atf_form_imported` — Action — *Experimental*

```php
do_action( 'atf_form_imported', int $form_id, string $importer_id, string $source_id );
```

Fires after a form is imported. The source plugin's data is never modified, so
importing is safe to repeat — hook here if you need to record the mapping or
redirect shortcodes.

### `atf_entries_imported` — Action — *Experimental*

```php
do_action( 'atf_entries_imported', int $form_id, array $result, array $source );
```

Fires after each pass of importing stored submissions. `$result` is
`{ imported, skipped, done, remaining }`; `$source` is `{ importer, source }`.
Fires once per pass, not once per migration, so a form imported in chunks fires
it several times — check `$result['done']` for the last one.

---

## Themes

### `atf_theme_tokens` — Filter — *Stable*

```php
apply_filters( 'atf_theme_tokens', array $tokens );
```

**The token surface itself.** Adding a token here makes it settable by every
theme and by the Theme Studio, which builds its controls from this list.

> A plugin adding a token is also responsible for the CSS that reads it. The test
> suite asserts the token table and the stylesheet agree in both directions.

### `atf_theme_token_control` — Filter — *Experimental*

```php
apply_filters( 'atf_theme_token_control', array $control, string $token );
```

Which control the Theme Studio shows for a token: `color`, `length`, `select` or
`text`.

### `atf_builtin_themes` — Filter — *Stable*

```php
apply_filters( 'atf_builtin_themes', array $themes );
```

Adding a theme here makes it available everywhere a built-in is, without creating
a post — the right shape for a theme shipped inside another plugin.

### `atf_resolved_tokens` — Filter — *Stable*

```php
apply_filters( 'atf_resolved_tokens', array $tokens, string $slug, array $overrides );
```

The last word on how a form looks.

### `atf_theme_saved` — Action — *Stable*

```php
do_action( 'atf_theme_saved', int $theme_id, array $tokens );
```

---

## Capabilities

### `atf_capability_map` — Filter — *Stable*

```php
apply_filters( 'atf_capability_map', array $map );
```

Role slug => capabilities. Applied at activation and whenever
`atf_add_capabilities()` runs — roles are stored in the database, not computed
per request, so call `atf_add_capabilities()` after filtering to apply a change.

### `atf_can_read_entries` — Filter — *Stable*

```php
apply_filters( 'atf_can_read_entries', bool $can, int $form_id );
```

The seam for per-form permissions: a site can let a department read only the
entries of the forms it owns by returning false for every other id.

---

## Assets

### `atf_script_config` — Filter — *Stable*

```php
apply_filters( 'atf_script_config', array $config );
```

The blob printed as `window.allTerrainForms`.
