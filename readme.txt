=== AllTerrain Forms ===
Contributors: allterraindeveloper
Tags: contact form, form builder, forms, conditional logic, survey
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 7.4
Requires Plugins: desktop-mode
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Drag-and-drop form builder with conditional logic, calculations, file uploads, entry management and 10 themes. Every premium feature, free.

== Description ==

**Every feature other form plugins sell you, in the free one.**

Conditional logic. Calculations. Multi-page forms. File uploads. Signatures.
Save and resume. Entry management with CSV export. Webhooks. Quizzes and
surveys. User registration. Ten themes you can rebuild without writing CSS.

There is no Pro version, no upsell notice in your dashboard, no feature that
turns out to need a licence key. None of these things are hard to build. They
were only ever sold separately because somebody could.

= Build a form by dragging =

Drag a field from the palette onto the canvas. Drop it where you want it. Type
the label. That is the whole learning curve — 37 field types, from a text box to
a signature pad to a Likert matrix, and every one behaves the same way.

Then paste one shortcode into a page, or drop in the **Form** block and pick it
from a list.

`[allterrain_form id="12"]`

= Conditional logic you can actually see =

Most builders tell you a field *has* a rule and leave you to go and find out
what it says. This one writes it on the card — **Shown when · Can you make it? ·
is · Yes** — and draws a line from the question that decides to the fields it
decides, labelled with the answer that triggers them.

Hover a field and everything it does not touch fades. A rule pointing at a
question you deleted turns red, because that form is stuck and you would never
otherwise know.

= Merge tags nobody has to learn =

Every email and confirmation box has an **Insert a value** button. It lists your
own questions by the label you wrote — not `{field:f2}` — grouped with the
person, the submission, the form and your site, each showing what it will
actually say on *your* site. Underneath, a live preview of the finished text.

= What you normally pay for =

Compared by feature rather than by brand. Every one of these is something the
well-known form plugins put behind a paid tier or a paid add-on, and every one of
them is included here:

* Conditional logic — show and hide fields, pages, emails and confirmations
* Reporting — response rates, distributions, NPS, and cross-tabs by any question
* Calculations and running totals
* Multi-page forms with per-step validation
* File uploads, with per-field type and size limits
* A signature field
* Save and continue later
* Entry management, search, filters and CSV or JSON export
* Conditional notifications, with CC, BCC, reply-to and attachments
* Webhooks, signed with HMAC-SHA256
* Quizzes with scoring, and survey reporting
* User registration and profile updates
* Post submission from the front end
* Anti-spam without a CAPTCHA
* Themes and full design control

Fourteen things, none of them technically hard, all of them normally the reason
you upgrade.

= Ten themes, and an eleventh you make yourself =

Clean, Midnight, Glass, Brutal, Paper, Neon, Terminal, Soft, Editorial and Holo.

A theme here is a flat list of 69 design tokens — no theme PHP, no template, no
stylesheet. **Theme Studio** gives you a control for every one of them and a live
preview beside it. Six plain-language dials cover the common case: Accent,
Roundness, Density, Depth, Fields, Labels. Nobody thinks *"radius-field: 14px"* —
they think *rounder*.

Save it, and it is a theme you can pick on any form, export as JSON, and import
on another site. No code, no build step.

= Switching? Bring your forms with you =

You do not have to go looking. Activate the plugin and it tells you what it
found — *"AllTerrain Forms found 6 forms in Contact Form 7"* — with one button
that brings all of them over. Each becomes an ordinary AllTerrain form: real
fields, the notification emails rewritten onto your own questions, the
thank-you message carried over. The original is never touched, so importing is
safe to try and safe to repeat.

**Forms → Import** is there too, when you would rather pick through them one at
a time. Both find forms other plugins left in your database — even after you
deactivated them.

Contact Form 7, WPForms and Gravity Forms are all supported — imports carry
conditional logic across, payment and product fields keep their prices, an
order total keeps calculating, and a Gravity Forms List becomes a real
repeater.

= Anti-spam that does not charge your visitors =

A honeypot, a signed time trap, a per-address rate limit, a word blocklist, and
Akismet if you already have it. Nothing asks a visitor to identify traffic
lights, because a CAPTCHA charges the visitor for the site's spam problem and
fails accessibility for a good number of people.

Anything judged spam is **kept**, in a spam folder, never silently thrown away.

= Accessible because it was built that way =

Real labels bound to real controls. Grouped inputs in a fieldset with a legend.
Hints and errors wired with `aria-describedby`. An error summary that takes
focus. Required announced properly rather than with an asterisk a screen reader
reads as "asterisk". Every theme meets WCAG AA contrast, and the test suite fails
if one stops.

And the form works with JavaScript switched off — a real POST, real server-side
validation, errors against the right fields, answers still in the boxes.

