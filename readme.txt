=== Flurfunk ===
Contributors: maxziebell
Tags: team chat, editorial, collaboration, tasks, comments
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Team conversations inside WordPress: channels, threads and tasks, attached to posts, with mentions, files and cards from other plugins.

== Description ==

Flurfunk brings the conversations of an editorial team into wp-admin, next to the work they are about.

* Channels and threads, every thread can be a task with a tick box.
* Threads can hang on a post (or anything a plugin registers) and show up in the editor's "Conversations" panel and in the posts list.
* @mentions, simple formatting, files with previews and players.
* Slash commands insert cards from other plugins: posts, statistics, newsletters …
* Guests (e.g. guest authors) see only what concerns their own posts.
* Files stay out of the media library until someone adds them – without copying or moving.
* Tasks with due dates and responsible people, "My tasks", reminders.
* Notifications: in-app list, mail (instant or daily summary), Web Push to browsers and phones, quiet hours, follow or mute threads.
* Light and dark, everything each person sets for themselves.
* Assistant accounts for agents and scripts, clearly marked in the conversation.
* Works in plain wp-admin and inside the OpenStation desktop.
* Own tables, one JSON format for export and import (with files), privacy export/erase.

Developers extend Flurfunk with providers (slash commands and cards), targets and filters. See the README in the repository.

== Installation ==

1. Upload the plugin and activate it.
2. Open Settings → Flurfunk: name, logo, colours, who counts as team or guest, channels.
3. Open Flurfunk in the admin menu.

== Frequently Asked Questions ==

= Where are files stored? =

In uploads/flurfunk/, as normal attachments that the media library hides. "Add to media library" only makes them visible there; the file keeps its address.

= Can scripts or AI agents post? =

Yes, as an assistant account: `wp flurfunk bot-create`, then `wp flurfunk post` or the REST API with an application password.

== Changelog ==

= 0.1.0 =
* First version.
