=== Flurfunk ===
Contributors: max-ziebell
Tags: team chat, editorial, collaboration, tasks, comments
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.5.0
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
* Optional AI assistants (WordPress 7.0+): mention one and it answers in the thread, with the rights of the person asking and a monthly budget; it can read the post, look things up and suggest tasks or other actions as cards a person confirms – it never changes anything by itself. Private summaries of long threads.
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

= Can Flurfunk work with AI? =

Yes, with WordPress 7.0 or newer. Set up a provider (Anthropic, Google or OpenAI) under Settings → Connectors, switch on the module "AI assistants" and give an assistant an AI profile under Settings → Flurfunk: what it can do (packs such as proofreading, looking things up or suggesting tasks), its channels, instructions and a monthly token budget. People then mention it in a thread. It reads that thread (and, if allowed, the post it is about), answers there and notifies only the person who asked. Anything that would change the site comes as a card with a button that a person clicks, with their own rights. Who may ask is the capability "Ask AI assistants" (flurfunk_use_ai). What is sent to the provider is described under External services.

= What happens to the conversations when I delete the plugin? =

By default they stay, so a reinstall picks up where you left off. Untick "Keep conversations when the plugin is deleted" under Settings → Flurfunk → Data to remove the tables, the Flurfunk files and the assistant accounts on deletion. Export them first if you want a copy.

== External services ==

Flurfunk talks to outside services only for these features.

= Web Push =

When a person turns on push notifications in their browser, the browser registers with its vendor's push service and gives Flurfunk an address there. From then on, for each notification that person should get (a mention, a task, a reply …), Flurfunk sends an encrypted message to that address; the push service delivers it to the browser. The content (title, a short text, a link into Flurfunk) is end-to-end encrypted for that browser (RFC 8291), so the push service cannot read it; it sees the address, the time and the size of the message, plus the site's administration email address, which the Web Push standard (VAPID, RFC 8292) asks senders to give as a contact. Nothing is sent for people who did not turn push on, and turning it off removes the address. Flurfunk only accepts addresses of these services:

