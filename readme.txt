=== AllTerrain Forms – Contact Form, Drag & Drop Form Builder, Surveys, Quizzes, Multi-Step Forms & Conditional Logic ===
Contributors: allterraindeveloper
Tags: contact form, form builder, survey, quiz, conditional logic
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 7.4
Requires Plugins: desktop-mode
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Drag & drop contact form builder with conditional logic, multi-step forms, file uploads, surveys, quizzes, entries and 10 themes. All free.

== Description ==

**The best contact form plugin is the one that doesn't sell you the rest of itself.** AllTerrain Forms is a complete WordPress form builder — contact forms, survey forms, quiz forms, registration forms, order forms, booking forms, feedback forms — with every feature included, free, forever.

Conditional logic. Calculations. Multi-page forms. File uploads. Signatures.
Save and resume. Entry management with CSV export. Webhooks. Form analytics
with Net Promoter Score. User registration. Ten form themes you can rebuild
without writing CSS.

There is no Pro version, no upsell notice in your dashboard, no feature that
turns out to need a licence key. None of these things are hard to build. They
were only ever sold separately because somebody could.

= Drag and drop form builder =

Create a form by dragging. Pull a field from the palette onto the canvas, drop
it where you want it, type the label. That is the whole learning curve — 37
field types, from a simple text box to a signature pad to a Likert matrix, and
every one behaves the same way.

Then paste one shortcode into a page, or drop in the **Form** block and pick
your form from a list:

`[allterrain_form id="12"]`

= Conditional logic you can actually see =

Show and hide fields, pages, emails and confirmations based on answers. Most
form builders tell you a field *has* a rule and leave you to go and find out
what it says. This one writes it on the card — **Shown when · Can you make it?
· is · Yes** — and draws a line from the question that decides to the fields it
decides, labelled with the answer that triggers them.

Hover a field and everything it does not touch fades. A rule pointing at a
question you deleted turns red, because that form is stuck and you would never
otherwise know.

= Multi-step forms, calculations and totals =

Break long forms into pages with per-step validation and a progress indicator.
Add calculated fields and running totals — an order form that adds up, a quote
form that quotes. Payment-style product and option fields keep their prices.

= File upload forms and signatures =

Accept file uploads with per-field type and size limits, stored in a protected
directory and attached to the entry. Collect a real drawn signature. Uploads
are parented to their submission, so deleting the entry cleans up after itself.

= Surveys, quizzes and NPS =

Build survey forms with rating scales, opinion scales, Likert matrices and
0–10 questions. Build quizzes with scoring and per-answer feedback. The
Analytics window turns responses into answers: submissions over time,
conversion and completion rates, per-question distributions, a Net Promoter
Score panel, and any question broken down by any other question.

= Entry management, search and CSV export =

Every submission is saved as an entry. Search answers, filter by form, status
and star, read one beside the list, add private notes, and export to CSV or
JSON. Unread counts, a spam folder, and bulk actions included.

= Email notifications, confirmations and webhooks =

Send conditional notification emails with CC, BCC, reply-to and attachments.
Show a message, redirect, or send visitors to a page — conditionally, per
answer. POST every entry as JSON to any URL, signed with HMAC-SHA256, and hook
`atf_entry_created` for anything else.

Every email and confirmation has an **Insert a value** button that lists your
own questions by the label you wrote — not `{field:f2}` — with a live preview
of the finished text.

= Import from Contact Form 7, WPForms and Gravity Forms — entries too =

Switching form plugins? You do not have to go looking. Activate AllTerrain
Forms and it tells you what it found — *"AllTerrain Forms found 6 forms in
Contact Form 7"* — with one button that migrates all of them. Conditional
logic carries across, payment fields keep their prices, an order total keeps
calculating, and a Gravity Forms List becomes a real repeater.

The stored submissions come too. Messages Flamingo kept for Contact Form 7,
Gravity Forms entries, and WPForms Pro entries can all be brought across after
their form is — each with its original date, spam filed as spam, and the
originals left untouched. Running an import twice never imports anything
twice, and everything still works after the old plugin is deactivated, because
the data outlives the plugin.

= Ten form themes, and an eleventh you make yourself =

Clean, Midnight, Glass, Brutal, Paper, Neon, Terminal, Soft, Editorial and
Holo. A theme here is a flat list of 69 design tokens — no theme PHP, no
template, no stylesheet to edit.

**Theme Studio** gives you a control for every token and a live preview that
updates the instant you click. Six plain-language dials cover the common case:
Accent, Roundness, Density, Depth, Fields, Labels. Nobody thinks
*"radius-field: 14px"* — they think *rounder*. Save your changes as a theme,
pick it on any form, export it as JSON, import it on another site.

= Spam protection without a CAPTCHA =

A honeypot, a signed time trap, a per-address rate limit, a word blocklist,
and Akismet if you already have it. Nothing asks a visitor to identify traffic
lights, because a CAPTCHA charges the visitor for the site's spam problem and
fails accessibility for a good number of people.

Anything judged spam is **kept**, in a spam folder, never silently thrown away.

= Accessible (WCAG AA) and works without JavaScript =

Real labels bound to real controls. Grouped inputs in a fieldset with a
legend. Hints and errors wired with `aria-describedby`. An error summary that
takes focus. Every theme meets WCAG AA contrast, and the test suite fails if
one stops.

And the form works with JavaScript switched off — a real POST, real
server-side validation, errors against the right fields, answers still in the
boxes.

