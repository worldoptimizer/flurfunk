# Flurfunk

Team conversations inside WordPress. Channels, threads and tasks, attachable to posts, with
@mentions, files from the media library and cards from other plugins via slash commands.
Works in plain wp-admin and inside the OpenStation
desktop.

- **Overview** (`wp-admin/admin.php?page=flurfunk`): sidebar with channels, mentions, open
  tasks and posts with conversations; thread list; thread pane. Your name sits at the bottom of
  the sidebar and opens your settings (light/dark, which mails you get, unread badge).
- **Editor panel** "Conversations" for the configured post types.
- **Posts list column** with the number of conversations and open tasks.
- **Embeds**: threads next to any element on an admin screen (e.g. a checklist).

Everything site-specific (logo, colours, who is team or guest, channels) is set under
*Settings → Flurfunk* or added by another plugin through the API below.

## Files

Files attached in Flurfunk are normal WordPress attachments stored in `uploads/flurfunk/<year>/<month>/<random>/`
and hidden from the media library. Images show as previews, audio and video as players. "Add to
media library" only removes the mark – the file keeps its place and address, so nothing that
uses it breaks. Hidden files nothing refers to any more are deleted after 30 days.

## Assistants (agents, scripts)

```bash
wp flurfunk bot-create --name="Assistent" --default   # account without editing rights
wp flurfunk bot-password <id>                          # application password for the REST API
wp flurfunk post --channel=general --title="Report" --body="…" --file=report.csv
wp flurfunk post --ref=checklist:billing --card='flurfunk/post:{"id":12}'
```

Assistants take part like the team (including private channels they are invited to through
`flurfunk_channel_level`) and are marked "Assistant" in the conversation.

## Tasks

Any thread can be a task: tick box, due date, people responsible. Changes show up as events in
the thread. "My tasks" lists open tasks assigned to you by due date; overdue ones are marked
red, also in the sidebar. Responsible people are notified when they get a task and reminded on
the due date (once a day, at their chosen hour).

## Notifications

One dispatcher, several channels. What counts:

| Kind | When |
| --- | --- |
| mention | someone wrote @you |
| assigned | you were made responsible for a task |
| due | a task of yours is due today or overdue (daily, once) |
| reply | new message in a thread you started, wrote in, are responsible for or follow |
| done | a task you started or are responsible for was ticked off |
| channel | a new post in a channel set to "every post" |
| message | anything else you can see – only if you chose "everything" |

Every notification lands in the list behind the bell (read ones are cleared after 90 days,
unread ones after 180; filter `flurfunk_notification_days`). Each person decides, under their
name at the bottom of the sidebar:

- **Mail**: right away (mentions, tasks, replies), once a day as a summary, everything, never.
- **Push** to this device (browser or phone, also when Flurfunk is closed): mentions/tasks/
  replies, everything, nothing – with quiet hours.
- **System notifications while Flurfunk is open in the background** (without push).
- **Hour** for the daily summary and due reminders.
- Per channel, in the channel header: **every post**, **only mentions** or as usual.
- Per thread: **follow** (every reply) or **mute** (only mentions and tasks).

Push needs HTTPS and PHP's OpenSSL extension (standard); keys (VAPID) are created
automatically, no external service or library. On iPhone/iPad, push works once Flurfunk is
added to the home screen (the page ships a web app manifest). The service worker only receives
pushes; it controls no pages.

## People and access

A person is **team** or **guest** (capabilities chosen in the settings; default
`edit_others_posts` and `edit_posts`). Each channel says what the team and what guests see:
`all`, `own-posts` (only threads attached to their own posts) or `none`. Guests therefore
never see internal conversations about their text unless a channel allows it.

## Data

Own tables (`{prefix}flurfunk_*`): channels, threads, messages, mentions, reads, attachments,
assignees, notifications, push. Personal settings live in user meta (`flurfunk_prefs_<key>`). Import and export use one JSON
format, `flurfunk/1`:

```bash
wp flurfunk export backup.json
wp flurfunk import backup.json
wp flurfunk export bundle/ --files     # with all files, for moving to another site
wp flurfunk import bundle/
wp flurfunk status
wp flurfunk channel news --name="News" --guests=own-posts
```

## Extending