* Google Firebase Cloud Messaging (Chrome, Edge on Android, Opera, Samsung Internet): [Terms](https://firebase.google.com/terms), [Privacy](https://policies.google.com/privacy)
* Mozilla Push Service (Firefox): [Terms](https://www.mozilla.org/about/legal/terms/services/), [Privacy](https://www.mozilla.org/privacy/firefox/)
* Apple Push Notification service (Safari on macOS, iPhone and iPad): [Terms](https://www.apple.com/legal/internet-services/terms/site.html), [Privacy](https://www.apple.com/legal/privacy/)
* Microsoft Windows Push Notification Services (Edge on Windows): [Terms](https://www.microsoft.com/servicesagreement), [Privacy](https://privacy.microsoft.com/privacystatement)

= Profile pictures (Gravatar) =

Flurfunk shows profile pictures through WordPress's own avatar function. Unless a plugin provides local avatars, WordPress loads them from Gravatar, which receives a hash of the person's email address. Sites with avatars turned off (Settings → Discussion) and people who chose initials in their Flurfunk settings load nothing from there. [Gravatar Terms](https://wordpress.com/tos/), [Privacy](https://automattic.com/privacy/)

= AI assistants (optional, off by default) =

Only if a site switches on the module "AI assistants" and sets up an AI provider under Settings → Connectors (WordPress 7.0 or newer). When a person mentions an assistant with an AI profile in a conversation, writes on in a conversation an assistant takes part in, starts a conversation in a channel where an assistant answers every new one or where assistants answer unasked (both set by the site per channel), talks to an assistant in a private conversation, or asks for a summary, and when a check-in or a routine someone asked an assistant for is due, the text of that one conversation (names of the people in it, messages, titles of linked posts and files, the assistant's instructions and, if the site keeps them, the channel's notes) is sent through WordPress's AI client to the provider the site chose, which returns the answer. If the site allows it, the text of the post the conversation is about and what the assistant looks up with its tools (only what the person asking may see) are sent too. Email addresses and phone numbers are replaced before sending unless the site switches that off. Nothing is sent otherwise. The providers WordPress offers:

* Anthropic: [Terms](https://www.anthropic.com/legal/commercial-terms), [Privacy](https://www.anthropic.com/legal/privacy)
* Google (Gemini API): [Terms](https://ai.google.dev/gemini-api/terms), [Privacy](https://policies.google.com/privacy)
* OpenAI: [Terms](https://openai.com/policies/row-terms-of-use/), [Privacy](https://openai.com/policies/row-privacy-policy/)

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

= 0.6.0 (in development) =
* New: an assistant stays in a thread once it has answered (for two days, on by default): anyone there can write on without mentioning it, as with one shared assistant per thread. A quick check with a small, cheap model decides whether a message is for it – an answer, a 👍 for thanks, or nothing for talk between people; "is thinking" only shows once it answers. Follow-ups of the person who asked go through the same check, which makes them cheaper.
* New: a 👎 on an assistant's answer mutes it in that thread until someone mentions it again (as in Claude Tag). Guests reach an assistant in a thread only by mentioning it. Channel notes reach the quick check, so "answer spelling questions without being mentioned" works. The line under an answer names the model that wrote it.
* New: per channel, a limit that caps every assistant there on top of its own (e.g. so it cannot quote internal drafts in a channel guest authors read), and a monthly token budget shared by the assistants in that channel.
* New: check-ins on request – "remind us on Friday whether the photo rights are settled": the assistant follows up in the thread at that time, asks whether it is done and mentions who should answer (who is then notified), or says it is settled. At most five per thread, 60 days ahead; it does not nag on its own. Ability flurfunk/follow-up.
* New: notes per channel (off by default): ask an assistant to remember something ("teasers at most 200 characters") and every assistant in that channel knows it from then on; "it is 180 now" changes it. Only on request, only within the channel, never in private conversations; administrators see and remove the notes per channel. Ability flurfunk/remember.
* New: per channel, "Assistants answer new threads here unasked" (off by default, as Claude Tag's "Respond automatically"): a new thread that mentions nobody goes through the quick check, and an assistant that can clearly help answers in two or three sentences; most threads get nothing. A 👎 mutes it in that thread.
* New: routines – "every Friday at 15:00 sum up in a new thread what is still open in this channel": the assistant sets it up and confirms the schedule; when due it does the task with the rights of the person who set it up and posts the result in the thread or as a new thread, or nothing with nothing to report. "Pause it", "stop it" work the same way; administrators see and remove routines per channel. At most ten per channel. Ability flurfunk/routine.
* New: while an assistant works, the thread ticks off what it has done so far (read a post, searched, wrote a draft), as a short checklist under "… is writing".
* Changed: in a channel guests can read, an assistant looks things up only in that channel, so internal conversations do not end up in front of guests.
* New: "Models for the quick check" in the AI settings – any model your providers offer (empty: a small one).
* New: the AI log shows the quick checks (for it, thanks, not for it) with model and tokens.
* Fixed: deleting the plugin with "keep data" off now also removes the AI assistants' settings.

= 0.5.0 =
* New: limits for AI assistants. An assistant can never do more than the person asking, and it never administers the site (users, plugins, themes, settings, unfiltered HTML are off while it works). "May at most act as" sets a role as a ceiling on top – also for what it reads, e.g. no other people's drafts within the limit Author. Proposals it may not make within the limit are not offered; a card runs within the limit too. The assistant says when its limit may hide something.
* New: AI assistants can write drafts (pack "Drafts", off by default): "make an article of this thread, with the photo" gives a draft – the person asking is its author, images from the conversation go along (into the media library) – and a card under the answer. Follow-ups change the draft until someone else works on it. It never publishes. Abilities flurfunk/create-draft and flurfunk/update-draft.
* New: changes to existing posts as one card (pack "Changes to posts", off by default): corrections in the text, titles, excerpts, categories, tags and featured images, for several posts at once – before and after per post, untick what you do not want, apply with your rights, undo afterwards. A post someone changed in the meantime is skipped, as is one whose markup saving would lose (e.g. an embed). Ability flurfunk/update-posts.
* New for developers: card actions receive what the person chose on the card (inputs with data-card-input) as a fourth argument.
* Changed: as in Slack, an assistant that is only thanked reacts with 👍 instead of writing; mentioned only in passing, it reacts with 👀. After a mention it never stays silent; a follow-up not meant for it still gets nothing.
* Changed: flurfunk/start-thread and flurfunk/reply check the channel or conversation in their permission check, not only when they run.

= 0.4.0 =
* Changed: a channel reads top to bottom, as in Slack – the oldest thread first, the newest right above the writing box; the day sits on a line between days and stays at the top while you scroll through it. A thread keeps its place when someone replies; older threads load at the top; scrolled up, a button brings you back down and counts new threads. All threads, a post's conversations and private conversations read the same way; mentions, tasks, search and the board stay lists by latest activity.
* New: emoji reactions on threads and replies, as in Slack – one smiley in the hover bar opens a picker with the most used emoji first and all others with a search (English and German names); capsules with counts, click to add yours or take it back. Nobody is notified.
* New: private conversations with an AI assistant – under "Assistants" in the sidebar; it answers every message there; only the person who started a conversation sees it in Flurfunk (not the team, not administrators); each conversation starts fresh.
* New for developers: `order=new`, `before_id` and `private_with` for the thread list, `POST threads/<id>/reactions`, `private_with` for new threads; the flurfunk_private_partners and flurfunk_reactions_per_minute filters and the flurfunk_reaction action.
* Emoji names in the picker: Emojibase (MIT, emojibase.dev).

= 0.3.0 =
* New: AI assistants (module, off by default; WordPress 7.0+ and a provider under Settings → Connectors). An assistant with an AI profile answers in the thread when someone mentions it, in the channels it is allowed in, with instructions per assistant and channel and a monthly token budget. It can read the post a thread is about, look things up with read-only abilities and propose actions that a person runs with a button – always with the rights of the person asking or clicking. Privacy switches: email addresses and phone numbers are replaced before sending, a log without content, a capability for who may ask.
* New: what an assistant can do is set as packs (proofreading, looking things up, tasks, SEO and teasers, images, tech) that say what each reads or suggests; packs the site cannot offer say what they need. Single abilities and the model stay behind "Single abilities and model …".
* New: AI answers carry a spark avatar, an "AI" label and a line with what they were based on and who was notified; while an assistant works, the thread shows what it is doing. Proposal cards say in plain words what would happen ("Task: …") and name the button after it. A channel's header explains which assistants take part and what they read.
* New: "Summarize" gives a private summary of a thread; threads with many unread messages offer one of just those.
* New: abilities for the WordPress Abilities API and MCP: list channels, list and read conversations (public), reply and start conversations (not public by default).
* New: AI assistants can find and read posts – "proofread the latest article" or "the article about …" works. Two new abilities, flurfunk/find-posts and flurfunk/get-post, with the rights of the person asking (drafts only for those who may edit them, guests only their own posts).
* New: right after an assistant's answer you can simply write on – a follow-up without a new mention goes to it; if it needs no answer ("thanks"), the assistant stays quiet. Developers can switch this off with flurfunk_ai_follow_ups.
* Changed: as in Slack, Enter sends and Shift+Enter makes a new line (Ctrl/Cmd+Enter still sends; on touch screens Enter makes a new line).
* Changed: one icon set instead of text links and characters – in the sidebar, the thread's header (follow, mute, remind, summarize, close), the writing box (file, media library, @, /, send) and on messages, whose edit and delete now sit in a small bar on hover, with tooltips as in Slack.
* Changed: in a thread the writing box stays at the bottom; scrolled up, a button brings you back to the newest message and counts what arrived meanwhile, as in Slack. Lists and threads show grey placeholders while they load and fade in; new replies slide in.
* Changed: the app asks the server in the language of the person using it, and mails and pushes go out in the recipient's language.
* Changed: Enter or Tab takes the first suggestion in the @ and / lists, as in Slack.
* Changed: long messages are folded ("Show more"); an AI answer notifies only the person who asked.
* Changed: assistants show no WordPress role next to their name.
* New for developers: the flurfunk_thread_view, flurfunk_channel_view and flurfunk_ai_packs filters and the flurfunk_settings_sections action.

= 0.2.1 =
* New for developers: module settings of the type `users` (pick people, optionally only those with a capability).
* Changed: review requests are closed when the post is moved to the trash or deleted.

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

= 0.5.0 =
AI assistants get limits (never more than the person asking, never administering the site) and, if you switch it on per assistant, can write drafts and propose changes to posts as one card you can undo. As in Slack, a thanks gets a thumbs-up reaction instead of a reply.

= 0.4.0 =
Channels and all threads now read top to bottom as in Slack, the newest right above the writing box, with the date at the top while you scroll. New: emoji reactions and private conversations with an AI assistant. Flurfunk updates its tables by itself.

= 0.3.0 =
New: optional AI assistants (WordPress 7.0+, off by default), Flurfunk abilities for the Abilities API and MCP, icons instead of text links. As in Slack, Enter now sends (Shift+Enter: new line). Mails and pushes go out in each recipient's language.

= 0.2.1 =
Small update: module settings can pick people, and review requests close when their post is trashed or deleted.

= 0.2.0 =
Permissions move to Flurfunk's own capabilities. On the first request after the update, the 0.1 "team" and "guest" settings become role permissions once, so everyone keeps their access. Check Settings → Flurfunk → Permissions afterwards.
