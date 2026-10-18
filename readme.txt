=== Flurfunk ===
Contributors: max-ziebell
Tags: team chat, editorial, collaboration, tasks, comments
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Team conversations inside WordPress: channels, threads and tasks, attached to posts, with mentions, files and cards from other plugins.

== Description ==

Flurfunk brings the conversations of an editorial team into wp-admin, next to the work they are about.

* Channels and threads, every thread can be a task with a tick box.
* Threads can hang on a post (or anything a plugin registers) and show up in the editor's "Conversations" panel and in the posts list.
* @mentions, simple formatting, files with previews and players.
* Slash commands insert cards: posts, comments with buttons to approve them, and whatever other plugins add. Cards are built from ready blocks (facts, checklists, progress, status, people, tables) and can carry buttons that act on the site.
* Optional modules: a daily thread for comments awaiting moderation, and a review task when a post is submitted for review.
* Guests (e.g. guest authors) see only what concerns their own posts.
* Files stay out of the media library until someone adds them – without copying or moving.
* Tasks with due dates and responsible people, "My tasks", a board, reminders.
* Notifications: in-app list, mail (instant or daily summary), Web Push to browsers and phones, quiet hours, follow or mute threads.
* Light and dark, everything each person sets for themselves.
* Assistant accounts for agents and scripts, clearly marked in the conversation.
* Works in plain wp-admin, full screen as an installable web app, and inside the OpenStation desktop.
* Own tables, one JSON format for export and import (with files), privacy export/erase.

Developers extend Flurfunk with providers (slash commands and cards), targets, filters and a REST API. Documentation: [worldoptimizer.github.io/flurfunk](https://worldoptimizer.github.io/flurfunk/), source code and issues: [github.com/worldoptimizer/flurfunk](https://github.com/worldoptimizer/flurfunk).

== Installation ==

1. Upload the plugin and activate it.
2. Open Flurfunk in the admin menu: #general has a short welcome thread that explains the basics.
3. Open Settings → Flurfunk: name, logo, colours, who counts as team or guest, channels, assistants.

== Frequently Asked Questions ==

= Who can use Flurfunk? =

By default administrators and editors are "team" and see all channels; authors and contributors are "guests" and only see conversations about their own posts; subscribers don't see Flurfunk. Flurfunk has its own capabilities for this (flurfunk_team, flurfunk_guest, plus flurfunk_moderate to edit other people's messages and flurfunk_manage for the settings), which you assign to roles under Settings → Flurfunk → Permissions. Role editor plugins such as Members or User Role Editor can manage them as well, also for single users. What team and guests see is set per channel.

When updating from 0.1, the "team" and "guest" capabilities chosen in the old settings are turned into these role permissions once, so everyone keeps the access they had.

= What are modules? =

Optional parts of Flurfunk that you switch on or off under Settings → Flurfunk → Modules; a module that is off is not loaded at all. Flurfunk brings three: comment cards (/comment, with buttons to approve a comment, mark it as spam or trash it; on by default), a daily thread listing the comments awaiting moderation, and review requests (a task for the people who may publish a post submitted for review). Other plugins can add their own modules, which then show up in the same list.

= Can cards do something on the site? =

Yes. A card can carry buttons (card actions), for example "Approve" on a comment card. They run with the rights of the person who clicks, ask first where something is hard to undo, and write a line into the thread saying who did what. Which roles see a button is decided by a capability, so it can be set in the permissions matrix.

= Push notifications don't work. Why? =

Push needs HTTPS. Browsers only offer push on secure sites, so on a site without HTTPS the setting says so and push stays off. Mail and the in-app notifications work everywhere. On iPhone and iPad, add Flurfunk to the home screen first (Share → Add to Home Screen); iOS only allows push for web apps.

= Where are files stored? =

In uploads/flurfunk/, as normal attachments that the media library hides. "Add to media library" only makes them visible there; the file keeps its address.

= Can scripts or AI agents post? =

Yes, as an assistant account. Create one under Settings → Flurfunk → Assistants, give it an application password (needs HTTPS) and send requests to the REST API, for example `POST /wp-json/flurfunk/v1/threads`. On the command line: `wp flurfunk bot-create`, then `wp flurfunk post`.

= What happens to the conversations when I delete the plugin? =

By default they stay, so a reinstall picks up where you left off. Untick "Keep conversations when the plugin is deleted" under Settings → Flurfunk → Data to remove the tables, the Flurfunk files and the assistant accounts on deletion. Export them first if you want a copy.

== External services ==

Flurfunk talks to outside services only for these two features.

= Web Push =

