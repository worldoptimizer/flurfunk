# Developer guide

How Flurfunk is built, how to talk to it, and how to extend it. Complete, copy-paste code is on
the [examples](examples.md) page.

- [Architecture](#architecture)
- [Data model](#data-model)
- [Data format flurfunk/1](#data-format-flurfunk1)
- [REST API](#rest-api)
- [Permissions](#permissions)
- [PHP functions](#php-functions)
- [Providers: slash commands and cards](#providers-slash-commands-and-cards)
- [Card building blocks](#card-building-blocks)
- [Card actions](#card-actions)
- [Custom card renderers](#custom-card-renderers)
- [Modules](#modules)
- [Targets](#targets)
- [Embedding](#embedding)
- [Hooks](#hooks)
- [WP-CLI](#wp-cli)
- [Posting as an assistant from scripts and CI](#posting-as-an-assistant-from-scripts-and-ci)
- [Working on Flurfunk itself](#working-on-flurfunk-itself)

## Architecture

Flurfunk is plain PHP and plain JavaScript. There is no build step and no framework. The
interface is drawn in the browser from the REST API.

| File | Namespace | Job |
| --- | --- | --- |
| `flurfunk.php` | global | Bootstrap, the public functions `flurfunk_*()` |
| `inc/schema.php` | `Flurfunk\Schema` | Own tables, versioned (`DB_VERSION`), upgrade steps |
| `inc/access.php` | `Flurfunk\Access` | Team and guests, channel levels, who sees what, assistants |
| `inc/caps.php` | `Flurfunk\Caps` | Capabilities, the 0.1 migration, the permissions matrix, declared capabilities |
| `inc/store.php` | `Flurfunk\Store` | Reading and writing, the data format |
| `inc/providers.php` | `Flurfunk\Providers` | Slash commands and cards, built-in `/post` |
| `inc/targets.php` | `Flurfunk\Targets` | What threads can be attached to, built-in `post` |
| `inc/files.php` | `Flurfunk\Files` | Flurfunk files, hidden from the media library, cleanup |
| `inc/privacy.php` | `Flurfunk\Privacy` | Personal data export and erase, user deletion |
| `inc/settings.php` | `Flurfunk\Settings` | *Settings → Flurfunk* |
| `inc/notify.php` | `Flurfunk\Notify` | Notifications: in-app list, mail, digest, push, reminders, preferences |
| `inc/push.php` | `Flurfunk\Push` | Web Push (VAPID, aes128gcm) without external libraries |
| `inc/rest.php` | `Flurfunk\Rest` | REST API `flurfunk/v1` |
| `inc/admin.php` | `Flurfunk\Admin` | Admin page, editor panel, posts column, OpenStation icon |
| `inc/transfer.php` | `Flurfunk\Transfer` | JSON export and import |
| `inc/data.php` | `Flurfunk\Data` | Export and import under *Settings → Flurfunk*, the welcome after the first activation |
| `inc/standalone.php` | `Flurfunk\Standalone` | The full-screen app outside wp-admin (`/flurfunk/`) |
| `inc/cli.php` | `Flurfunk\Cli` | `wp flurfunk …` |
| `inc/strings.php` | `Flurfunk\Strings` | Translatable strings of the JavaScript |
| `inc/modules.php` | `Flurfunk\Modules` | Module register: optional parts, switched under *Settings → Flurfunk* |
| `modules/comments.php` | `Flurfunk\Modules\Comments` | `/comment` cards with moderation buttons |
| `modules/comments-daily.php` | `Flurfunk\Modules\CommentsDaily` | Daily thread about comments awaiting moderation |
| `modules/review.php` | `Flurfunk\Modules\Review` | Review requests as tasks |
| `assets/app.js` | `window.Flurfunk` | The interface |
| `assets/editor.js` | | Block editor "Conversations" panel |
| `assets/sw.js` | | Service worker for push (controls no pages) |

The functions in the namespaces are internal. The stable extension points are the global
functions, the provider and target registries, the hooks, the REST API, the data format and
`window.Flurfunk`.

### Request flow

1. The app loads `GET bootstrap` once (who am I, people, channels, providers, counts, look).
2. Lists and threads come from `GET threads` and `GET threads/<id>`.
3. Writes go through `Flurfunk\Store`, which checks permissions and fires actions.
4. `Flurfunk\Notify` listens to those actions, works out who gets what, and stores and delivers
   notifications at the end of the request (on `shutdown`).
5. The app polls `GET counts` and the current list every 30 seconds while visible.

Unread numbers are cached per person in transients and invalidated through revision counters
(option `flurfunk_rev`, user meta `flurfunk_rev`).

## Data model

All tables are prefixed `{$wpdb->prefix}flurfunk_`.

| Table | Rows | Key columns |
| --- | --- | --- |
| `channels` | channels | `slug` (unique), `name`, `topic`, `access_team`, `access_guests`, `capability` (required on top of the levels, `''` = none; since DB version 6), `position`, `archived` |
| `threads` | threads (the first post) | `channel_id`, `ref_key`, `target_type`, `target_id`, `title`, `body`, `author_id`, `status` (`''`, `open`, `doing`, `waiting`, `done`), `due_date`, `resolved_by`, `resolved_at`, `created`, `last_activity` |
| `messages` | replies and log lines | `thread_id`, `type` (`message`, `status`, `event`, `deleted`), `author_id`, `body`, `status`, `files` (JSON), `created`, `edited` |
| `mentions` | who was mentioned where | `thread_id`, `message_id` (0 = the thread itself), `user_id` |
| `reads` | per person and thread | `last_message_id`, `seen`, `follow` (1 follow, -1 mute, 0 default), `remind_at` |
| `attachments` | cards | `thread_id`, `message_id`, `provider`, `ref_key`, `ref` (JSON), `snapshot` (JSON) |
| `assignees` | responsible people | `thread_id`, `user_id`, `assigned_by` |
| `notifications` | the bell list | `user_id`, `thread_id`, `message_id`, `kind`, `actor_id`, `read_at`, `sent` |
| `push` | browser subscriptions | `user_id`, `endpoint`, `p256dh`, `auth`, `label`, `last_ok`, `failures` |

All times are stored in UTC. Text is stored as plain text with mentions as `<@ID>` and is always
escaped on output.

Other storage:

| Where | Key | Content |
| --- | --- | --- |
| option | `flurfunk_settings` | Site settings (see `flurfunk_settings` filter) |
| option | `flurfunk_db_version` | Schema version |
| option | `flurfunk_modules` | Module switches: `id => bool` |
| option | `flurfunk_module_settings` | Module settings: `id => { key => value }` (see [Module settings](#module-settings)) |
| option | `flurfunk_rev` | Global revision counter |
| option | `flurfunk_vapid` | Push key pair |
| option | `flurfunk_bot_default` | Default assistant for `wp flurfunk post` |
| user meta | `flurfunk_prefs_<key>` | Personal preferences: `theme`, `avatar`, `mail`, `push`, `quiet`, `badge`, `hour`, `channels`, `pause` |
| user meta | `flurfunk_bot` | Marks an assistant account |
| user meta | `flurfunk_rev`, `flurfunk_digest_last` | Per-person revision, time of the last digest |
| post meta | `_flurfunk_file` | Marks an attachment as a Flurfunk file (hidden from the media library) |
| transients | `flurfunk_counts_*`, `flurfunk_rate_*` | Cached counts, rate limit counters |

Files are normal attachments stored under `uploads/flurfunk/<year>/<month>/<random>/`.

## Data format flurfunk/1

The REST API, the export and the import use the same shapes.

- Times are ISO 8601 in UTC.
- People are user IDs. Mentions in text are `<@ID>`.
- `null` means "not set".

### Export file

```json
{
  "format": "flurfunk/1",
  "exported": "2026-10-17T08:00:00+00:00",
  "site": "https://example.com/",
  "channels": [
    {
      "id": "general",
      "name": "General",
      "topic": "Anything that concerns everyone",
      "access": { "team": "all", "guests": "none" },
      "capability": "",
      "position": 100,
      "archived": false
    }
  ],
  "people": [
    { "id": 1, "name": "Ada Admin", "email": "ada@example.com", "kind": "team" },
    { "id": 7, "name": "Ben Editor", "email": "ben@example.com", "kind": "team" }
  ],
  "threads": [
    {
      "id": 12,
      "channel": "general",
      "ref_key": null,
      "target": { "type": "post", "id": 345 },
      "title": "Teaser image",
      "body": "Can <@7> find a better picture?",
      "author": 1,
      "created": "2026-10-15T09:12:00+00:00",
      "status": "open",
      "resolved": null,
      "mentions": [ 7 ],
      "attachments": [
        {
          "id": 3,
          "provider": "flurfunk/post",
          "ref": { "id": 345 },
          "snapshot": {
            "title": "Autumn issue",
            "subtitle": "Draft · Ada Admin",
            "url": "https://example.com/wp-admin/post.php?post=345&action=edit",
            "target": { "type": "post", "id": 345 }
          }
        }
      ],
      "due": "2026-10-20",
      "assignees": [ 7 ],
      "messages": [
        {
          "id": 40,
          "type": "message",
          "author": 7,
          "created": "2026-10-15T10:01:00+00:00",
          "text": "Here are two options.",
          "mentions": [],
          "files": [
            { "id": 501, "name": "option-a.jpg", "size": 182311, "type": "image/jpeg",
              "url": "https://example.com/wp-content/uploads/flurfunk/2026/10/k3j…/option-a.jpg",
              "title": "option-a", "library": false, "bundle": "files/501-option-a.jpg" }
          ],
          "attachments": [],
          "edited": null
        },
        { "id": 41, "type": "event", "author": 1, "created": "2026-10-15T10:05:00+00:00", "event": "due", "due": "2026-10-20" },
        { "id": 42, "type": "event", "author": 1, "created": "2026-10-15T10:05:10+00:00", "event": "assign", "added": [ 7 ], "removed": [] },
        { "id": 43, "type": "status", "author": 7, "created": "2026-10-16T08:30:00+00:00", "status": "done" },
        { "id": 45, "type": "event", "author": 7, "created": "2026-10-16T08:30:30+00:00", "event": "card_action", "card": 3, "provider": "flurfunk/comment", "action": "approve", "label": "Approve", "summary": "Ana approved the comment", "title": "Comment by Eve" },
        { "id": 44, "type": "deleted", "author": 7, "created": "2026-10-16T08:31:00+00:00" }
      ],
      "read": { "1": 44, "7": 43 }
    }
  ]
}
```

### Thread

| Field | Type | Meaning |
| --- | --- | --- |
| `id` | int | Thread ID |
| `channel` | string | Channel slug |
| `ref_key` | string or null | External key set by an embedding plugin or script, e.g. `checklist:billing` |
| `target` | `{type, id}` or null | What the thread is attached to |
| `title` | string or null | Optional title |
| `body` | string | Text of the first post |
| `author` | int | User ID (0 = removed) |
| `created` | ISO time | |
| `status` | `open`, `doing`, `waiting`, `done` or null | Task state, see [task states](#task-states); null = not a task |
| `resolved` | `{by, at}` or null | Who ticked it off and when |
| `mentions` | int[] | People mentioned in the first post |
| `attachments` | card[] | Cards on the first post |
| `due` | `Y-m-d` or null | Due date |
| `assignees` | int[] | Responsible people |
| `messages` | message[] | Export and `GET threads/<id>` only |
| `read` | object | Export only: user ID → last message ID read |

### Message

| `type` | Fields |
| --- | --- |
| `message` | `id`, `author`, `created`, `text`, `mentions[]`, `files[]`, `attachments[]`, `edited` |
| `status` | `id`, `author`, `created`, `status` (`open`, `doing`, `waiting`, `done`, `none`) |
| `event` | `id`, `author`, `created`, `event`: `due` with `due`; `assign` with `added[]`, `removed[]`; `card_action` with `card` (card ID), `provider`, `action`, `label`, `summary` ("Ana approved", in the language of the person who clicked) and `title` (the card's title) |
| `deleted` | `id`, `author`, `created` |

### Card (attachment)

| Field | Meaning |
| --- | --- |
| `id` | Card row ID (used for `cards/<id>/refresh`) |
| `provider` | Provider ID, e.g. `flurfunk/post` |
| `ref` | The provider's reference, e.g. `{"id": 12}` |
| `snapshot` | The card data, see [snapshot fields](#snapshot-fields) |

### Task states

| State | Shown as | Meaning |
| --- | --- | --- |
| `open` | Open | To do |
| `doing` | In progress | Someone is working on it |
| `waiting` | Question | Needs an answer before it can go on; notifies the author and the responsible people |
| `done` | Done | Ticked off; `resolved` says by whom and when |

`open`, `doing` and `waiting` count as active: they appear in "My tasks", "Open tasks", the task
counts and the due reminders. Changing the state adds a `status` message to the thread. A thread
with status null is not a task.

### File

Stored as `{id, name, size, type}`. A message refers to the attachment; nothing is copied, so a
file from the media library stays where it is. On output Flurfunk adds `url`, `title`, `library`
(false = still a hidden Flurfunk file), and for images `preview` (medium large), `thumb`
(thumbnail), `width`, `height`. A file deleted from the
media library gets `missing: true`. Bundle exports add `bundle` (path inside the folder). Files
imported without a bundle have `id: 0` and keep the `url` of the old site.

## REST API

Base: `/wp-json/flurfunk/v1/`. Every route needs a logged-in user who is team or guest
(including assistants). Inside wp-admin the app authenticates with the cookie and an
`X-WP-Nonce` header; scripts use an application password (HTTP Basic auth).

Request bodies are JSON (`Content-Type: application/json`), except file uploads.

### Routes

| Method | Route | Parameters | Returns |
| --- | --- | --- | --- |
| GET | `bootstrap` | | Everything the app needs to start, see below |
| GET | `counts` | | Unread numbers, see below |
| GET | `threads` | `view` (`all`, `mentions`, `tasks`, `mine`, `board`), `channel` (slug), `target` (`type:id`), `person` (user ID: only tasks this person is responsible for), `ref_prefix`, `q` (search), `before` (ISO time, for paging by last activity), `before_id` (thread ID, for paging with `order=new`), `private_with` (an assistant's user ID: one's own private conversations with it), `limit` (1–200, default 40), `order` (`ref` = oldest first, `new` = newest started first, as a channel shows them) | Thread summaries |
| POST | `threads` | `channel` (slug, required), `title`, `body`, `target` `{type, id}`, `task` (bool; implied by `due` or `assignees`), `due` (`Y-m-d`), `assignees` (user IDs), `attachments` (`[{provider, ref}]`), `ref_key` | The new thread (full). With a `ref_key` that exists already: that thread, unchanged |
| GET | `threads/<id>` | `peek=1` to not mark it as read | Full thread; marks it and its notifications as read |
| POST | `threads/<id>/messages` | `body`, `files` (attachment IDs: uploaded Flurfunk files or media library files, up to 50), `attachments` | The full thread |
| POST | `threads/<id>/status` | `status`: `open`, `doing`, `waiting`, `done`, or `''` (no longer a task) | The full thread |
| POST | `threads/<id>/edit` | `title`, `body` | The full thread |
| POST | `threads/<id>/task` | `due` (`Y-m-d` or `''`), `assignees` (user IDs, replaces the list) | The full thread |
| POST | `threads/<id>/follow` | `mode`: `follow`, `mute` or `default` | `{follow: 1, -1 or 0}` |
| POST | `threads/<id>/remind` | `when`: `hour`, `tomorrow`, `monday`, `none` or an ISO time within a year | `{remind: ISO time or null}` |
| POST | `messages/<id>` | `body` | The full thread |
| DELETE | `messages/<id>` | | The full thread (the message becomes a `deleted` note) |
| GET | `providers/<vendor>/<name>/search` | `q` | `[{ref, title, subtitle}]`, at most 20 |
| GET | `targets` | `q` | `[{type, id, title, subtitle, label}]` across all target types, at most 30 |
| POST | `cards/<id>/refresh` | | The full thread with a fresh snapshot |
| POST | `cards/<id>/action/<action>` | `key` (optional idempotency key, up to 64 of `A-Za-z0-9_-`; also as header `X-Flurfunk-Key`) | The full thread with the new snapshot and the event line. See [card actions](#card-actions) |
| POST | `files` | Raw file as body, `Content-Disposition: attachment; filename="name.ext"`, `Content-Type` | The file `{id, name, size, type, url, …}` |
| POST | `files/<id>/adopt` | | The file, now in the media library |
| POST | `me/prefs` | Any of `theme`, `avatar`, `mail`, `push`, `quiet`, `badge`, `hour`, `pause` (`1h`, `3h`, `tomorrow`, `none`), `channel` `{slug, level}` | All preferences |
| GET | `notifications` | `before` (notification ID), `limit` (1–100, default 30) | The person's notifications, newest first |
| POST | `notifications` | `ids` (array) or `all: true` | Counts |
| POST | `read` | `channel` (optional) | `{marked}` plus counts |
| POST | `me/push` | `subscription` `{endpoint, keys: {p256dh, auth}}`, `label` | `{devices}` |
| DELETE | `me/push` | `endpoint` | `{devices}` |
| POST | `me/push/test` | | `{sent: bool}` |

Two admin-ajax actions belong to the app: `flurfunk_manifest` (web app manifest, logged in) and
`flurfunk_push_renew` (the service worker replaces a rotated push subscription; authenticated by
the old endpoint).

### Responses

A **thread summary** (from `GET threads`) is the thread shape above plus:

| Field | Meaning |
| --- | --- |
| `replies` | Number of replies (`message` type only) |
| `participants` | User IDs who replied |
| `last` | `{author, created}` of the last reply |
| `activity` | Last activity (use it as `before` for the next page) |
| `unread` | Unread replies for you (+1 if you never opened the thread and did not start it) |
| `audience` | Guest names who can read it (or "Guests"), shown to the team |
| `target` | Extended with `title`, `url`, `status`, `label` |

A **full thread** (from `GET threads/<id>` and all write routes) has `messages` (the newest 300,
see `flurfunk_thread_limit`) plus:

| Field | Meaning |
| --- | --- |
| `older` | Messages not included |
| `read` | Last message ID you read (-1 = never opened) |
| `follow` | 1 follow, -1 mute, 0 default |
| `remind` | Your reminder (ISO) or null |
| `audience` | As above |

Views of `GET threads`:

| `view` | Threads | Order |
| --- | --- | --- |
| `all` (default) | everything visible | last activity |
| `mentions` | where you were mentioned | last activity |
| `tasks` | active tasks (`open`, `doing`, `waiting`) | due date (none last), then activity |
| `mine` | active tasks you are responsible for | as `tasks` |
| `board` | active tasks plus tasks done in the last 14 days (filter `flurfunk_board_done_days`) | as `tasks` |

The board in the app asks for `view=board&limit=200`, optionally with `channel` and `person`.

**`GET counts`**: `{total, mentions, tasks, mine, overdue, channels: {slug: n}, targets: {"type:id": n}, notifications}`.
`tasks`, `mine` and `overdue` count active tasks.

**`GET bootstrap`**: `format`, `me` (person plus `prefs`, `manage`, `upload`), `people`,
`channels` (with your `level`; a channel that requires a capability also lists the IDs of the people
who can read it as `members`, which the app uses for mention and assignee suggestions), `providers` (`id`, `command`, `label`, `hint`, `hidden`, `actions`:
`{id: {label, done, style, confirm, can}}`), `counts`,
`targets` (up to 15 you can attach to), `recent` (targets with recent conversations), `locale`,
`look` (`name`, `logo`, `accent`, `sidebar`), `settings` (settings URL for admins), `push`
(`available`, `key`, `devices`).

A **person** is `{id, name, role, kind, bot, avatar}`.

### Errors

Errors are standard WordPress REST errors: `{"code": "…", "message": "…", "data": {"status": …}}`.

| Code | Status | When |
| --- | --- | --- |
| `flurfunk_channel` | 400 | Unknown channel slug |
| `flurfunk_empty` | 400 | No text, title, file or card |
| `flurfunk_invalid` | 400 | Reminder time not within the next year |
| `flurfunk_forbidden` | 403 / 404 | Not allowed, or not visible (404 hides that it exists) |
| `flurfunk_card` | 409 | The provider no longer returns a snapshot |
| `flurfunk_action` | 404 | Unknown card action |
| `flurfunk_action_gone` | 409 | The card does not offer this action (any more), e.g. someone else was faster |
| `flurfunk_action_failed` | 400 / 500 | The action's callback returned an error (its message) or threw (a generic message) |
| `flurfunk_rate` | 429 | Rate limit reached |
| `flurfunk_upload` | 400 / 403 / 413 | Empty file, no upload right, file too large |
| `flurfunk_file` | 404 | Not a Flurfunk file (adopt) |
| `flurfunk_provider` | 404 | Unknown or forbidden provider (search) |
| `flurfunk_push` | 400 | Invalid push subscription |
| `flurfunk_db` | 500 | Database write failed |

## Permissions

| Check | Rule | Filter |
| --- | --- | --- |
| Uses Flurfunk at all | kind is `team` or `guest` | `flurfunk_user_kind` |
| Kind | assistant or `flurfunk_team` → team; `flurfunk_guest` → guest | `flurfunk_user_kind` |
| Channel level | archived → `none`; lacks the channel's `capability` → `none`; otherwise the channel's team or guest level | `flurfunk_channel_level` (runs last), `flurfunk_bot_can` |
| Sees a thread | level `all`; or `own-posts` and the thread is attached to one of their posts | `flurfunk_owned_post_ids` |
| Starts a thread | as "sees", plus may attach to the chosen target | `flurfunk_can_target` |
| Attaches to a target | the target type's `can` callback (default: team, and the target exists) | `flurfunk_can_target` |
| Edits or deletes | author or `flurfunk_moderate` | `flurfunk_can_edit` |
| Changes task state, due date, assignees | sees the thread | `flurfunk_can_resolve` |
| Is mentioned or assigned | only people who can see the thread | |
| Inserts, searches or refreshes a card | the provider's `capability` (default `flurfunk_team`), its `can`, and `snapshot` must not return null | |
| Uploads | `upload_files` or assistant | |
| Attaches a media library file by ID | their own files and files they may edit (`edit_post`); assistants any file not attached to a private post | `flurfunk_can_attach` |
| Manages settings, channels, permissions, modules, assistants, export and import | `flurfunk_manage`; creating an assistant account also needs `create_users` | |

`own-posts` only ever applies to the target type `post`.

### Channels that require a capability

A channel can require a capability on top of its levels (*Settings → Flurfunk → Channels → Also
requires*, `ensure_channel()`'s `capability`, `wp flurfunk channel --capability=…`). Whoever lacks
it gets the level `none` there: the channel is not listed, its threads do not open, are not
counted, found or listed, notifications about them disappear from the list, the unread number and
the digest, nobody without it can be mentioned or assigned there, and nobody without it can start
a thread or reply. It is checked once, in `Flurfunk\Access\level()`, so `can_see()`, `can_start()`
and `where()` (every list and count) follow; `flurfunk_channel_level` runs after it and can still
open or close a channel per person.

| Capability | Who passes |
| --- | --- |
| `''` | everyone (the default) |
| `flurfunk_team` | the team, assistants included; guests never |
| `flurfunk_guest` | everyone who uses Flurfunk (team, guests, assistants) |
| anything else | people with it (`user_can()`); assistants only if their account has it, while PHP code acts as them, or if `flurfunk_bot_can` returns true |

**Assistants.** Code on the site that posts as the assistant (`flurfunk_post()`, `flurfunk_reply()`,
`flurfunk_set_status()`, anything inside `flurfunk_as()`) always passes: that is the site acting,
so a module can post into a gated channel. An agent that logs in as the assistant with an
application password does not, unless the account itself has the capability or you allow it:

```php
// Let assistants read and post in channels that require moderate_comments.
add_filter( 'flurfunk_bot_can', fn ( $ok, $cap ) => $ok || 'moderate_comments' === $cap, 10, 2 );
```

The choices in the settings are Flurfunk's capability registry (its own and declared ones) and
`moderate_comments`, `edit_others_posts`, `publish_posts`, `manage_options`; adjust the list with
`flurfunk_channel_capabilities`. A capability set in code that is not in the list is kept.

## Capabilities

Flurfunk checks only its own capabilities (and WordPress's `upload_files` for files). They are
normal primitive capabilities granted to roles, so role editor plugins such as Members or User Role
Editor can hand them out like any other, also to single users. Administrators manage them under
*Settings → Flurfunk → Permissions*.

| Capability | Allows | Fresh site |
| --- | --- | --- |
| `flurfunk_team` | Takes part as team: sees channels according to their team level, attaches threads to any post, uses team providers. | administrator, editor |
| `flurfunk_guest` | Takes part as a guest: sees channels according to their guest level (usually only threads about their own posts). Team wins if someone has both. | author, contributor |
| `flurfunk_moderate` | Edits and deletes other people's messages and threads. | administrator, editor |
| `flurfunk_manage` | Settings page, channels, permissions, modules, assistants, export and import. | administrator |

There is no `flurfunk_use`: someone uses Flurfunk when they are team or guest. Assistant accounts
count as team without holding `flurfunk_team`. `flurfunk_user_kind` and `flurfunk_channel_level`
still have the last word. In code, ask `Flurfunk\Access\kind()`, `can_use()`, `can_manage()` and
`can_moderate()` rather than the capabilities, so assistants and filters are respected.

**Grants.** Activation (and the first request after an update, once per site; on a network each
site on its first request) grants the defaults above. The version is kept in the option
`flurfunk_caps_version`; nothing is granted again later, so whatever the site changes stays.
*Settings → Flurfunk → Permissions* never takes `flurfunk_manage` away from administrators, from the
last role that has it or from the role of the person saving; if a role editor removes it from every
role, administrators get it back.

**Migration from 0.1.** 0.1 chose a WordPress capability for the team (`team_cap`, default
`edit_others_posts`) and one for guests (`guest_cap`, default `edit_posts`) in the settings.
On the first request with 0.2 a site that ran 0.1 (it has Flurfunk settings or channels) gets,
once:

- `flurfunk_team` for every role with the team capability,
- `flurfunk_guest` for every other role with the guest capability (none if guests were off),
- `flurfunk_manage` and `flurfunk_moderate` for every role with `manage_options` (in 0.1 only they
  could edit other people's messages; editors do not get `flurfunk_moderate` here, unlike on a fresh
  site).

Values set through the `flurfunk_settings` filter are read for this too. Afterwards `team_cap` and
`guest_cap` are removed from the settings and no longer read. Capabilities granted to single users
(not roles) are not migrated.

**Uninstall** removes Flurfunk's capabilities from all roles on every site, whether or not the
conversations are kept; declared capabilities (below) only if no role had them before Flurfunk
granted them. A reinstall grants them again (a site with kept data is migrated as above).

### Declaring capabilities

Modules, providers and plugins can declare capabilities of their own. They appear in the
permissions matrix, and their recommended roles get them once, unless the site already decided
(some role has the capability already, or the matrix was saved with it). After that Flurfunk never
grants them again.

```php
add_action( 'init', function () {
	flurfunk_register_capability( 'acme_send_newsletter', array(
		'label'       => 'Send newsletters',
		'description' => 'May send a campaign from its card.',
		'roles'       => array( 'administrator', 'editor' ), // recommended
		'group'       => 'Acme',                             // heading in the matrix
	) );
} );

// In a module: granted once the module is switched on; switching it off keeps the grants.
flurfunk_register_module( 'acme/newsletter', array(
	'label'        => 'Newsletter cards',
	'load'         => fn () => require __DIR__ . '/newsletter.php',
	'capabilities' => array(
		'acme_send_newsletter' => array( 'label' => 'Send newsletters', 'roles' => array( 'administrator', 'editor' ) ),
	),
) );

// In a provider: `capability` is what the command needs, `capabilities` declares new ones.
flurfunk_register_provider( 'acme/newsletter', array(
	'command'    => 'newsletter',
	'snapshot'   => 'acme_newsletter_card',
	'capability' => 'acme_send_newsletter',
) );
```

Names are lower-case letters, digits, `_` and `-`; Flurfunk's own four cannot be declared again.
Capabilities of a module that is off and that no role has are left out of the matrix. The
`flurfunk_capabilities` filter adjusts the whole registry (`cap => label, description, roles,
group, module`). Check a declared capability like any other with `user_can()`.

### For integrators upgrading from 0.1

- Code that relied on `team_cap` / `guest_cap` (option or `flurfunk_settings` filter) to decide who
  is team: after the migration grant `flurfunk_team` / `flurfunk_guest` to the roles instead, or
  keep using the `flurfunk_user_kind` filter.
- Code that checked `manage_options` to mean "manages Flurfunk" should use
  `Flurfunk\Access\can_manage()`; `manage_options` alone no longer opens the settings.
- Providers without `capability` are now team only. A provider guests should use (e.g. statistics
  for their own posts) needs `'capability' => 'flurfunk_guest'`.
- Users created later get access through their role, as before; custom roles need the capabilities
  granted (matrix, role editor or `get_role( 'x' )->add_cap( 'flurfunk_team' )`).

## PHP functions

| Function | Since | Returns | Purpose |
| --- | --- | --- | --- |
| `flurfunk_register_provider( string $id, array $args )` | 0.1.0 | void | Register a slash command with cards. See [Providers](#providers-slash-commands-and-cards). |
| `flurfunk_register_target( string $type, array $args )` | 0.1.0 | void | Register something threads can be attached to. See [Targets](#targets). |
| `flurfunk_register_module( string $id, array $args )` | 0.2.0 | bool | Register an optional module (a switch under *Settings → Flurfunk → Modules*). Call it on the `flurfunk_modules` action. See [Modules](#modules). |
| `flurfunk_module_enabled( string $id )` | 0.2.0 | bool | Is a module switched on? |
| `flurfunk_module_setting( string $module_id, string $key )` | 0.2.0 | mixed | A module's setting: the saved value or the field's default; `null` for an unknown module or key. See [Module settings](#module-settings). |
| `flurfunk_register_capability( string $cap, array $args = [] )` | 0.2.0 | bool | Declare a capability for the permissions matrix, with recommended roles. See [Declaring capabilities](#declaring-capabilities). |
| `flurfunk_ensure_channel( string $slug, array $args = [] )` | 0.1.0 | int channel ID | Create a channel unless it exists. `$args`: `name`, `topic`, `access_team`, `access_guests`, `position`, `capability` (0.2.0; see [Channels that require a capability](#channels-that-require-a-capability)). An existing channel is left unchanged. |
| `flurfunk_post( array $args )` | 0.2.0 | int thread ID or `WP_Error` | Start a thread from PHP. See [Posting from PHP](#posting-from-php). |
| `flurfunk_reply( int\|string $thread, string $body, array $args = [] )` | 0.2.0 | int message ID or `WP_Error` | Reply to a thread (ID or `ref_key`). `$args`: `files`, `attachments`, `author`. |
| `flurfunk_set_status( int\|string $thread, string $status, int $author = 0 )` | 0.2.0 | true or `WP_Error` | Set a thread's task state: `open`, `doing`, `waiting`, `done`, or `''` (no task). |
| `flurfunk_as( int $user_id, callable $fn )` | 0.2.0 | what `$fn` returns | Run code as another person, then switch back. `0` = the default assistant (created if needed). As an assistant it passes channel capabilities. |
| `flurfunk_unread_count( int $user_id = 0 )` | 0.1.0 | int | Unread messages of a person (0 = current), for badges elsewhere. 0 for people without access. |
| `flurfunk_url( string $view = '' )` | 0.1.0 | string | URL of the app in wp-admin, optionally opening a view. |
| `flurfunk_app_url( string $view = '' )` | 0.1.0 | string | URL of the full-screen app outside wp-admin, same views. |

Views for `flurfunk_url()` (and the `view` query argument of the app page):

| View | Opens |
| --- | --- |
| `''` or `all` | All threads |
| `mentions`, `mine`, `tasks`, `board`, `notifications` | Those views |
| `channel:<slug>` | A channel |
| `target:<type>:<id>` | Conversations about a target, e.g. `target:post:12` |
| `thread:<id>` | A thread |
| `search:<text>` | Search results |

Register providers and targets on `init`. The built-in target is registered at priority 5, the
built-in provider at priority 20; the default priority 10 is fine for your own.

### Posting from PHP

`flurfunk_post()`, `flurfunk_reply()` and `flurfunk_set_status()` act as the default assistant
unless `author` names someone, and go through the same checks as the app: the author must be
allowed to start a thread in the channel (and on the target), mentions, tasks and notifications
work as usual, and the rate limit applies. As the assistant they may also post into channels that
require a capability. With a `ref_key` the call is idempotent: if a thread
with that key exists, its ID comes back and nothing is posted.

```php
// When an order fails: one task thread per order, with a card.
$thread = flurfunk_post( array(
	'channel'     => 'shop',                 // slug, required
	'title'       => 'Payment failed for #1042',
	'body'        => 'Stripe declined the card. <@12> please check.',
	'task'        => true,                    // also implied by due or assignees
	'due'         => gmdate( 'Y-m-d', strtotime( '+1 day' ) ),
	'assignees'   => array( 12 ),
	'target'      => array( 'type' => 'post', 'id' => 99 ), // optional
	'attachments' => array( array( 'provider' => 'acme/order', 'ref' => array( 'id' => 1042 ) ) ),
	'files'       => array(),                 // media library IDs
	'ref_key'     => 'acme-order:1042',
	// 'author'   => 7,                       // user ID; default: the assistant
) );
if ( ! is_wp_error( $thread ) ) {
	flurfunk_reply( 'acme-order:1042', 'Retried, still failing.' );
	flurfunk_set_status( 'acme-order:1042', 'waiting' );
}

// Anything else as a given person:
flurfunk_as( 7, fn () => Flurfunk\Store\mark_all_read( 7 ) );
```

`Flurfunk\Store\create_thread()` and `Flurfunk\Store\add_message()` stay internal; use the
functions above. From outside PHP, use [WP-CLI or the REST API](#posting-as-an-assistant-from-scripts-and-ci).

## Providers: slash commands and cards

A provider adds a slash command. Its `search` callback suggests items while the person types,
its `snapshot` callback turns the chosen item into card data, which is stored with the message.

```php
add_action( 'init', function () {
	flurfunk_register_provider( 'myplugin/report', array(
		'command'  => 'report',
		'label'    => 'Report',
		'hint'     => 'Insert a report card',
		'search'   => fn ( string $q, int $user_id ) => array(
			array( 'ref' => array( 'id' => 12 ), 'title' => 'Q3', 'subtitle' => 'Draft' ),
		),
		'snapshot' => fn ( array $ref, int $user_id ) => array(
			'title'  => 'Q3',
			'metric' => array( 'value' => '1,284', 'label' => 'views' ),
			'spark'  => array( 3, 5, 2, 8 ),
			'url'    => admin_url( 'admin.php?page=reports&id=' . (int) $ref['id'] ),
		),
		'can'      => fn ( int $user_id ) => user_can( $user_id, 'edit_posts' ),
	) );
} );
```

### Arguments

| Key | Required | Type | Meaning |
| --- | --- | --- | --- |
| `command` | yes | string | Typed as `/command`. Passed through `sanitize_key()` (lowercase letters, digits, `_`, `-`). |
| `snapshot` | yes | callable `( array $ref, int $user_id ): ?array` | Card data for a reference. Return `null` if the person may not see it or it is gone. |
| `label` | no | string | Shown on the card and in the menu. Default: the command. |
| `hint` | no | string | One line in the command menu. |
| `search` | no | callable `( string $q, int $user_id ): array` | Suggestions while typing. Without it the command offers nothing to pick. |
| `capability` | no | string | Capability needed for the menu, search, snapshot, refresh and actions. Default `flurfunk_team`; `flurfunk_guest` (or `''`) opens it to guests too; any other capability is checked with `user_can()`. Assistants count as team. |
| `capabilities` | no | array | Capabilities to declare, `cap => [ label, description, roles ]`. See [Declaring capabilities](#declaring-capabilities). |
| `can` | no | callable `( int $user_id ): bool` | Offer the command to this person, checked after `capability`. Checked for the menu, search, snapshot, refresh and actions. Default: everyone who has the capability. |
| `actions` | no | array | Buttons on the card that run PHP. See [card actions](#card-actions). |
| `hidden` | no | bool | Not in the `/` menu. For cards that only code attaches (e.g. a thread posted from PHP). |

The provider ID should look like `vendor/name`: the search route only accepts IDs matching
`[a-z0-9_-]+/[a-z0-9_-]+`.

### Search results

Return a list of items. Each needs a `ref` array; `title` and `subtitle` are shown in the menu.
HTML is stripped and at most 20 items are shown.

```php
return array(
	array( 'ref' => array( 'id' => 12 ), 'title' => 'Q3 report', 'subtitle' => 'Draft' ),
);
```

The `ref` is what gets stored and later passed to `snapshot`. Keep it small and stable; an `id`
key is used for lookups (`ref_key`), otherwise a hash of the whole ref.

The search callback is called with the text after `/command ` (it may be empty after the space).

### Snapshot fields

A snapshot is data, never HTML. Flurfunk keeps only these fields, cleans them (tags and control
characters removed, lengths cut, links limited to `http`, `https` and, for links, `mailto`) and
draws them itself. Fields of the wrong type are dropped, never turned into text.

| Field | Type | Shown as |
| --- | --- | --- |
| `title` | string (300) | Bold title, linked to `url` if given |
| `subtitle` | string (300) | Small line under the title |
| `text` | string (2,000, line breaks kept) | Paragraph |
| `url` | URL | Link of the title |
| `image` | URL (http/https) | Image at the side |
| `audio` | URL (http/https) | Audio player |
| `metric` | `{value, label}` | Big number with a label |
| `spark` | number[] (up to 60) | Small bar chart |
| `meta` | string[] (up to 6) | Small facts, joined with " · " |
| `status`, `fields`, `checklist`, `progress`, `people`, `table`, `badges`, `quote` | | The [building blocks](#card-building-blocks) |
| `target` | `{type, id}` | "Conversations about this" link |
| `action` | `{label, url}` | Link button |
| `actions` | string[] | The [card actions](#card-actions) available right now |
| `data` | array (up to 16 KB as JSON) | Not drawn; free data for your own [renderer](#custom-card-renderers) |

Everything else is dropped. `data` is passed through JSON, so only plain values survive.

### Lifecycle

- **Insert**: when a message is saved, Flurfunk calls `snapshot()` again for the author (not the
  preview from the menu). If it returns `null`, the card is silently dropped.
- **Stored**: the reference and the snapshot are saved with the message, so the card stays
  readable when the source changes or disappears, and in exports.
- **Update**: the "Update" button (`POST cards/<id>/refresh`) calls `snapshot()` for the person
  who clicked. If it returns `null`, the old snapshot stays and the person sees "This card cannot
  be updated (any more)."
- **Unregistered provider**: existing cards still show from their snapshot; the Update button
  disappears.

### The built-in `/post`

`flurfunk/post` searches posts of the configured post types (guests: only their own) and shows
title, status, author, thumbnail and the edit link. Remove it with:

```php
remove_action( 'init', 'Flurfunk\Providers\register_builtin', 20 );
```

## Card building blocks

Next to the classic fields, a snapshot can use these blocks. They look the same everywhere, in
light and dark, follow the accent colour, and are readable on phones. See them all in the
[card gallery](cards.md).

| Block | Schema | Limits | Drawn as |
| --- | --- | --- | --- |
| `status` | `{label, tone}`; `tone`: `neutral`, `positive`, `warning`, `critical`, `info` | label 60 | Pill next to the title. Unknown tones become `neutral`. |
| `fields` | `[{label, value, url?}]` | 12 fields; label 60, value 200 | Label/value pairs in columns (`<dl>`) |
| `checklist` | `[{label, done}]` | 20 items; label 200 | Ticked and open items; screen readers hear "done"/"open" |
| `progress` | `{value, max?, label?}` | `max` defaults to 100, `value` is clamped to 0…max | Bar with percentage (`role="progressbar"`) |
| `people` | user IDs | 12 | Avatars and names of people Flurfunk knows (others are left out) |
| `table` | `{columns: [...], rows: [[...]]}` | 6 columns, 20 rows, cells 200 | Plain text table; scrolls sideways when narrow. `columns` may be empty |
| `badges` | strings | 8; 40 each | Small labels |
| `quote` | string | 2,000; line breaks kept | Quoted block of plain text |

Empty blocks are left out, lists are cut at their limit after invalid entries were dropped, and
`done` must be a real boolean (or `1`, `"true"`, `"yes"`).

```php
add_action( 'init', function () {
	if ( ! function_exists( 'flurfunk_register_provider' ) ) {
		return;
	}
	flurfunk_register_provider( 'acme/order', array(
		'command'  => 'order',
		'label'    => 'Order',
		'search'   => fn ( string $q ) => acme_find_orders( $q ),      // [ [ 'ref' => [ 'id' => 1042 ], 'title' => '#1042' ] ]
		'can'      => fn ( int $user_id ) => user_can( $user_id, 'manage_options' ),
		'snapshot' => function ( array $ref, int $user_id ) {
			$o = acme_get_order( (int) $ref['id'] );
			if ( ! $o ) {
				return null;
			}
			return array(
				'title'     => 'Order #' . $o->id,
				'url'       => admin_url( 'admin.php?page=acme-orders&id=' . $o->id ),
				'status'    => array( 'label' => $o->paid ? 'Paid' : 'Open', 'tone' => $o->paid ? 'positive' : 'warning' ),
				'fields'    => array(
					array( 'label' => 'Customer', 'value' => $o->customer ),
					array( 'label' => 'Total', 'value' => $o->total ),
				),
				'checklist' => array(
					array( 'label' => 'Paid', 'done' => $o->paid ),
					array( 'label' => 'Shipped', 'done' => $o->shipped ),
				),
				'table'     => array(
					'columns' => array( 'Item', 'Qty' ),
					'rows'    => array_map( fn ( $i ) => array( $i->name, (string) $i->qty ), $o->items ),
				),
				'people'    => array( $o->handled_by ),   // user IDs
				'badges'    => $o->express ? array( 'Express' ) : array(),
				'quote'     => $o->customer_note,
				'actions'   => $o->shipped ? array() : array( 'ship' ),   // see "Card actions"
			);
		},
	) );
} );
```

## Card actions

A provider can put buttons on its cards that run PHP on the site: approve, ship, retry. Register
them with the provider; each snapshot lists the ones that make sense right now.

```php
'actions' => array(
	'ship' => array(
		'label'      => 'Mark as shipped',
		'done'       => '%s marked the order as shipped',   // event line, %s = person
		'style'      => 'primary',                          // primary | secondary | danger
		'confirm'    => 'Mark this order as shipped?',      // optional: asked inline first
		'capability' => 'flurfunk_team',                    // default
		'callback'   => function ( array $ref, array $card, int $user_id ) {
			$ok = acme_ship_order( (int) $ref['id'], $user_id );
			if ( ! $ok ) {
				return new WP_Error( 'acme', 'The shipping service did not answer.' );
			}
			return acme_order_snapshot( $ref );             // the new snapshot
		},
	),
),
```

| Key | Default | Meaning |
| --- | --- | --- |
| `label` | the action ID | Button text (60 characters) |
| `done` | | Event line in the thread, `%s` is the person ("Ana marked the order as shipped"). Without it: *Ana chose "Mark as shipped"* |
| `style` | `secondary` | `primary`, `secondary` or `danger` |
| `confirm` | | Question asked inside the card before it runs. Use it for anything hard to undo |
| `capability` | `flurfunk_team` | Needed to see and run the button, on top of the provider's `capability` and `can`. Any [capability](#permissions), e.g. `moderate_comments` or one you declared |
| `callback` | required | `( array $ref, array $card, int $user_id, array $input ): array\|WP_Error`. Runs as the person who clicked. `$input` is what they chose on the card: in a custom renderer, form fields with `data-card-input` (checkboxes of one name become a list of the checked values) – untrusted, check it |

Action IDs are keys like `ship` (lowercase letters, digits, `_`, `-`); up to six per provider.

When someone clicks, `POST cards/<id>/action/<action>`:

1. checks that the person may see the thread (404 otherwise), that the action exists (404), and
   the rate limit (429);
2. takes a lock on the card, so two clicks at once run one after the other (409 if the lock is not
   free within ten seconds), then checks that the provider's `capability` and `can` and the
   action's `capability` allow it (403) and that the **stored** snapshot lists the action (409
   otherwise; the app then shows the current card);
3. calls the callback. A `WP_Error` comes back to the person as its message (status 400, or the
   4xx status you set); an exception becomes a generic error without details; `null` means "ask
   `snapshot` again";
4. stores the returned snapshot (cleaned like any other), adds an `event` message with
   `event: card_action` to the thread and fires `flurfunk_card_action`. Notifications follow the
   thread's normal rules, as for a reply: author, participants, responsible people and followers.

The app sends a random `key` per click; a retry with the same key within ten minutes returns the
thread without running the action again, also when both requests arrive at the same time. Your callback must still check its own permissions
(e.g. `edit_comment` for that comment): the capability only decides who sees the button.

## Custom card renderers

The default card handles most needs. For a special look, register a renderer for your provider
ID in JavaScript. It receives the snapshot and a helper object and returns an HTML string.

```js
window.Flurfunk.registerCard( 'myplugin/report', ( s, h ) =>
	'<div class="ff-card-body"><span class="ff-card-head"><b class="ff-card-title">' + h.esc( s.title ) + '</b>'
	+ h.status( s.data.state, 'positive' ) + '</span>'
	+ h.fields( [ { label: 'Views', value: s.data.views } ] )
	+ h.progress( s.data.done, s.data.total, 'Steps' )
	+ '</div>'
);
```

| Helper | Does |
| --- | --- |
| `h.esc( value )` | HTML-escapes a value. Use it for every value you output yourself. |
| `h.rich( text )` | Escapes and applies Flurfunk's [formatting](user-guide.md#formatting) |
| `h.when( iso )` | A short, localised relative time ("today, 14:05") |
| `h.card( snapshot )` | The default card body for a snapshot, to extend |
| `h.status( label, tone )` (also `h.pill`) | Status pill; tone `neutral`, `positive`, `warning`, `critical`, `info` |
| `h.fields( [ { label, value, url } ] )` | Label/value pairs |
| `h.list( [ { label, done } ] )` (also `h.checklist`) | Checklist |
| `h.progress( value, max = 100, label )` | Progress bar |
| `h.people( ids )` | Avatars and names; also takes `[ { name, avatar } ]` |
| `h.table( columns, rows )` | Table of plain text |
| `h.badges( labels )` | Small labels |
| `h.quote( text )` | Quoted text |
| `h.button( label, { style, action, confirm } )` | A button. With `action` (an action ID of your provider) it runs that card action, with confirmation, busy state and errors |

The block helpers escape their input. The same helpers are available as
`window.Flurfunk.cardHelpers`.

Rules:

- The returned HTML is inserted as is. Escape everything that comes from data.
- The renderer is used for stored cards. While composing, the card preview uses the default look.
- If the renderer throws, the default card is drawn instead.
- Flurfunk wraps the result in its card frame with the provider label and the Update button, and
  adds the row of available actions unless your HTML already contains buttons made with
  `h.button( …, { action } )`.
- The CSS classes `ff-card-body`, `ff-card-head`, `ff-card-title`, `ff-card-sub`, `ff-card-text`,
  `ff-card-metric`, `ff-card-meta`, `ff-card-action`, `ff-card-img` give you the default styles.

Load your script after Flurfunk's (handle `flurfunk`), on the pages where Flurfunk runs. See the
[example](examples.md#a-custom-card-renderer).

## Modules

A module is an optional part with a switch under *Settings → Flurfunk → Modules* (and
`wp flurfunk module on|off <id>`). A module that is off is never loaded. Flurfunk's own optional
parts are modules, and a plugin can register its own, so everything optional is in one list.

```php
add_action( 'flurfunk_modules', function () {
	flurfunk_register_module( 'acme/review', array(
		'label'        => __( 'Review workflow', 'acme' ),
		'description'  => __( 'A task for every post that is submitted for review.', 'acme' ),
		'group'        => 'Acme',
		'requires'     => array( 'Acme_Plugin' ),        // or a callable, or a bool
		'default'      => false,
		'load'         => fn () => require __DIR__ . '/review.php',
		'settings_url' => admin_url( 'admin.php?page=acme' ),
		'capabilities' => array(
			'acme_review' => array( 'label' => 'Approve reviews', 'roles' => array( 'editor' ) ),
		),
		'settings'     => array(
			array( 'key' => 'channel', 'type' => 'channel', 'label' => __( 'Channel', 'acme' ), 'default' => 'general' ),
			array( 'key' => 'remind', 'type' => 'number', 'label' => __( 'Remind after days', 'acme' ), 'default' => 2, 'min' => 1, 'max' => 30 ),
		),
	) );
} );
```

| Argument | Default | Meaning |
| --- | --- | --- |
| `label` | required | Name next to the switch |
| `description` | | One sentence under it |
| `group` | `Other` | Heading the module is listed under |
| `requires` | `true` | `true`/`false`, a callable returning bool, or a list of functions, classes or constants that must exist. Unmet: the switch says so and the module does not load |
| `default` | `true` | On until the site switches it off |
| `load` | required | Callable that loads the module (e.g. `require` its file) |
| `settings_url` | | Link shown next to the switch |
| `capabilities` | | Capabilities the module brings, as in [Declaring capabilities](#declaring-capabilities); their roles get them once the module is on |
| `settings` | | Fields shown under the switch, see [Module settings](#module-settings) |

IDs look like `vendor/name` (lowercase letters, digits, `_`, `-`). `flurfunk_modules` fires on
`init` at priority 0, so labels can be translated; modules that are on load right after, before
`flurfunk_modules_loaded`. A module can therefore hook into `init` (priority 1 and later),
`admin_menu`, `rest_api_init` and everything later. `flurfunk_module_on` and
`flurfunk_module_off` fire when a switch changes, `flurfunk_module_enabled` overrides the switch.

### Module settings

A module can bring a few settings of its own. Flurfunk shows them under the module's switch in
*Settings → Flurfunk → Modules* (saved with the same button, also while the module is off),
sanitizes them by type and stores them in the option `flurfunk_module_settings`
(`module ID => { key => value }`). Read them with `flurfunk_module_setting( $module_id, $key )`:
the saved value, or the field's `default` while nothing is saved.

| Field key | Meaning |
| --- | --- |
| `key` | required; lower-case letters, digits, `_` and `-` |
| `type` | `channel`, `checkbox`, `number`, `text`, `select`, `roles` or `users` (0.2.1); a field with another type is left out (with a `_doing_it_wrong` notice) |
| `label` | text next to the field |
| `description` | optional line under it |
| `default` | value until the site saves one (defaults: `false`, `0`, `[]` for roles and users, else `''`) |
| `options` | `select` only: `value => label` |
| `min`, `max` | `number` only: the value is clamped to them |
| `capability` | `users` only: only people with this capability are offered and kept. Without it: everyone with access to Flurfunk |

| Type | Field | Stored as | Invalid input becomes |
| --- | --- | --- | --- |
| `checkbox` | checkbox | bool | `false` (an unticked box in the form is `false`) |
| `number` | number input | int (float if a decimal point was entered), clamped to `min`/`max` | the default |
| `text` | text input | string, `sanitize_text_field()` | |
| `select` | select | one of the `options` keys | the default |
| `channel` | select of all channels (inactive ones marked; the default is listed even if it does not exist yet) | channel slug | the default |
| `roles` | a checkbox per role | list of role names that exist | unknown roles are dropped |
| `users` | a checkbox per person (assistants are not offered) | list of user IDs | people without the `capability`, assistants and unknown IDs are dropped |

The `flurfunk_module_setting` filter (`$value`, `$module_id`, `$key`) adjusts what
`flurfunk_module_setting()` returns. `Flurfunk\Modules\save_settings( $id, $values )` saves from
code (only the given keys; sanitized the same way).

### Core modules

They only build on WordPress itself.

| Module | Default | Does |
| --- | --- | --- |
| `flurfunk/comments` | on | `/comment` (German `/kommentar`) inserts a comment: the commenter's public name, the post, an excerpt as quote, the state as status pill, and the actions **Approve**, **Spam** and **Trash** (with confirmation), depending on the state. For the team (`flurfunk_team`) with `moderate_comments`; each action checks `edit_comment` for that comment. The card never contains the email or IP address, the user agent or the commenter's website. |
| `flurfunk/comments-daily` | off | Once a day (9:00 site time, filter `flurfunk_comments_hour`), if comments are waiting, the assistant posts one thread "Comments awaiting moderation" with the number, the oldest date and a table of up to ten (name, post, date). Running again on the same day updates that card. No thread on days without waiting comments. Everyone who can read the channel sees the commenters' names and post titles. Setting **Channel** (default `comments`): switching the module on creates #comments ("Comments"), which requires `moderate_comments` and is closed to guests (an existing #comments is used as it is). The filter `flurfunk_comments_channel` overrides the setting. |
| `flurfunk/ai` | off | Needs WordPress 7.0 or newer (the AI client) and an AI provider under *Settings → Connectors*. Assistants get an AI profile under *Settings → Flurfunk → AI assistants*: on/off, instructions, the channels they take part in, whether guests may ask, whether they may read the text of the post a thread is about, read-only tools (abilities), preferred model IDs and a monthly token budget; channels can carry instructions of their own; site-wide switches replace email addresses and phone numbers before sending (on), keep a log of runs without content (on) and offer private summaries (on). When a person with `flurfunk_use_ai` mentions such an assistant in one of its channels (also by editing a message) – or starts a new thread in a channel where the assistant answers every new thread (profile key `auto`) – a run is queued: the thread shows "… is thinking", the assistant reads that one thread (newest 60 messages, 40,000 characters) and answers in it; only the person who asked is notified. "Summarize" in the thread header gives a private summary that is never posted. Tools run with the rights of the person asking and, in Flurfunk, only in the assistant's channels. Only abilities annotated `readonly: true` can be tools; everything else (including abilities without annotations, e.g. those of the WordPress plugin "AI") counts as changing something and can only be offered as **proposals**: the assistant never runs them, each call becomes a card `flurfunk/ai-proposal` under its answer with **Run** and **Dismiss**, and a person runs it with their own rights (stored in the option `flurfunk_ai_proposals`, the newest 500). Assistants do not trigger each other; a mention is answered once; over budget it says so once a month. See [AI assistants](#ai-assistants). Since 0.3.0. |
| `flurfunk/review` | off | When a post of one of the configured post types is submitted for review (`pending`), the assistant starts a task thread about it, assigned to everyone who may publish and edit it (not the author, no assistants), with the post as a card. Settings: **Channel** (default `general`) and **Assign everyone who may publish** (default on; off: nobody is assigned). The filters `flurfunk_review_channel` and `flurfunk_review_assignees` override them per post. Submitting again reopens the same thread; publishing (or scheduling) replies and ticks it off; back to draft replies and marks it as a question. |

### AI assistants

What the AI gets is built in `Flurfunk\Modules\Ai\context()`: a system instruction (who it is, the
rules, the channel and its topic, the date, the site's and the channel's instructions) and the thread
as a transcript inside `<thread>…</thread>`, with the note that the conversation is data, not
instructions. The call goes through `wp_ai_client_prompt()` with `using_system_instruction()`,
`using_max_tokens()` and, if set, `using_model_preference()`. A site can block calls with WordPress's
`wp_ai_client_prevent_prompt` filter; runs happen as the assistant's account.

| Hook | Arguments | Use |
| --- | --- | --- |
| `flurfunk_ai_pre_answer` (filter) | `null`, `array $context` (`system`, `prompt`, `asker`), `int $agent` | Return `['text' => …, 'tokens' => …]` or a `WP_Error` to answer without the provider (tests, another backend). |
| `flurfunk_ai_system` (filter) | `string $system`, `object $thread`, `int $agent` | Change the system instruction. |
| `flurfunk_ai_max_tokens` (filter) | `int $max` (4000), `int $agent` | Maximum length of an answer. |
| `flurfunk_ai_run_now` (filter) | `bool $now` (true) | false: leave runs to WP-Cron only. |
| `flurfunk_ai_error` (action) | `WP_Error $error`, `int $thread_id`, `int $agent` | A run failed (the assistant also says so in the thread, in plain words). |
| `flurfunk_ai_guidelines` (filter) | `string $text` | Editorial guidelines for the assistants. Default: the site's guidelines from the WordPress plugin "AI" (categories site, copy, additional), if it is active. |
| `flurfunk_ai_summaries_per_hour` (filter) | `int $max` (20) | Private summaries per person and hour. |
| `flurfunk_ability_channel` (filter) | `bool $ok`, `string $channel` | Narrow the channels Flurfunk's reading abilities return; the AI module uses it while an assistant looks things up. |
| `flurfunk_ai_follow_ups` (filter) | `bool $on` (true), `int $thread_id`, `int $author` | false: a reply right after an assistant's answer needs a new mention. |
| `flurfunk_ai_pre_check` (filter) | `null`, `object $thread`, `int $message_id`, `int $agent`, `bool $unasked` | Decide the quick check yourself: `'answer'`, `'thanks'`, `'no'` (or true/false). `$unasked`: a new thread nobody asked, in a channel where assistants answer unasked (message id 0). |
| `flurfunk_ai_check_models` (filter) | `string[] $models`, `int $agent` | Models for the quick check, first available wins (default: small Claude, Gemini, GPT models). |
| `flurfunk_ai_check_max_tokens` (filter) | `int $max` (600) | Room for the quick check (newer models think first). |
| `flurfunk_ai_stays_for` (filter) | `int $seconds` (two days) | How long an assistant stays in a thread after its last answer. |
| `flurfunk_private_partners` (filter) | `array $list`, `int $user_id` | Who a person may talk to in private: `[ { id, name, role } ]`. The AI module adds the assistants this person may ask. |
| `flurfunk_reaction` (action) | `object $thread`, `int $message_id` (0 = the thread), `string $emoji`, `int $user_id`, `bool $added` | A reaction was added or taken back. |
| `flurfunk_reactions_per_minute` (filter) | `int $max` (60), `int $user_id` | Brake for reactions. |
| `flurfunk_ai_packs` (filter) | `array $packs` | The capability packs on the settings page (key → `label`, `desc`, `kind` read/propose, `posts`, `tools`, `proposals`, `needs`, `available`). A pack stands for those settings; ticking it adds them to a profile, unticking removes them. Add a pack for your plugin's abilities here. |

REST: `POST flurfunk/v1/threads/<id>/summary` (optional `since`: a message ID – then only what came
after it) returns `{ text, agent }` for the person asking (400 `flurfunk_ai_none` if no assistant
takes part in the channel, 403 if summaries are off, 429 over the hourly limit or the budget).

In the thread and channel data (`flurfunk_thread_view`, `flurfunk_channel_view`) the module adds
`thinking` (assistants at work) with `thinking_steps` (`read`, `look`, `write`), `ai_meta` per AI
answer (`posts` and `threads` it read or opened – titles only if the viewer may see them –,
`tools` it used, `asker`), and per channel `agents`, `auto`, `agents_info` (`id`, `role`, `does`)
and `ai_note`. Profile key `role`: a few words shown next to the assistant (mention list, channel).
Answers are put into the chat's format (`**bold**` and headings become `*bold*`).

Options: `flurfunk_ai_settings` (`redact`, `log`, `summary`), `flurfunk_ai_channels` (channel →
instructions), `flurfunk_ai_log` (the last 100 runs: time, agent, asker, thread, channel, model,
tokens, error, tools, kind), `flurfunk_ai_meta` (what the newest 2,000 answers were based on). User meta on assistants: `flurfunk_ai` (profile), `flurfunk_ai_usage`
(tokens this month).

### Limits of AI assistants

While an assistant works for someone (an answer, a summary, a proposal being run), Flurfunk runs it
as that person inside `Flurfunk\Modules\Ai\with_limit( $agent, $user_id, $fn )`. A `map_meta_cap`
filter then turns every check of that person into `do_not_allow` if it needs a capability the
assistant's limit lacks (`limit_caps()`: the capabilities of the role in its profile, or none for
"the person asking") or one of `Flurfunk\Modules\Ai\BLOCKED` (administering users, plugins, themes,
options, unfiltered HTML, `flurfunk_manage`, role names such as `administrator`). It only takes away,
so abilities of other plugins are covered without knowing them; `kses_init()` runs again, so markup
saved within a limit is filtered. `Flurfunk\Access\scope()` names the scope, so cached channel
access is not shared between scopes. A proposal is only made if the ability's `permission_callback`
passes for the person asking within the limit; `flurfunk/start-thread` and `flurfunk/reply` check the
channel or conversation in their permission callbacks for that.

### Reactions and private conversations

`POST threads/<id>/reactions` with `{ message, emoji }` (`message` 0 = the thread itself) adds the
person's reaction or takes it back and returns `{ message, reactions }`. Threads and replies carry
`reactions`: `[ { emoji, people } ]`. Any single emoji is accepted (at most 20 kinds per message); it is
stored as code points without variation selectors, so 👍 and 👍️ are one reaction.

`POST threads` with `{ private_with: <assistant ID>, body }` starts a private conversation
(`Flurfunk\Store\start_private()`). Private threads (`private: true`, target `assistant:<ID>`) live
in a container channel that never opens as a channel; only their person and the assistant can see
them, and lists, numbers and searches leave them out (`Access\where()` adds `t.private_to = 0`).
`GET threads?private_with=<ID>` lists one's own; `counts.private` has their unread replies per
assistant. Export and import keep them private.

### Abilities

With WordPress 6.9 or newer Flurfunk registers abilities in the category `flurfunk`. They run as the
current user with Flurfunk's own checks, so an agent only reaches what its account may.

| Ability | Public | Does |
| --- | --- | --- |
| `flurfunk/list-channels` | yes | Channels the user can read (short name, name, topic). |
| `flurfunk/list-threads` | yes | Conversations, newest activity first; `channel`, `view` (`all`, `mine`, `tasks`, `mentions`), `search`, `limit` (≤ 50). |
| `flurfunk/get-thread` | yes | One conversation with its newest 100 messages; mentions as names. |
| `flurfunk/reply` | no | Replies as the user: `id`, `text`. |
| `flurfunk/start-thread` | no | Starts a conversation: `channel`, `title`, `text`, `task`. |
| `flurfunk/find-posts` | yes | Posts the user may see (drafts only if they may edit them): `search`, `status`, `order`, `limit`. |
| `flurfunk/get-post` | yes | One post's text as plain text: `id`. |
| `flurfunk/create-draft` | no | A draft (never published), the user is its author: `title`, `text` (paragraphs, `- ` lists, `## ` headings, `*bold*`; no HTML), `excerpt`, `type`, `featured_image`, `images` (IDs; Flurfunk files from conversations the user can see move into the media library). Needs the post type's `create_posts`, for images `upload_files`. |
| `flurfunk/update-posts` | no | Changes existing posts, up to 20 at once: `posts: [ { id, title, excerpt, replace: [ { find, with } ], add_categories, remove_categories, add_tags, remove_tags, featured_image, unchanged_since } ]`. Replacements only touch text, never markup; `with` is escaped. A post modified since `unchanged_since`, or one whose markup kses would change for this person, is skipped. Returns `posts: [ { id, status: changed\|skipped, message, undo } ]`; `Flurfunk\Changes\undo()` takes those back. |
| `flurfunk/follow-up` | no | An assistant at work (or an assistant account) sets itself a check-in in a conversation: `thread`, `when` (`YYYY-MM-DD HH:MM`, site time), `note`; or `cancel`. When due (cron hook `flurfunk_ai_check_in`), the assistant follows up in the thread; "@Name" in its text becomes a mention. Pending ones are in the option `flurfunk_ai_followups`. |
| `flurfunk/routine` | no | An assistant at work (or an assistant account) manages the routines of a conversation's channel: `thread`, `action` (`add`, `list`, `pause`, `resume`, `remove`); for `add` a `name`, the `task`, `days` (`mon` … `sun`), `time` (`HH:MM`, site time) and `post` (`thread` or `channel`); `id` for the others. The person must be team and see the conversation. When due (cron hook `flurfunk_ai_routine`), the assistant does the task with that person's rights and posts the result, or nothing for an answer of `-`. Routines live in the option `flurfunk_ai_routines`, at most ten per channel. |
| `flurfunk/remember` | no | Adds a note to the channel of a conversation (`thread`, `note`) or removes one by its number (`remove`); needs the site's notes per channel switched on and a conversation in a channel (not private) the user can see. Notes live in the option `flurfunk_ai_memory`. |
| `flurfunk/update-draft` | no | Changes a draft made with `create-draft` while nobody else has saved it since (`_flurfunk_draft_saved` = its modification time): `id`, `title`, `text`, `excerpt`, `featured_image`, `images`. Needs `edit_post`. |

The action `flurfunk_draft_saved` (`int $post_id`, `array $input`) fires after either; drafts an AI
assistant wrote carry `_flurfunk_assistant` and `_flurfunk_thread`.

"Public" is the ability's `meta.public` (REST, MCP adapter); the filters
`flurfunk_abilities_public` and `flurfunk_abilities_public_write` change it.

To try it locally without a paid key: `tools/playground/start-ai.sh` starts a Playground on port 9600
with the OpenAI and Anthropic providers; requests of the OpenAI provider go to an OpenAI-compatible
proxy on this machine (`FLURFUNK_DEV_AI_PROXY` in `tools/playground/blueprint-ai.json`).
`ANTHROPIC_API_KEY=… tools/playground/start-ai.sh` uses a real Anthropic key instead.

## Targets

A target is something a thread can be attached to. `post` is built in; plugins add more.

```php
add_action( 'init', function () {
	flurfunk_register_target( 'dossier', array(
		'label'  => 'Dossier',
		'search' => fn ( string $q, int $user_id ) => array(
			array( 'id' => 12, 'title' => 'Travel', 'subtitle' => '12 posts' ),
		),
		'info'   => fn ( int $id ) => array( 'title' => 'Travel', 'url' => admin_url( '…' ), 'status' => 'open' ),
		'can'    => fn ( int $user_id, int $id ) => user_can( $user_id, 'edit_others_posts' ),
	) );
} );
```

| Key | Required | Type | Meaning |
| --- | --- | --- | --- |
| `info` | yes | callable `( int $id ): ?array` | `title`, `url`, `status` of the target. Return `null` when it is gone; the thread then shows "deleted". |
| `label` | no | string | Name of the type, shown in the picker. Default: the type key. |
| `search` | no | callable `( string $q, int $user_id ): array` | Items `{id, title, subtitle}` for the "Attached to" picker. `$q` is empty for the initial list. |
| `can` | no | callable `( int $user_id, int $id ): bool` | May the person attach a thread to this target? Default: team members, if `info()` returns something. |

Notes:

- The type key goes through `sanitize_key()` and is cut to 32 characters. Use only lowercase
  letters and underscores: the `target` filter of `GET threads` only matches `[a-z_]+:<id>`.
- Target IDs are integers.
- Guests never see non-post target types in the picker. `own-posts` channels only work with
  posts.
- `info()` is called without a user. If the URL depends on the person, use `current_user_can()`
  inside, as the built-in post target does.
- Adjust title or link for display with the `flurfunk_target_info` filter, and permission with
  `flurfunk_can_target`.

Link to the conversations about a target with `flurfunk_url( 'target:dossier:12' )`, or embed
them with [`mountTarget`](#mounttarget).

## Embedding

Flurfunk's interface can be placed on any wp-admin screen. Load the assets first:

```php
add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( 'toplevel_page_my-page' === $hook ) {
		Flurfunk\Admin\enqueue();
	}
} );
```

`Flurfunk\Admin\enqueue()` adds `assets/app.css`, `assets/app.js` (handle `flurfunk`) and the
configuration (`window.flurfunkConfig`: REST root, nonce, URLs, translations), and for people
with `upload_files` WordPress's media dialog (`wp_enqueue_media()`) for "From media library".
The current user must be able to use Flurfunk; for others the REST calls fail.

Embedded views always use the light theme, the site's accent colour and Flurfunk's styles.

### `window.Flurfunk`

| Member | Signature | Purpose |
| --- | --- | --- |
| `mountApp` | `( el ) → Promise` | The full overview. Runs automatically for `#flurfunk-app`. |
| `mountChannel` | `( el, { channel, newLabel?, titleLabel? } ) → Promise<{refresh}>` | A channel as a numbered checklist |
| `mountTarget` | `( el, { type, id } ) → Promise<{refresh}>` | Conversations about one target (the editor panel uses this) |
| `mountRefs` | `( container, { prefix?, channel, onChange? } ) → Promise` | A thread under each element with `data-flurfunk-ref` |
| `registerCard` | `( providerId, ( snapshot, h ) => html )` | Custom card look |
| `api` | `( path, { method?, body? } ) → Promise` | Calls the REST API with the nonce (renews an expired nonce once) |
| `t` | `( text, ...args ) → string` | Translates one of Flurfunk's strings |
| `format` | `( text ) → html` | The text formatter |

### mountChannel

Shows a channel as a numbered list: open items first, done ones below, a form for a new item at
the end. Every item shows its full text, cards, task controls and the conversation.

```js
Flurfunk.mountChannel( document.getElementById( 'questions' ), {
	channel: 'questions',          // channel slug, must exist
	newLabel: 'New question',      // heading of the form (default "New item")
	titleLabel: 'Question',        // placeholder of the title field (default "Title")
} );
```

At most 200 threads are shown, oldest first. A link with `#ff-item-<id>` scrolls to that item.

### mountTarget

```js
Flurfunk.mountTarget( el, { type: 'dossier', id: 12 } );
```

Lists the threads about the target, lets people reply, tick tasks and start a new conversation
(as a task by default) in any channel they may start threads in. The returned `refresh()` reloads.

### mountRefs

Puts a thread under existing elements, for example the items of a checklist. Each element with a
`data-flurfunk-ref` attribute gets a reply box (and a tick box if it is a task). The thread is
created on the first reply or tick, so nothing needs seeding.

```html
<ul id="checklist">
  <li data-flurfunk-ref="checklist:billing" data-title="Billing" data-task="1">Check the invoices</li>
  <li data-flurfunk-ref="checklist:legal" data-title="Legal notice" data-body="Is the address current?">Legal notice</li>
</ul>
<script>
  Flurfunk.mountRefs( document.getElementById( 'checklist' ), {
    prefix: 'checklist:',   // loads existing threads whose ref_key starts with this
    channel: 'general',     // channel for new threads
    onChange: () => {},     // called after a tick
  } );
</script>
```

| Attribute | Meaning |
| --- | --- |
| `data-flurfunk-ref` | The thread's `ref_key`, unique per item |
| `data-title` | Title of the thread when it is created |
| `data-body` | Text of the thread when it is created |
| `data-task="1"` | The thread is a task (shows a tick box) |
| `data-channel` | Channel for this item (overrides `channel`) |

Elements whose thread is done get the class `ff-is-done`. Up to 200 threads per prefix are loaded.
Two people ticking the same item at the same moment still get one thread: `ref_key` creation is
locked in the database.

## Hooks

### Filters

| Filter | Since | Arguments | Return | Purpose |
| --- | --- | --- | --- | --- |
| `flurfunk_settings` | 0.1.0 | `array $settings` | array | Adjust settings in code. Keys: `name`, `logo_id`, `accent`, `sidebar`, `post_types`, `editor_panel`, `posts_column`, `keep_data`. (0.1 also had `team_cap` and `guest_cap`; they are only read once for the [migration](#capabilities).) Applied once per request. |
| `flurfunk_user_kind` | 0.1.0 | `string $kind`, `WP_User $user` | `'team'`, `'guest'` or `''` | Who is team, guest or has no access. Default from `flurfunk_team` / `flurfunk_guest` (assistants: team). Cached per request. |
| `flurfunk_capabilities` | 0.2.0 | `array $caps` | array | The capability registry for the permissions matrix and recommended grants: `cap => { label, description, roles, group, module }`. |
| `flurfunk_channel_level` | 0.1.0 | `string $level`, `object $channel`, `int $user_id`, `string $kind` | `'all'`, `'own-posts'` or `'none'` | Per-person access to a channel, e.g. private channels. Runs after the channel's capability check (so it can override it). Other values count as `none`. Cached per request. |
| `flurfunk_bot_can` | 0.2.0 | `bool $can` (false), `string $cap`, `object $channel`, `int $user_id` | bool | May an assistant whose account lacks the capability a channel requires use that channel anyway (e.g. an agent over the REST API)? PHP code acting as the assistant passes without it. See [Channels that require a capability](#channels-that-require-a-capability). |
| `flurfunk_channel_capabilities` | 0.2.0 | `array $groups` | array | The capabilities offered under *Channels → Also requires*: `group label => { cap => label }`. |
| `flurfunk_owned_post_ids` | 0.1.0 | `int[] $ids`, `int $user_id` | int[] | Posts that count as a person's own (co-authors). |
| `flurfunk_post_owners` | 0.1.0 | `int[] $user_ids`, `WP_Post $post` | int[] | Owners of a post, for the "… can read this" hint only. |
| `flurfunk_can_target` | 0.1.0 | `bool $can`, `string $type`, `int $id`, `int $user_id` | bool | May the person attach a thread to this target? Also used by the `/post` snapshot. |
| `flurfunk_can_resolve` | 0.1.0 | `bool $can`, `object $thread`, `int $user_id` | bool | May the person change the task state, due date or assignees? |
| `flurfunk_can_attach` | 0.1.0 | `bool $can`, `WP_Post $attachment`, `int $user_id` | bool | May the person refer to this media library file in a message? Default: own files, files they may edit, and for assistants any file not attached to a private post. |
| `flurfunk_max_files` | 0.1.0 | `int $max` | int | Files per message. Default 50. |
| `flurfunk_thread_view` | 0.3.0 | `array $view`, `object $thread`, `int $user_id` | array | A thread as the interface and `GET threads/<id>` return it (e.g. `thinking`: assistants working on it). |
| `flurfunk_channel_view` | 0.3.0 | `array $view`, `object $channel` | array | A channel as the interface gets it (e.g. `agents`: AI assistants that answer there). |
| `flurfunk_board_done_days` | 0.1.0 | `int $days` | int | How long done tasks stay on the board. Default 14. |
| `flurfunk_can_edit` | 0.1.0 | `bool $can`, `int $author_id`, `int $user_id` | bool | May the person edit or delete this message or thread? Default: author or `flurfunk_moderate`. |
| `flurfunk_target_info` | 0.1.0 | `array $info`, `string $type`, `int $id` | array | `type`, `title`, `url`, `status`, `label` of a target for the interface. |
| `flurfunk_user_role_label` | 0.1.0 | `string $role`, `WP_User $user` | string | The role shown next to names. |
| `flurfunk_notify_recipients` | 0.1.0 | `array $to`, `object $thread`, `int $message_id` | array | `user_id => kind` for a new thread (`$message_id` 0) or message. Not used for task, due, reminder, question (`waiting`) and done notifications. |
| `flurfunk_mail` | 0.1.0 | `array {subject, body}`, `WP_User $user`, `?object $thread`, `int $message_id` | array or false | Change a notification mail, or return false to skip it. `$thread` is null for the daily digest. |
| `flurfunk_push_payload` | 0.1.0 | `array {title, body, url, tag, icon}`, `array $n` | array | Change a push message. `$n` has `uid`, `kind`, `thread` (row), `message_id`, `actor`. |
| `flurfunk_push_hosts` | 0.1.0 | `string[] $hosts` | string[] | Push services a browser subscription may point to; `*.example.com` matches subdomains. Default: `fcm.googleapis.com`, `android.googleapis.com`, `updates.push.services.mozilla.com`, `*.push.apple.com`, `*.notify.windows.com`. |
| `flurfunk_push_renew_limit` | 0.1.0 | `int $max` | int | Logged-out subscription renewals per IP address and hour. Default 30; 0 = off. |
| `flurfunk_rate_limit` | 0.1.0 | `int $max`, `int $user_id` | int | New threads and messages per minute and person. Default 30, 0 for people with `flurfunk_manage`; 0 = off. |
| `flurfunk_thread_limit` | 0.1.0 | `int $limit` | int | Messages loaded per thread in the app. Default 300. |
| `flurfunk_notification_days` | 0.1.0 | `array {read, unread}` | array | Days to keep notifications. Default 90 and 180. |
| `flurfunk_comments_channel` | 0.2.0 | `string $slug` | string | Channel of the daily moderation thread (module `flurfunk/comments-daily`). Default: the module's setting (`comments`). |
| `flurfunk_comments_hour` | 0.2.0 | `int $hour` | int | Hour (site time) of the daily moderation thread. Default 9. |
| `flurfunk_review_channel` | 0.2.0 | `string $slug`, `WP_Post $post` | string | Channel of review requests (module `flurfunk/review`). Default: the module's setting (`general`). |
| `flurfunk_review_assignees` | 0.2.0 | `int[] $user_ids`, `WP_Post $post` | int[] | Who is responsible for a review request. Default: everyone who may publish it, or nobody when the module's setting is off. |
| `flurfunk_module_enabled` | 0.2.0 | `bool $on`, `string $id` | bool | Is a module switched on? Default: the choice under *Settings → Flurfunk → Modules*, else the module's `default`. Decides whether it loads. |
| `flurfunk_module_setting` | 0.2.0 | `mixed $value`, `string $module_id`, `string $key` | mixed | What `flurfunk_module_setting()` returns: the saved value or the field's default (`null` for unknown keys). |
| `flurfunk_people_query` | 0.1.0 | `array $args` | array | `get_users()` arguments for the people Flurfunk knows (mentions, people list). Default: everyone with `flurfunk_team` or `flurfunk_guest`; assistants are always added. |
| `flurfunk_app_slug` | 0.1.0 | `string $slug` | string | Path of the full-screen app, default `flurfunk` (`/flurfunk/`). Rewrite rules are flushed once after a change. |
| `flurfunk_manifest` | 0.1.0 | `array $manifest` | array | The web app manifest (name, colours, icons, start URL). |
| `flurfunk_menu_icon` | 0.1.0 | `string $icon` | string | The admin menu icon (a `data:image/svg+xml;base64,…` URI, a dashicon name or a URL). |
| `flurfunk_openstation_icon` | 0.1.0 | `array {title, icon_svg, url, position}` | array or false | Desktop icon when the OpenStation plugin is active; false = none. |

Notification kinds for `flurfunk_notify_recipients` are `mention`, `assigned`, `due`, `remind`,
`waiting`, `done`, `reply`, `channel` and `message`; other values are ignored. If several notifications for
the same person and event come together, the most important kind wins (in that order).
Recipients you add are not checked against thread access, so check `Flurfunk\Access\can_see()`
yourself.

`object $thread` is always a row of the `threads` table: `id`, `channel_id`, `ref_key`,
`target_type`, `target_id`, `title`, `body`, `author_id`, `status`, `due_date`, `resolved_by`,
`resolved_at`, `created`, `last_activity` (database strings).

### Actions

| Action | Since | Arguments | Fires |
| --- | --- | --- | --- |
| `flurfunk_thread_created` | 0.1.0 | `object $thread` | After a thread was created (not on import). |
| `flurfunk_message_added` | 0.1.0 | `object $thread`, `int $message_id` | After a reply was added, and after status lines (ticking a task adds a `status` message). Check the message type if you only want replies. Not on import. |
| `flurfunk_message_edited` | 0.1.0 | `object $thread`, `int $message_id` | After a message was edited. |
| `flurfunk_message_deleted` | 0.1.0 | `object $thread`, `int $message_id` | After a message was deleted. |
| `flurfunk_assigned` | 0.1.0 | `object $thread`, `int[] $added`, `int $actor` | After people were made responsible. |
| `flurfunk_status_changed` | 0.1.0 | `object $thread`, `string $status`, `int $actor` | After the task state changed (`open`, `doing`, `waiting`, `done`), or the thread stopped being a task (`''`). |
| `flurfunk_card_action` | 0.2.0 | `object $thread`, `int $message_id`, `string $action`, `int $user_id`, `array $snapshot` | After a card action ran and its event line was added. |
| `flurfunk_modules` | 0.2.0 | | Register modules here with `flurfunk_register_module()` (on `init`, priority 0, so translations work). |
| `flurfunk_modules_loaded` | 0.2.0 | | After the modules that are on have loaded (still `init`, priority 0). |
| `flurfunk_module_on` | 0.2.0 | `string $id` | After a module was switched on (settings or `wp flurfunk module on`), only if it was off. |
| `flurfunk_module_off` | 0.2.0 | `string $id` | After a module was switched off, only if it was on. E.g. to clear its cron events. |
| `flurfunk_settings_sections` | 0.3.0 | | On *Settings → Flurfunk*, after the modules section: modules print their own sections (forms of their own) here. |

Editing a thread's own title or text (`threads/<id>/edit`) fires no action.

### Cron hooks

`flurfunk_remind` (every 5 minutes), `flurfunk_due` and `flurfunk_digest` (hourly),
`flurfunk_cleanup_files` (daily; also prunes notifications), `flurfunk_comments_daily` (daily, only
with the module `flurfunk/comments-daily`). See
[Cron](installation.md#cron-reminders-digests-cleanup).

## WP-CLI

All commands live under `wp flurfunk`.

### `wp flurfunk export`

```
wp flurfunk export [<file>] [--files]
```

| Option | Meaning |
| --- | --- |
| `<file>` | Write to this file instead of the screen. With `--files`: a folder (required). |
| `--files` | Write a bundle folder with `flurfunk.json` and `files/`, for moving to another site. |

### `wp flurfunk import`

```
wp flurfunk import <file>
```

| Option | Meaning |
| --- | --- |
| `<file>` | An export file, or a bundle folder from `export --files`. |

Threads imported before are skipped. People are matched by email. Use the global `--user=<admin>`
to choose who owns imported files (default: the first administrator).

### `wp flurfunk status`

```
wp flurfunk status
```

A table of channels: slug, name, team and guest level, required capability, active, number of threads.

### `wp flurfunk channel`

```
wp flurfunk channel <slug> [--name=<name>] [--topic=<topic>] [--team=<level>] [--guests=<level>] [--position=<n>] [--capability=<cap>]
```

Creates the channel or changes the given details of an existing one. Levels: `all`, `own-posts`,
`none`. `--capability` is required on top of the levels (`--capability=` for none).

### `wp flurfunk bot-create`

```
wp flurfunk bot-create [--name=<name>] [--login=<login>] [--default]
```

| Option | Default | Meaning |
| --- | --- | --- |
| `--name` | `Assistant` (translated) | Display name |
| `--login` | `flurfunk-assistent` | Login name. An existing user with that login is marked as assistant. |
| `--default` | | Use it for `wp flurfunk post` when no `--as` is given |

### `wp flurfunk bot-password`

```
wp flurfunk bot-password <user> [--name=<name>]
```

Creates an application password for an assistant (user ID) and prints login, password and API
URL. The password is shown once. `--name` labels it (default `Agent`).

### `wp flurfunk post`

```
wp flurfunk post [--channel=<slug>] [--thread=<id>] [--ref=<key>] [--title=<title>] [--body=<text>]
                 [--task] [--target=<type:id>] [--file=<files>] [--card=<card>] [--as=<user>]
```

| Option | Meaning |
| --- | --- |
| `--channel` | Channel for a new thread |
| `--thread` | Reply in this thread |
| `--ref` | Reply in the thread with this `ref_key`; if there is none, create it (needs `--channel`) |
| `--title` | Title of a new thread |
| `--body` | Text; `-` reads standard input. Mentions as `<@ID>`. |
| `--task` | The new thread is a task |
| `--target` | Attach a new thread, e.g. `post:12` |
| `--file` | Files to attach, comma-separated paths. They become Flurfunk files. For a new thread they are added as a first reply. |
| `--card` | One card, `provider:json`, e.g. `'flurfunk/post:{"id":12}'` |
| `--as` | Author (user ID). Default: the default assistant. |

The command runs with the permissions of the author: the channel level, the target and the rate
limit apply. Uploaded files are removed again if posting fails.

```bash
wp flurfunk post --channel=general --title="Nightly backup" --body="Done in 4 min." --file=/tmp/backup.log
echo "Build failed on main" | wp flurfunk post --ref=ci:main --channel=dev --title="CI main" --body=-
wp flurfunk post --thread=12 --body="<@7> please have a look" --card='flurfunk/post:{"id":345}'
```

## Posting as an assistant from scripts and CI

1. Create an assistant and an application password (see
   [Assistants](installation.md#assistants-agents-and-scripts)):

   ```bash
   wp flurfunk bot-create --name="CI" --login=ci-bot
   wp flurfunk bot-password <id> --name="GitHub Actions"
   ```

2. Call the REST API with HTTP Basic auth: the assistant's login and the application password.

```bash
SITE=https://example.com
AUTH="ci-bot:abcd efgh ijkl mnop qrst uvwx"

# New thread
curl -sS -u "$AUTH" -H 'Content-Type: application/json' \
  -d '{"channel":"dev","title":"Release 2.4.0","body":"Deployed to production."}' \
  "$SITE/wp-json/flurfunk/v1/threads"

# Reply in thread 12
curl -sS -u "$AUTH" -H 'Content-Type: application/json' \
  -d '{"body":"Smoke tests passed."}' \
  "$SITE/wp-json/flurfunk/v1/threads/12/messages"
```

### One thread per topic: `ref_key`

`POST threads` with a `ref_key` is idempotent: if a thread with that key exists, it is returned
unchanged (title and body of the request are ignored); otherwise it is created. Use it to keep
one thread per branch, server or job, and post the news as replies:

```bash
TID=$(curl -sS -u "$AUTH" -H 'Content-Type: application/json' \
  -d '{"channel":"dev","ref_key":"ci:main","title":"CI: main"}' \
  "$SITE/wp-json/flurfunk/v1/threads" | jq -r .id)

curl -sS -u "$AUTH" -H 'Content-Type: application/json' \
  -d "$(jq -n --arg b "Build #$BUILD failed" '{body: $b}')" \
  "$SITE/wp-json/flurfunk/v1/threads/$TID/messages"
```

Build JSON with `jq` (or another tool) rather than by string concatenation, so quotes and line
breaks in messages do not break the request.

### Files

Upload the file first, then attach its ID to a reply. A file that is already in the media library
can be attached by its attachment ID without uploading it again. A new thread cannot carry files
directly; post them as the first reply.

```bash
FID=$(curl -sS -u "$AUTH" \
  -H 'Content-Disposition: attachment; filename="report.csv"' \
  -H 'Content-Type: text/csv' \
  --data-binary @report.csv \
  "$SITE/wp-json/flurfunk/v1/files" | jq -r .id)

curl -sS -u "$AUTH" -H 'Content-Type: application/json' \
  -d "{\"body\":\"Today's report\",\"files\":[$FID]}" \
  "$SITE/wp-json/flurfunk/v1/threads/$TID/messages"
```

### Mentions, tasks, cards

```json
{
  "channel": "dev",
  "title": "Update the SSL certificate",
  "body": "<@7> the certificate expires in 10 days.",
  "task": true,
  "due": "2026-10-25",
  "assignees": [ 7 ],
  "attachments": [ { "provider": "flurfunk/post", "ref": { "id": 345 } } ]
}
```

Mentions are `<@user-id>`. Only people who can see the thread are stored as mentioned or
assigned. Cards are created through their provider, with the assistant's permissions.

### Things to keep in mind

- The assistant sees channels according to the **team** setting. For a private channel, let it in
  through `flurfunk_channel_level`. A channel that requires a capability other than
  `flurfunk_team`/`flurfunk_guest` is closed to it unless its account has the capability or
  `flurfunk_bot_can` allows it.
- The rate limit (30 per minute) applies. Batch output into one message.
- Text is cut at 20,000 characters per message. Control characters are removed; HTML is stored as plain text and never rendered.
- A request returns the full thread. Pipe through `jq -r .id` or similar if you only need the ID.

More complete scripts (a GitHub Action, a daily report) are on the [examples](examples.md) page.

## Working on Flurfunk itself

| Task | Command |
| --- | --- |
| API and permission checks (100+) | `wp eval-file tests/api-edge.php <team-id> <team2-id> <guest-id>` |
| Browser tests (Playwright) | `cd tests/e2e && npm install && FF_BASE=… FF_TEAM=… FF_TEAM_PASS=… npx playwright test` |
| Web Push crypto against Node | `tests/push/run.sh` |
| Formatter | `node tests/format.test.cjs` |
| Load test (2000 threads, 20000 messages) | `wp eval-file tests/load.php` |
| Collect JavaScript strings | `python3 tools/strings.py` (writes `inc/strings.php`) |
| Build translations | `python3 tools/l10n.py` (from `tools/translations/*.json` to `languages/*.l10n.php`) |
| Card gallery of the website | `node tools/docs-cards.mjs` (from `assets/app.js` and `assets/app.css` to `docs/_includes/card-gallery.html` and `docs/assets/cards.css`) |
| Release zip | `tools/build-zip.sh` |

The e2e tests read `FF_BASE`, `FF_TEAM`, `FF_TEAM_PASS`, `FF_TEAM2`, `FF_TEAM2_PASS`, `FF_GUEST`,
`FF_GUEST_PASS` and optionally `FF_QUERY`; with `FF_SHOTS=<folder>` the card tests also save
screenshots there. `api-edge.php` and `load.php` clean up after themselves.

The card tests need the **test provider** `tests/fixtures/flurfunk-test-provider.php` (a card with
every block and five actions, command `/e2e-card`); without it they are skipped. Load it as a
must-use plugin and switch it on, only on test sites:

```php
// wp-config.php
define( 'FLURFUNK_TEST_PROVIDERS', true );
```

```bash
ln -s ../plugins/flurfunk/tests/fixtures/flurfunk-test-provider.php wp-content/mu-plugins/
# WordPress Playground: --mount=<plugin>/tests/fixtures:/wordpress/wp-content/mu-plugins plus the define,
# or add_filter( 'flurfunk_test_providers', '__return_true' ) in another mu-plugin.
```

To change the schema, bump `DB_VERSION` in `inc/schema.php`. `dbDelta` adds columns and indexes;
anything else (renames, data rewrites) goes into `upgrade()` as a step.

### Demo content

`tests/demo/seed.php` fills a test site with a small fictional editorial team, "Northline
Magazine": five people (editors as team, two authors as guests) and an assistant, the channels
#general, #editorial, #photo, #tech and #authors, four posts with a few reader comments (one
waiting for approval), and about a dozen conversations over the last days, with mentions, tasks in
every state and with due dates (one overdue, one due today), an image gallery, a transcript file,
`/post` and `/comment` cards and notifications for Ana Klein, the editor-in-chief. Roles decide
who is team and who is guest (editors and authors with Flurfunk's default permissions); the
colours stay at Flurfunk's defaults. The screenshots in these docs were taken with it.

```bash
wp eval-file wp-content/plugins/flurfunk/tests/demo/seed.php        # add reset-passwords for new ones
cp wp-content/plugins/flurfunk/tests/demo/demo-avatars.php wp-content/mu-plugins/  # pictures, job titles
```

Without WP-CLI, open the file's address in the browser while logged in as an administrator
(inside the plugin, or copied into the WordPress root) and confirm; or define
`FLURFUNK_DEMO_SECRET` (16+ characters) in `wp-config.php` and add `?secret=…` once. Running it
again adds nothing twice (people by login, posts and comments by a meta key, threads by `ref_key`
`northline-demo:…`). New accounts get random passwords, printed once. Times are relative to the
moment it runs. Only for test and demo sites: it sets the Flurfunk name (if none is set), changes the five
channels' access and creates users with `@northline.example` addresses. The folder is not part of
release zips.
