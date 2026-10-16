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

## People and access

A person is **team** or **guest** (capabilities chosen in the settings; default
`edit_others_posts` and `edit_posts`). Each channel says what the team and what guests see:
`all`, `own-posts` (only threads attached to their own posts) or `none`. Guests therefore
never see internal conversations about their text unless a channel allows it.

## Data

Own tables (`{prefix}flurfunk_*`): channels, threads, messages, mentions, reads, attachments.
Personal settings live in the user meta `flurfunk_prefs`. Import and export use one JSON
format, `flurfunk/1`:

```bash
wp flurfunk export backup.json
wp flurfunk import backup.json
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

### Functions

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
| `flurfunk_target_info` | title and link of a target |
| `flurfunk_user_role_label` | role shown next to names |
| `flurfunk_notify_recipients`, `flurfunk_mail` | who gets mails, mail content (false = skip) |
| `flurfunk_openstation_icon` | desktop icon arguments (false = none) |

### Actions

`flurfunk_thread_created( $thread )`, `flurfunk_message_added( $thread, $message_id )`.

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

## Development

- `tools/strings.py` – collect the JavaScript strings into `inc/strings.php`.
- `tools/l10n.py` – build `languages/*.l10n.php` from `tools/translations/*.json`.

License: GPL-2.0-or-later.
