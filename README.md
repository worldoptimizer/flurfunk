# Flurfunk

Team conversations inside WordPress. Channels, threads and tasks, attachable to posts, with
@mentions, files and cards from other plugins via slash commands. Works in plain wp-admin and
inside the OpenStation desktop.

- **Overview** (`wp-admin/admin.php?page=flurfunk`): sidebar with channels, mentions, open
  tasks and posts with conversations; thread list; thread pane. Your name sits at the bottom of
  the sidebar and opens your settings (light/dark, which mails you get, push, unread badge).
- **Editor panel** "Conversations" for the configured post types.
- **Posts list column** with the number of conversations and open tasks.
- **Tasks** with due dates, responsible people, "My tasks" and reminders.
- **Notifications** in the app, by mail (instant or daily summary) and by Web Push, with quiet
  hours, pause, per-channel levels, follow, mute and "remind me".
- **Team and guests**: each channel decides what the team and what guests (e.g. guest authors)
  see.
- **Assistants** for agents and scripts, posting through WP-CLI or the REST API.
- **Embeds**: threads next to any element on an admin screen (e.g. a checklist).
- **Own tables**, one JSON format (`flurfunk/1`) for export and import with files, privacy
  export/erase.

Requires WordPress 6.5+, PHP 8.1+ and MySQL/MariaDB. Web Push needs HTTPS and PHP's OpenSSL
extension; no external service or library.

## Documentation

| | |
| --- | --- |
| [Overview](docs/index.md) | What Flurfunk does, feature tour |
| [Installation and administration](docs/installation.md) | Requirements, settings, access, assistants, cron, moving sites, privacy, uninstall, troubleshooting |
| [User guide](docs/user-guide.md) | Threads, formatting, mentions, slash commands, files, tasks, notifications, shortcuts |
| [Developer guide](docs/developers.md) | Architecture, data format, REST API, providers, targets, embeds, hooks, WP-CLI |
| [Examples](docs/examples.md) | A provider, a card renderer, a target type, embeds, shell and CI scripts, filters |

## Quick start

```bash
cd wp-content/plugins && git clone https://github.com/worldoptimizer/flurfunk.git flurfunk
wp plugin activate flurfunk
```

Then open *Settings → Flurfunk* (name, logo, colours, who is team or guest, channels) and
*Flurfunk* in the admin menu.

## Assistants (agents, scripts)

```bash
wp flurfunk bot-create --name="Assistant" --default   # account without editing rights
wp flurfunk bot-password <id>                          # application password for the REST API
wp flurfunk post --channel=general --title="Report" --body="…" --file=report.csv
wp flurfunk post --ref=checklist:billing --card='flurfunk/post:{"id":12}'
```

```bash
curl -u "login:application password" -H 'Content-Type: application/json' \
  -d '{"channel":"general","title":"Deploy","body":"Release 2.4.0 is live."}' \
  https://example.com/wp-json/flurfunk/v1/threads
```

See [posting as an assistant](docs/developers.md#posting-as-an-assistant-from-scripts-and-ci).

## Data

```bash
wp flurfunk export backup.json
wp flurfunk export bundle/ --files     # with all files, for moving to another site
wp flurfunk import bundle/
wp flurfunk status
wp flurfunk channel news --name="News" --guests=own-posts
```

## Extending

A slash command with cards:

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
			'url'    => admin_url( 'admin.php?page=reports' ),
		),
		'can'      => fn ( int $user_id ) => user_can( $user_id, 'edit_posts' ),
	) );
} );
```

Also available: targets (`flurfunk_register_target`), channels from code
(`flurfunk_ensure_channel`), badges (`flurfunk_unread_count`), links (`flurfunk_url`), custom card
renderers (`Flurfunk.registerCard`), embeds (`Flurfunk.mountRefs`, `mountChannel`,
`mountTarget`) and filters and actions for access, notifications, mail and push. All of it is in
the [developer guide](docs/developers.md).

## Integrations

Optional cards for other plugins live in `integrations/` and load only when that plugin is active:

- **MailerPress**: `/newsletter` inserts a campaign with status, lists and subscribed recipients,
  numbers after sending, and a link into the editor (team only).

## Tests and development

- `wp eval-file tests/api-edge.php <team> <team2> <guest>`: 100+ checks on rights, input,
  mentions, read state, paging, editing, targets, mails, export/import. Cleans up after itself.
- `tests/e2e`: Playwright against a running site (`FF_BASE`, `FF_TEAM`, `FF_TEAM_PASS`,
  `FF_TEAM2`, `FF_TEAM2_PASS`, `FF_GUEST`, `FF_GUEST_PASS`, optional `FF_QUERY`).
- `tests/push/run.sh`: Web Push crypto checked against Node.
- `node tests/format.test.cjs`: the text formatter.
- `wp eval-file tests/load.php`: 2000 threads / 20000 messages, timings, cleans up.
- `tools/strings.py` collects the JavaScript strings into `inc/strings.php`; `tools/l10n.py`
  builds `languages/*.l10n.php` from `tools/translations/*.json`; `tools/build-zip.sh` builds a
  release zip.

License: GPL-2.0-or-later.