### Slash commands and cards

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
			'url'    => admin_url( '…' ),
		),
		'can'      => fn ( int $user_id ) => user_can( $user_id, 'edit_posts' ),
	) );
} );
```

The snapshot is stored with the message, so it stays readable after the source is gone.
Card fields: `title`, `subtitle`, `text`, `url`, `image`, `audio`, `metric {value,label}`,
`spark []`, `meta []`, `target {type,id}`, `action {label,url}`, `data` (free, for your own
renderer). A custom look in JavaScript:

```js
window.Flurfunk.registerCard( 'myplugin/report', ( s, h ) => '<b>' + h.esc( s.title ) + '</b>' );
```

### Targets

Threads can hang on a post (built in) or on anything a plugin registers:

```php
flurfunk_register_target( 'dossier', array(
	'label'  => 'Dossier',
	'search' => fn ( string $q, int $user_id ) => array( array( 'id' => 12, 'title' => 'Balkan', 'subtitle' => '38 posts' ) ),
	'info'   => fn ( int $id ) => array( 'title' => 'Balkan', 'url' => admin_url( '…' ) ), // null = gone
	'can'    => fn ( int $user_id, int $id ) => true,                                       // default: team only
) );
```

The "Attached to" picker searches all registered types. Guests only ever see their own posts.

### Functions

- `flurfunk_register_target( $type, $args )` – something threads can be attached to.
- `flurfunk_ensure_channel( $slug, $args )` – create a channel your plugin needs.
- `flurfunk_unread_count( $user_id )` – for badges elsewhere.
- `flurfunk_url( $view )` – link into the app (`channel:news`, `target:post:12`, `thread:5`).

### Filters

| Filter | Purpose |
| --- | --- |
| `flurfunk_settings` | adjust settings in code |
| `flurfunk_user_kind` | `team`, `guest` or `''` for a user |
| `flurfunk_channel_level` | per-person access to a channel (private channels) |
| `flurfunk_owned_post_ids` | posts that count as a person's own (co-authors) |
| `flurfunk_post_owners` | who owns a post (for the "can read this" hint) |
| `flurfunk_can_target` | may a person attach a thread to a target |
| `flurfunk_can_resolve` | may a person tick a task off |
| `flurfunk_can_edit` | may a person edit or delete a message or thread (default: author and admins) |
| `flurfunk_target_info` | title and link of a target |
| `flurfunk_user_role_label` | role shown next to names |
| `flurfunk_notify_recipients` | user => kind for a new thread or message |
| `flurfunk_mail` | mail subject/body (false = skip) |
| `flurfunk_push_payload` | title, body, url, tag, icon of a push message |
| `flurfunk_rate_limit` | messages per minute and person (default 30, admins exempt) |
| `flurfunk_thread_limit` | messages loaded per thread (default 300) |
| `flurfunk_openstation_icon` | desktop icon arguments (false = none) |

### Actions

`flurfunk_thread_created( $thread )`, `flurfunk_message_added( $thread, $message_id )`,
`flurfunk_message_edited( $thread, $message_id )`, `flurfunk_message_deleted( $thread, $message_id )`,
`flurfunk_assigned( $thread, $user_ids, $actor )`, `flurfunk_status_changed( $thread, $status, $actor )`.

### Embeds

```php
Flurfunk\Admin\enqueue();
```
```html
<div id="list">
  <div data-flurfunk-ref="checklist:billing" data-title="Billing" data-task="1">…</div>
</div>
<script>Flurfunk.mountRefs( document.getElementById( 'list' ), { prefix: 'checklist:', channel: 'general' } );</script>
```

A thread is created on the first reply or tick, so nothing needs seeding.

A whole channel as a numbered checklist (open items first, done below, new items at the end):

```js
Flurfunk.mountChannel( element, { channel: 'questions', newLabel: 'New question' } );
```

Cards can be refreshed in place ("Update"), which asks the provider for a new snapshot.

Personal data: Flurfunk takes part in WordPress's export and erase tools. Deleting a user
hands their messages to the person who receives their posts, or keeps them as "deleted user".

## Integrations

Optional cards for other plugins live in `integrations/` and load only when that plugin is active:

- **MailerPress** – `/newsletter`: campaign with status, lists and subscribed recipients, numbers after sending, link into the editor (team only).

## Tests

- `wp eval-file tests/api-edge.php <team> <team2> <guest>` – 100+ checks on rights, input, mentions,
  read state, paging, editing, targets, mails, export/import. Works on staging, cleans up after itself.
- `tests/e2e` – Playwright against a running site (`FF_BASE`, `FF_TEAM`, `FF_TEAM_PASS`, `FF_TEAM2`,
  `FF_TEAM2_PASS`, `FF_GUEST`, `FF_GUEST_PASS`, optional `FF_QUERY`): layout at several widths,
  escaping, mentions, slash menu, picker, edit/delete, drafts, expired nonce, logout, accessibility.

- `tests/push/run.sh` – Web Push crypto checked against Node (decrypt, ES256 signature).
- `node tests/format.test.cjs` – the text formatter.
- `wp eval-file tests/load.php` – 2000 threads / 20000 messages, timings, cleans up.

## Development

- `tools/strings.py` – collect the JavaScript strings into `inc/strings.php`.
- `tools/l10n.py` – build `languages/*.l10n.php` from `tools/translations/*.json`.

License: GPL-2.0-or-later.