When a person turns on push notifications in their browser, the browser registers with its vendor's push service and gives Flurfunk an address there. From then on, for each notification that person should get (a mention, a task, a reply …), Flurfunk sends an encrypted message to that address; the push service delivers it to the browser. The content (title, a short text, a link into Flurfunk) is end-to-end encrypted for that browser (RFC 8291), so the push service cannot read it; it sees the address, the time and the size of the message, plus the site's administration email address, which the Web Push standard (VAPID, RFC 8292) asks senders to give as a contact. Nothing is sent for people who did not turn push on, and turning it off removes the address. Flurfunk only accepts addresses of these services:

* Google Firebase Cloud Messaging (Chrome, Edge on Android, Opera, Samsung Internet): [Terms](https://firebase.google.com/terms), [Privacy](https://policies.google.com/privacy)
* Mozilla Push Service (Firefox): [Terms](https://www.mozilla.org/about/legal/terms/services/), [Privacy](https://www.mozilla.org/privacy/firefox/)
* Apple Push Notification service (Safari on macOS, iPhone and iPad): [Terms](https://www.apple.com/legal/internet-services/terms/site.html), [Privacy](https://www.apple.com/legal/privacy/)
* Microsoft Windows Push Notification Services (Edge on Windows): [Terms](https://www.microsoft.com/servicesagreement), [Privacy](https://privacy.microsoft.com/privacystatement)

= Profile pictures (Gravatar) =

Flurfunk shows profile pictures through WordPress's own avatar function. Unless a plugin provides local avatars, WordPress loads them from Gravatar, which receives a hash of the person's email address. Sites with avatars turned off (Settings → Discussion) and people who chose initials in their Flurfunk settings load nothing from there. [Gravatar Terms](https://wordpress.com/tos/), [Privacy](https://automattic.com/privacy/)

== Screenshots ==

1. The overview: channels and posts in the sidebar, the thread list and an open thread.
2. The same in the dark appearance.
3. The board: tasks by state, moved by dragging.
4. A card opened from the board: the thread slides in over the columns.
5. The Conversations panel in the block editor.
6. The notifications behind the bell.
7. A card inserted with a slash command.
8. An image from a message in the lightbox.
9. Full screen, outside wp-admin, with a task thread open next to the list.
10. Flurfunk on a phone.
11. Each person's own settings: appearance, mail, push and quiet hours.

== Changelog ==

= 0.2.0 =
* New: Flurfunk's own capabilities (flurfunk_team, flurfunk_guest, flurfunk_moderate, flurfunk_manage) with a permissions matrix under Settings → Flurfunk → Permissions. Role editor plugins can manage them as well. Sites updating from 0.1 keep who had access: the "team" and "guest" settings are turned into role permissions once.
* New: moderators (flurfunk_moderate; administrators and editors on a fresh site) edit and delete other people's messages. In 0.1 only administrators could.
* New: modules, switchable under Settings → Flurfunk → Modules or with `wp flurfunk module`, with settings of their own under the switch. Core modules: comment cards (/comment with Approve, Spam, Trash), a daily moderation thread (in its own channel #comments for people who may moderate comments) and review requests (channel and who is assigned are settings).
* New: a channel can require a capability on top of its team and guest levels (Settings → Flurfunk → Channels → Also requires). Whoever lacks it does not see the channel, its threads, numbers, notifications or search results and cannot be mentioned there. Export and import carry it.
* New: card building blocks (status, fields, checklist, progress, people, table, badges, quote) and card actions: buttons on a card that run on the site, with an inline confirmation, one click at a time per card and safe retries.
* New for developers: flurfunk_post(), flurfunk_reply(), flurfunk_set_status() and flurfunk_as() to post from PHP; flurfunk_register_module() (with `settings` fields), flurfunk_module_setting() and flurfunk_register_capability(); `capability` for flurfunk_ensure_channel() and `wp flurfunk channel`; the flurfunk_card_action, flurfunk_modules, flurfunk_module_on/off actions and the flurfunk_capabilities, flurfunk_module_enabled, flurfunk_module_setting, flurfunk_bot_can and flurfunk_channel_capabilities filters; window.Flurfunk.cardHelpers.
* Changed: the settings page needs flurfunk_manage (administrators have it) instead of manage_options. Providers without a `capability` are for the team only; guests need `'capability' => 'flurfunk_guest'`.
* Changed: creating assistant accounts needs the right to create users.
* Removed: the MailerPress integration.
* Fixed: imported cards are cleaned like cards made on the site.

= 0.1.0 =
* First version.

== Upgrade Notice ==

= 0.2.0 =
Permissions move to Flurfunk's own capabilities. On the first request after the update, the 0.1 "team" and "guest" settings become role permissions once, so everyone keeps their access. Check Settings → Flurfunk → Permissions afterwards.