= GDPR-friendly by structure =

A form is a post. An entry is a post. A note on an entry is a comment. Nothing
lives in a custom table, so WordPress's own privacy exporter and eraser, user
capabilities, search and the trash already work on your form data. A consent
field, IP anonymisation and per-form storage controls are built in — and
nothing is ever sent to us or to any third party.

= Built on OpenStation =

AllTerrain Forms is an [OpenStation](https://wordpress.org/plugins/desktop-mode/)
desktop app, and OpenStation is **required** — WordPress installs it alongside
this plugin and keeps the two together. OpenStation turns wp-admin into a
windowed desktop: the builder is a real window you can put beside your
entries, drag a field from one form into another, and drop an image straight
from the media browser onto a choice. That spatial way of working is the
product, which is why the plugin does not ship a lesser version of itself
without it.

Your visitors are never part of that dependency: published forms render and
submit on the front end regardless of what is happening in the admin.

= Built in the open =

Source, issue tracker and full developer documentation:
[github.com/AllTerrainDeveloper/forms](https://github.com/AllTerrainDeveloper/forms)

Over 800 automated tests, and the conditional-logic and calculation engines
are tested against one shared table of cases in both PHP and JavaScript —
because if the browser and the server ever disagreed about which fields were
required, you would be shown a form you could not submit.

== Installation ==

1. Install through **Plugins → Add New**, or upload the ZIP under
   **Plugins → Add New → Upload Plugin**. WordPress installs OpenStation
   alongside it automatically.
2. Activate it.
3. Open **AllTerrain Forms** from the dock and build a form by dragging.
4. Paste the shortcode it shows you into any page or post, or add the **Form**
   block.

No account, no licence key, no connection to any external service.

== Frequently Asked Questions ==

= Is AllTerrain Forms really free? What's the catch? =

It is, and there isn't one. There is no Pro version and no paid add-on.
Conditional logic, multi-step forms, file uploads, entry management, CSV
export, webhooks, quizzes, surveys, analytics — everything described on this
page is in this plugin.

= How do I create a contact form? =

Activate the plugin, open **AllTerrain Forms** from the dock, and drag fields
from the palette onto the canvas — or start from the contact form template.
Paste the shortcode into a page, or use the **Form** block. The whole thing
takes about a minute.

= Do I need the OpenStation plugin? =

Yes. The builder is an OpenStation desktop app — a real window you can drag
things between — and WordPress installs and activates OpenStation for you when
you install this plugin. Your visitors never need it: published forms render
and submit on the front end without any of the admin machinery.

= Can I import forms from Contact Form 7, WPForms or Gravity Forms? =

Yes, and it offers before you ask: if the plugin finds forms from Contact Form
7, WPForms or Gravity Forms it says so and gives you a single button that
imports all of them. **Forms → Import** lists them individually whenever you
want it, including forms whose plugin has already been deactivated, because
the data outlives the plugin. Each import creates a new AllTerrain form and
leaves the original untouched.

= Can I import my old entries and submissions too? =

Yes. Stored submissions from all three sources — Flamingo's messages for
Contact Form 7, Gravity Forms entries, WPForms Pro entries — can be brought
across after their form is imported, each keeping its original date, with spam
filed as spam and the originals left untouched. Running it twice never imports
anything twice.

= Will my forms keep working if I deactivate the plugin? =

Your data stays: forms and entries are ordinary posts, so deactivating leaves
them in your database untouched. Uninstalling removes them, which is what
uninstalling should do — export your entries to CSV first if you want them.

= Can I move a form to another site? =

Yes. Export the form as JSON and import it on the other site. Themes export
and import the same way.

= Does it work with page builders and the block editor? =

Yes. Use the **Form** block in the block editor, or the shortcode anywhere a
shortcode works, including page builders and widgets.

= Does it send my data anywhere? Is it GDPR-friendly? =

Nothing is sent to us or to any third party. Entries are ordinary WordPress
posts, so the privacy exporter and eraser already cover them; a consent field,
IP anonymisation and per-form storage controls are built in. Akismet is used
only if you already have it installed and configured.

= Is it accessible? =

That was a design requirement rather than an afterthought — real labels,
fieldsets, `aria-describedby`, a focus-taking error summary, WCAG AA contrast
in every theme, and a form that works without JavaScript. If you find
something that is not accessible, please open an issue; it will be treated as
a bug.

= Can developers extend it? =

Yes, and it is documented. 55 filters and 15 actions, a field-type registry
that built-in fields use exactly as a third-party plugin would, a theme token
API, importer hooks, and a REST namespace. Full reference in the repository.

== Screenshots ==

1. The form builder, open as a window on the OpenStation desktop. Drag a field from the palette onto the canvas; the inspector on the right changes what you selected.
2. The Theme tab wearing Neon — ten built-in themes across the top, plain-language dials on the left, and a live preview that updates the instant you click.
3. Entries beside the builder. Search, filter and star submissions; read one beside the list; export to CSV or JSON.
4. Analytics: submissions over time, conversion and completion, and a Net Promoter Score panel — grouped by any question you ask.
5. Conditional logic drawn on the canvas — every card states its condition, and a line joins the question that decides to the fields it decides.
6. The Insert a value picker, listing your own questions by name with an example of what each one will say.

== Changelog ==

= 0.1.0 =
* First release.

== Upgrade Notice ==

= 0.1.0 =
First release.