= Everything is a post =

A form is a post. An entry is a post. A note on an entry is a comment. Nothing
lives in a custom table, so the REST API, user capabilities, search, the trash,
and WordPress's own privacy exporter and eraser already work on your form data
without a line of integration code.

= Built on OpenStation =

AllTerrain Forms is an [OpenStation](https://wordpress.org/plugins/desktop-mode/)
desktop app, and OpenStation is **required** — WordPress installs it alongside
this plugin and keeps the two together. OpenStation turns wp-admin into a
windowed desktop: the builder is a real window you can put beside your entries,
drag a field from one form into another, and drop an image straight from the
media browser onto a choice. That spatial way of working is the product, which
is why the plugin does not ship a lesser version of itself without it.

Your visitors are never part of that dependency: published forms render and
submit on the front end regardless of what is happening in the admin.

= Built in the open =

Source, issue tracker and full developer documentation:
[github.com/AllTerrainDeveloper/forms](https://github.com/AllTerrainDeveloper/forms)

777 automated tests, and the conditional-logic and calculation engines are
tested against one shared table of cases in both PHP and JavaScript — because if
the browser and the server ever disagreed about which fields were required, you
would be shown a form you could not submit.

== Installation ==

1. Install through **Plugins → Add New**, or upload the ZIP under
   **Plugins → Add New → Upload Plugin**.
2. Activate it.
3. Go to **Forms** and build one.
4. Paste the shortcode it shows you into any page or post, or add the **Form**
   block.

No account, no licence key, no connection to any external service.

== Frequently Asked Questions ==

= Is anything paid? =

No. There is no Pro version and no paid add-on. Everything described here is in
this plugin.

= Do I need the OpenStation plugin? =

Yes. The builder is an OpenStation desktop app — a real window you can drag
things between — and WordPress installs and activates OpenStation for you when
you install this plugin. Your visitors never need it: published forms render
and submit on the front end without any of the admin machinery.

= Will my forms keep working if I deactivate the plugin? =

Your data stays: forms and entries are ordinary posts, so deactivating leaves
them in your database untouched. Uninstalling removes them, which is what
uninstalling should do — export your entries to CSV first if you want them.

= Can I import my forms from another form plugin? =

Yes, and it offers before you ask: if the plugin finds forms from Contact Form
7, WPForms or Gravity Forms it says so and gives you a single button that
imports all of them. It asks once — "Not now" is remembered, and it stops
offering entirely once anything has been imported.

**Forms → Import** lists them individually whenever you want it, including
forms whose plugin has already been deactivated, because the data outlives the
plugin. Each import creates a new AllTerrain form and leaves the original
untouched.

= Can I move a form to another site? =

Yes. Export the form as JSON and import it on the other site. Themes export and
import the same way.

= Does it work with page builders and the block editor? =

Yes. Use the **Form** block in the block editor, or the shortcode anywhere a
shortcode works, including page builders and widgets.

= Does it send my data anywhere? =

No. Nothing is sent to us or to any third party. Akismet is used only if you
already have it installed and configured.

= Is it accessible? =

That was a design requirement rather than an afterthought — see the
accessibility section above. If you find something that is not, please open an
issue; it will be treated as a bug.

= Can developers extend it? =

Yes, and it is documented. 55 filters and 15 actions, a field-type registry that
built-in fields use exactly as a third-party plugin would, a theme token API, and
a REST namespace. Full reference in the repository.

== Screenshots ==

1. The builder. Drag a field from the palette onto the canvas; the inspector on the right changes what you selected.
2. Conditional logic drawn on the canvas — every card states its condition, and a line joins the question that decides to the fields it decides.
3. The Insert a value picker, listing your own questions by name with an example of what each one will say.
4. Theme Studio. Ten built-in themes, plain-language dials, and a live preview of a real form.
5. Entries. Search, filter and star submissions; read one beside the list; export to CSV or JSON.
6. A form on the front end, in the Clean theme.

== Changelog ==

= 0.1.0 =
* First release.
* Drag-and-drop builder with 37 field types.
* Conditional logic, drawn on the canvas, with calculations and multi-page forms.
* Merge-tag picker that lists your own questions by name.
* Ten themes and a no-code Theme Studio over 69 design tokens.
* Entry management, CSV and JSON export.
* Analytics: submissions over time, per-question distributions, Net Promoter Score, and answers broken down by any grouping question.
* Conditional notifications, confirmations, webhooks and post-submit actions.
* Anti-spam without a CAPTCHA; spam is kept, not discarded.
* Save and continue later, quizzes, surveys, user registration.
* Works without JavaScript and meets WCAG AA in every theme.
* A native OpenStation desktop app: windowed builder, dock, cross-window drag and drop.

== Upgrade Notice ==

= 0.1.0 =
First release.
