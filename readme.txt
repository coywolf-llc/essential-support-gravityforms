=== Essential Support for Gravity Forms ===
Contributors: coywolf
Tags: gravity forms, support, help desk, tickets, contact form
Requires at least: 6.3
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn any Gravity Form into an Essential Support ticket form — verify-first by email, with your ticket types and optional file attachments.

== Description ==

Essential Support for Gravity Forms connects a Gravity Form to your [Essential Support](https://essential.support) workspace. When someone submits the form, they get an email asking them to confirm their request. Nothing is created until they click the link — which also filters out spam and junk. Once confirmed, the ticket opens in your workspace and the customer lands in their portal with the request in front of them.

**How it works**

* **Verify-first.** A submission sends a confirmation email. The ticket is only created after the customer confirms, so unverified junk never reaches your agents.
* **Zero-config field mapping.** The add-on auto-detects your email, subject, message, ticket-type, and file-upload fields. You can override any of them, but you usually don't need to.
* **Customer-chosen ticket type.** Add a Drop Down (or Radio) field and the add-on fills it with your workspace's live ticket types, so customers pick the type themselves and it always matches your real types.
* **Attachments (optional).** If your workspace has Images & Files turned on, map a File Upload field and the files attach to the ticket once the customer verifies their email. The ticket shows an "Attaching…" placeholder for each incoming file the moment it opens, replaced by the file (or image thumbnail) as it finishes copying. You can also have the add-on delete each file from your site once Essential Support has stored a copy, to reclaim disk space.

This add-on works with any Essential Support workspace — no lock-in to a particular host app.

== Installation ==

1. Install and activate the plugin (Gravity Forms 2.5+ must be active).
2. Go to **Forms → Settings → Essential Support** and enter your **Workspace URL** (e.g. `https://acme.essential.support`) and an **API key** (create one in Essential Support under Settings → Integrations).
3. Open a form, go to **Settings → Essential Support**, and create a feed: map the email, subject, and message fields, and choose a ticket type.
4. (Recommended) Set the form's confirmation to tell people to check their email to confirm their request.

**To enable attachments**

1. Turn on **Images & Files** in your Essential Support workspace.
2. In Essential Support, add a **webhook** for the `ticket.created` event pointing at the callback URL shown on the plugin's settings screen.
3. Paste that webhook's **signing secret** into the plugin settings.
4. In your form feed, map the **File Upload** field. Files upload to the ticket after the customer confirms.
5. (Optional) Under **File attachments**, tick **Clean up copied files** to delete each uploaded file from this site once Essential Support confirms it stored a copy. The Gravity Forms entry keeps its record; only the file on disk is removed.

== Frequently Asked Questions ==

= Do I need Gravity Forms? =

Yes — Gravity Forms 2.5 or newer.

= Why doesn't the ticket appear right away? =

By design. The request is held until the customer confirms it by clicking the link in the email they receive. This is what keeps spam out.

= Why can't I map a file upload field? =

Your workspace needs Images & Files enabled first. Until then the file mapping is hidden and the add-on tells you to set it up.

= Is my API key exposed to visitors? =

No. The API key and webhook secret are stored on your server and used only in server-to-server calls.

== Changelog ==

= 1.0.1 =
* Update readme to describe auto-detection and the customer ticket-type dropdown (#1).

= 1.0.0 =
* Initial release: verify-first ticket creation, field mapping, live ticket types, and optional file attachments via the ticket.created webhook.
