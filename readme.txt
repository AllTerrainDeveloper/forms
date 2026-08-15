=== AllTerrain Forms ===
Contributors: allterraindeveloper
Tags: forms, contact form, form builder, survey, quiz
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Forms with every premium feature free. Conditional logic, calculations, multi-page, file uploads, signatures, entry management and ten themes. No paywall.

== Description ==

A forms plugin with no paid tier, because there is no technical reason for one.

Conditional logic, calculations, multi-page forms, file uploads, signatures, repeaters, save-and-resume, entry management, CSV export, conditional notifications, webhooks, surveys, quizzes, user registration and front-end post submission are the paid add-ons of every other forms plugin. All of them are here, in the free plugin.

= Build by dragging =

Drag a field from the palette onto the canvas. Reorder by dragging. Every palette entry is also a real button, and Alt with an arrow key moves a field — dragging is never the only way to do anything.

With the [OpenStation](https://github.com/WordPress/openstation) desktop shell installed the builder becomes a native window, which is what lets you drag a field between two open forms, drop an image straight from WP Explorer onto an image-choice option, or drag a submission out of the Entries window onto another plugin entirely. Without OpenStation it is an ordinary admin page and everything else still works.

= Thirty-seven field types =

Single line, paragraph, email, website, phone, number, password, hidden, dropdown, multi-select, radios, checkboxes, image choice, toggle, date, time, date & time, date range, file upload, signature, star rating, opinion scale, Likert matrix, slider, colour, name, address, country, repeater, section heading, HTML block, divider, spacer, page break, consent, calculated total and quiz question.

Adding your own is one `atf_register_field_type()` call.

= Ten themes, and an eleventh you make yourself =

Clean, Midnight, Glass, Brutal, Paper, Neon, Terminal, Soft, Editorial and Holo.

A theme is a map of 69 design tokens — colour, corner radius, shadows, borders, spacing, type, label position, button shape, focus ring, motion and effects. There is no theme CSS to write. Open Theme Studio, duplicate a theme, move the sliders, save. It becomes selectable everywhere, and exports as JSON so you can use it on another site.

= Spam blocking without a captcha =

A honeypot, a signed time trap, a per-address rate limit, a word blocklist, and Akismet when you already have it. Nothing asks your visitors to identify traffic lights — that charges them for your spam problem and locks out people who cannot pass it.

Anything judged spam is stored in a spam folder, never silently deleted. False positives are recoverable.

= It works without JavaScript =

The form is a real form. With scripting switched off it submits, validates on the server, and comes back with errors against the right fields and the answers still in place. Everything else is enhancement on top of something that already worked.

= Accessible =

Real labels bound to their controls. Grouped controls in fieldsets with legends. Hints and errors wired with aria-describedby. An error summary that takes focus. Required announced properly rather than with an asterisk a screen reader reads as "asterisk". All ten themes meet WCAG AA contrast, and the test suite fails if one stops.

= Privacy =

A retention policy that deletes old submissions automatically, IP anonymisation, and integration with WordPress's own export and erase tools, so a data request reaches form submissions without anyone having to remember they live somewhere separate.

Deleting the plugin does **not** delete your submissions. They are somebody's enquiries and applications, and uninstalling a plugin is not consent to destroy them. Define `ATF_REMOVE_ALL_DATA` if that is genuinely what you want.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/`, or install it through Plugins → Add New.
2. Activate it.
3. Go to **Forms** and start from a template.
4. Place the form with the shortcode the builder shows you, or with the **Form** block.

== Frequently Asked Questions ==

= Is anything paid? =

No. There is no pro version, no add-on store and no upsell.

= Do I need OpenStation? =

No. It is optional. With it the builder is a desktop window with cross-window drag and drop; without it, an ordinary admin page.

= Where are submissions stored? =

As posts, in your own database. Nothing is sent anywhere. Uploaded files go to a directory that is not reachable by URL.

= Can I add my own field type? =

Yes — one `atf_register_field_type()` call. Every one of the thirty-seven built-in types uses exactly that API, so there is no privileged path.

= Does it work with page caching? =

Yes. The one per-user value in the page is the REST nonce, which is deliberately empty for logged-out visitors so a cache cannot serve one person's nonce to everybody.

== Screenshots ==

1. The builder — palette, canvas and inspector.
2. Ten themes, previewed live.
3. Theme Studio: a control per design token.
4. The entries window, with filters and export.
5. A form on the front end.

== Changelog ==

= 0.1.0 =
* First release.
* Thirty-seven field types, drag-and-drop builder, ten themes and Theme Studio.
* Conditional logic, calculations, multi-page forms, repeaters, file uploads, signatures.
* Entry management, CSV export, per-form analytics, quiz scoring.
* Conditional notifications, confirmations, webhooks, post creation and user registration.
* Anti-spam without a captcha. GDPR retention, export and erasure.
* OpenStation integration: three native windows, a wallpaper icon, a widget, a title-bar preview button and cross-window drag and drop.
