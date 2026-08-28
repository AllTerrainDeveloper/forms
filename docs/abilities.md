# Abilities — the plugin for AI agents

AllTerrain Forms registers nine abilities through WordPress's Abilities API,
so an agent — through MCP, the REST channel, or anything else that speaks
abilities — can build forms, submit to them, read what came back and report on
it. Every ability is a thin adapter over the same functions the windows and
the REST API use: an agent and a human clicking the same button get the same
behaviour, the same validation and the same capability checks.

All nine are `public`, categorised under `allterrain-forms`, and registered on
`wp_abilities_api_init`. On a WordPress without the Abilities API the hooks
never fire and the plugin behaves as if the file did not exist.

**Status: Experimental** — the set may grow and the output shapes may gain
fields inside 0.x; existing fields will not be renamed without a changelog
note.

## The set

| Ability | Does | Permission |
|---|---|---|
| `list-forms` | Every form with id, title, status, theme, shortcode, entry count and distilled fields. The starting point: its ids feed everything else. | read entries *or* edit forms; lists only forms the per-form gate lets the caller read |
| `get-form` | One form in the same shape. Read it before submitting or interpreting entries — values are keyed by these field ids. | edit forms, *or* read entries **for that form** |
| `list-field-types` | The vocabulary for building: every registered type with slug, label, description, group and value shape. | edit forms |
| `create-form` | Builds a form from a title and a loose field list (`{ type, label, required?, hint?, placeholder?, choices? }`); ids are minted, defaults seeded, shortcode returned. Fields go through the same normaliser a JSON import does. | edit forms |
| `set-form-theme` | Applies an installed theme to a form; refuses invented slugs rather than falling back. | edit forms |
| `list-entries` | Queries submissions with search, date range, status, starred and pagination; answers come back raw (by field id) and readable (by label). | read entries **for that form** |
| `get-entry` | One submission in full: label, raw value and formatted value per question. | read entries (checked against the entry's form) |
| `submit-form` | Submits through the visitor pipeline — availability, validation, anti-spam, storage, notifications. Refusals return per-field errors and store nothing. | any authenticated user |
| `form-report` | The analytics report as data: counts, rates, timeline, distributions, NPS, optional `group_by` breakdown. Prefer it over fetching every entry when the job is summarising. | read entries **for that form** |

Where a permission says **for that form**, the `alltfo_can_read_entries`
filter is asked with the `form_id` from the ability's input — so a site that
confines a user to a department's forms through that filter confines these
abilities the same way.

## The one honest liberty

`submit-form` mints a valid time-trap signature stamped a minute in the past.
The trap exists to catch bots that post faster than a human can read; an agent
invoking a described ability is not that traffic — which is exactly why the
ability requires an authenticated user, and an anonymous caller is refused
before this liberty is taken. Anonymous traffic has the rendered form and the
public REST `/submit` route, where the trap runs whole. Every other check —
honeypot, rate limit, blocklist, Akismet, and all of validation — runs exactly
as it does for a visitor, and spam verdicts still file the entry as spam
rather than discarding it.

## Trying one

```
wp eval 'var_export( wp_get_ability( "allterrain-forms/list-forms" )->execute() );'
```

Or over REST, on a site exposing abilities there, with any authenticated
application password:

```
POST /wp-json/wp/v2/abilities/allterrain-forms/create-form/run
{ "input": { "title": "Booking form", "fields": [ { "type": "text", "label": "Your name", "required": true } ] } }
```

The exact REST route shape belongs to core's Abilities API; the plugin only
declares the abilities and their schemas.
