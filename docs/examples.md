# Examples

Complete examples to copy and adapt. Each PHP example is a single file that works as a
must-use plugin: save it in `wp-content/mu-plugins/` and it is active. As a normal plugin it
works the same way.

All examples check that Flurfunk is active before using it, so they do no harm when it is not.

- [A provider: order status cards](#a-provider-order-status-cards)
- [A custom card renderer](#a-custom-card-renderer)
- [A custom target type: categories](#a-custom-target-type-categories)
- [Embedding: a checklist page with threads](#embedding-a-checklist-page-with-threads)
- [Posting from a shell script](#posting-from-a-shell-script)
- [Posting from GitHub Actions](#posting-from-github-actions)
- [A daily report with WP-CLI](#a-daily-report-with-wp-cli)
- [Filters](#filters)
- [Actions: a webhook for new threads](#actions-a-webhook-for-new-threads)

See the [developer guide](developers.md) for the full reference.

## A provider: order status cards

`/order 1042` inserts a card with the status of an order from an external shop API. The card
shows the order number, status, total, items and a button into the shop.

The example expects the shop to answer `GET https://shop.example.com/api/orders/1042` with JSON
like this; adapt `myshop_order()` to your API:

```json
{
  "number": "1042",
  "status": "shipped",
  "total": "129.90 EUR",
  "customer": "Jane Doe",
  "items": 3,
  "date": "2026-10-14",
  "url": "https://shop.example.com/admin/orders/1042"
}
```

`wp-content/mu-plugins/flurfunk-orders.php`:

```php
<?php
/**
 * Plugin Name: Flurfunk order cards
 * Description: /order inserts the status of a shop order as a Flurfunk card.
 */

defined( 'ABSPATH' ) || exit;

const MYSHOP_API = 'https://shop.example.com/api/orders/';

/**
 * Fetch one order from the shop (cached for five minutes).
 *
 * @param int $id Order number.
 * @return array|null Order data, or null if it does not exist or the shop is unreachable.
 */
function myshop_order( int $id ): ?array {
	$key   = 'myshop_order_' . $id;
	$order = get_transient( $key );
	if ( is_array( $order ) ) {
		return $order;
	}
	$res = wp_remote_get(
		MYSHOP_API . $id,
		array(
			'timeout' => 5,
			'headers' => array( 'Accept' => 'application/json' ),
		)
	);
	if ( 200 !== wp_remote_retrieve_response_code( $res ) ) {
		return null;
	}
	$order = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( ! is_array( $order ) ) {
		return null;
	}
	set_transient( $key, $order, 5 * MINUTE_IN_SECONDS );
	return $order;
}

add_action(
	'init',
	static function (): void {
		if ( ! function_exists( 'flurfunk_register_provider' ) ) {
			return;
		}
		flurfunk_register_provider(
			'myshop/order',
			array(
				'command'  => 'order',
				'label'    => 'Order',
				'hint'     => 'Insert an order by its number',

				// Only the team: guests never see customer data.
				'can'      => static fn ( int $user_id ): bool => user_can( $user_id, 'edit_others_posts' ),

				// "/order 1042" or "/order #1042": suggest that order if it exists.
				'search'   => static function ( string $q, int $user_id ): array {
					$id = absint( ltrim( trim( $q ), '#' ) );
					if ( ! $id ) {
						return array();
					}
					$order = myshop_order( $id );
					if ( ! $order ) {
						return array();
					}
					return array(
						array(
							'ref'      => array( 'id' => $id ),
							'title'    => 'Order #' . $id,
							'subtitle' => implode( ' · ', array_filter( array( (string) ( $order['customer'] ?? '' ), (string) ( $order['status'] ?? '' ) ) ) ),
						),
					);
				},

				// The card data. Stored with the message; "Update" calls this again.
				'snapshot' => static function ( array $ref, int $user_id ): ?array {
					$id    = absint( $ref['id'] ?? 0 );
					$order = $id ? myshop_order( $id ) : null;
					if ( ! $order ) {
						return null;
					}
					$url = esc_url_raw( (string) ( $order['url'] ?? '' ) );
					return array(
						'title'    => 'Order #' . ( $order['number'] ?? $id ),
						'subtitle' => ucfirst( (string) ( $order['status'] ?? '' ) ),
						'metric'   => array(
							'value' => (string) ( $order['total'] ?? '' ),
							'label' => 'total',
						),
						'meta'     => array_values(
							array_filter(
								array(
									(string) ( $order['customer'] ?? '' ),
									isset( $order['items'] ) ? (int) $order['items'] . ' items' : '',
									(string) ( $order['date'] ?? '' ),
								)
							)
						),
						'url'      => $url,
						'action'   => $url ? array(
							'label' => 'Open in the shop',
							'url'   => $url,
						) : null,
						// Free data for a custom renderer (see the next example).
						'data'     => array( 'status' => (string) ( $order['status'] ?? '' ) ),
					);
				},
			)
		);
	}
);
```

Notes:

- Returning `null` from `snapshot` on insert drops the card silently. On "Update" the old
  snapshot stays and the person sees "This card cannot be updated (any more)". If your API can
  be down for a while, consider caching the last good answer longer.
- Keep `ref` small and stable. Here it is just the order number.

## A custom card renderer

The default card already shows the order nicely. This renderer adds a coloured status badge,
using the `data` the provider stored.

`wp-content/mu-plugins/flurfunk-orders-look.php`:

```php
<?php
/**
 * Plugin Name: Flurfunk order cards look
 * Description: A custom renderer for myshop/order cards.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Add the renderer wherever Flurfunk's script is loaded (app page, editor panel, embeds).
 * Priority 20: after Flurfunk enqueued its script at the default priority.
 */
function myshop_card_renderer(): void {
	if ( ! wp_script_is( 'flurfunk', 'enqueued' ) ) {
		return;
	}
	$js = <<<'JS'
window.Flurfunk.registerCard( 'myshop/order', function ( s, h ) {
	var colours = { pending: '#b26a00', processing: '#0b62a4', shipped: '#2e7d32', cancelled: '#b3261e' };
	var status = ( s.data && s.data.status ) || '';
	var badge = status
		? '<span style="display:inline-block;padding:0 .5em;border-radius:1em;color:#fff;background:' + ( colours[ status ] || '#666' ) + '">' + h.esc( status ) + '</span>'
		: '';
	return '<div class="ff-card-body">'
		+ '<b class="ff-card-title">' + ( s.url ? '<a href="' + h.esc( s.url ) + '" target="_top">' + h.esc( s.title ) + '</a>' : h.esc( s.title ) ) + '</b> ' + badge
		+ ( s.metric ? '<span class="ff-card-metric">' + h.esc( s.metric.value ) + ' <small>' + h.esc( s.metric.label ) + '</small></span>' : '' )
		+ ( s.meta ? '<span class="ff-card-meta">' + s.meta.map( h.esc ).join( ' · ' ) + '</span>' : '' )
		+ ( s.action ? '<a class="ff-card-action" href="' + h.esc( s.action.url ) + '" target="_top">' + h.esc( s.action.label ) + '</a>' : '' )
		+ '</div>';
} );
JS;
	wp_add_inline_script( 'flurfunk', $js );
}
add_action( 'admin_enqueue_scripts', 'myshop_card_renderer', 20 );
add_action( 'enqueue_block_editor_assets', 'myshop_card_renderer', 20 );
```

Escape every value with `h.esc()`: the returned HTML is inserted as it is.

## A custom target type: categories

Lets the team attach threads to a category ("Which topics do we still need for this
section?"). The "Attached to" picker then finds categories as well as posts, and the category
list gets a "Conversations" link.

`wp-content/mu-plugins/flurfunk-category-target.php`:

```php
<?php
/**
 * Plugin Name: Flurfunk category conversations
 * Description: Threads can be attached to categories.
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'init',
	static function (): void {
		if ( ! function_exists( 'flurfunk_register_target' ) ) {
			return;
		}
		flurfunk_register_target(
			'category',
			array(
				'label'  => 'Category',

				// Items for the "Attached to" picker; $q is empty for the first list.
				'search' => static function ( string $q, int $user_id ): array {
					$terms = get_terms(
						array(
							'taxonomy'   => 'category',
							'hide_empty' => false,
							'number'     => 15,
							'search'     => $q,
						)
					);
					if ( is_wp_error( $terms ) ) {
						return array();
					}
					return array_map(
						static fn ( WP_Term $t ): array => array(
							'id'       => $t->term_id,
							'title'    => $t->name,
							'subtitle' => sprintf( '%d posts', $t->count ),
						),
						$terms
					);
				},

				// Title and link; null when the category was deleted.
				'info'   => static function ( int $id ): ?array {
					$t = get_term( $id, 'category' );
					if ( ! $t instanceof WP_Term ) {
						return null;
					}
					$link = current_user_can( 'manage_categories' ) ? get_edit_term_link( $t->term_id, 'category' ) : get_term_link( $t );
					return array(
						'title'  => $t->name,
						'url'    => is_string( $link ) ? $link : '',
						'status' => sprintf( '%d posts', $t->count ),
					);
				},

				// Who may attach threads to a category.
				'can'    => static fn ( int $user_id, int $id ): bool => user_can( $user_id, 'edit_others_posts' ) && get_term( $id, 'category' ) instanceof WP_Term,
			)
		);
	}
);

// "Conversations" link in the row actions of Posts → Categories.
add_filter(
	'category_row_actions',
	static function ( array $actions, WP_Term $term ): array {
		if ( function_exists( 'flurfunk_url' ) ) {
			$actions['flurfunk'] = '<a href="' . esc_url( flurfunk_url( 'target:category:' . $term->term_id ) ) . '">Conversations</a>';
		}
		return $actions;
	},
	10,
	2
);
```

Use only lowercase letters and underscores in the type key (`category`, `customer_account`),
because `GET threads?target=` only accepts `[a-z_]+:<id>`.

## Embedding: a checklist page with threads

An admin page with a launch checklist. Every item gets its own thread (created on the first reply
or tick), and below it the whole `#launch-questions` channel is shown as a numbered list.

`wp-content/mu-plugins/flurfunk-launch-checklist.php`:

```php
<?php
/**
 * Plugin Name: Launch checklist
 * Description: A checklist page whose items carry Flurfunk threads.
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'admin_menu',
	static function (): void {
		if ( ! function_exists( 'flurfunk_ensure_channel' ) ) {
			return;
		}
		$hook = add_menu_page( 'Launch', 'Launch', 'edit_others_posts', 'launch-checklist', 'launch_checklist_render', 'dashicons-yes-alt', 4 );
		add_action(
			'load-' . $hook,
			static function (): void {
				// The channels this page uses; created once, then left alone.
				flurfunk_ensure_channel(
					'launch',
					array(
						'name'          => 'Launch',
						'topic'         => 'Checklist items',
						'access_team'   => 'all',
						'access_guests' => 'none',
					)
				);
				flurfunk_ensure_channel( 'launch-questions', array( 'name' => 'Launch questions' ) );
				add_action( 'admin_enqueue_scripts', 'Flurfunk\Admin\enqueue' );
			}
		);
	}
);

/**
 * The page.
 */
function launch_checklist_render(): void {
	$items = array(
		'dns'     => array( 'DNS switched', 'Who has access to the registrar?' ),
		'ssl'     => array( 'Certificate valid', '' ),
		'imprint' => array( 'Legal notice updated', 'Is the address current?' ),
	);
	echo '<div class="wrap"><h1>Launch checklist</h1><ol id="launch-list">';
	foreach ( $items as $key => $item ) {
		printf(
			'<li data-flurfunk-ref="%s" data-title="%s" data-body="%s" data-task="1"><strong>%s</strong></li>',
			esc_attr( 'launch:' . $key ),
			esc_attr( $item[0] ),
			esc_attr( $item[1] ),
			esc_html( $item[0] )
		);
	}
	echo '</ol><h2>Open questions</h2><div id="launch-questions"></div></div>';
	?>
	<script>
	document.addEventListener( 'DOMContentLoaded', function () {
		Flurfunk.mountRefs( document.getElementById( 'launch-list' ), { prefix: 'launch:', channel: 'launch' } );
		Flurfunk.mountChannel( document.getElementById( 'launch-questions' ), { channel: 'launch-questions', newLabel: 'New question', titleLabel: 'Question' } );
	} );
	</script>
	<?php
}
```

The ref keys (`launch:dns`, …) must be unique across the site. Use a prefix per page or
feature.

## Posting from a shell script

A small script for servers, deploy hooks or cron jobs outside WordPress. It reads the message
from standard input and attaches any files given as arguments. With `FLURFUNK_REF` set, all posts
for that key go into one thread as replies.

Requirements: `bash`, `curl`, `jq`, and an assistant with an application password (see
[Assistants](installation.md#assistants-agents-and-scripts)).

`flurfunk-post.sh`:

```bash
#!/usr/bin/env bash
# Post to Flurfunk.
#
#   echo "Backup done" | flurfunk-post.sh <channel> <title> [file …]
#
# Environment:
#   FLURFUNK_SITE  https://example.com
#   FLURFUNK_AUTH  "login:application password"
#   FLURFUNK_REF   optional key: one thread per key, new posts become replies
set -euo pipefail

channel=${1:?channel}
title=${2:?title}
shift 2
api="${FLURFUNK_SITE%/}/wp-json/flurfunk/v1"
body=$(cat)

call() { curl -fsS -u "$FLURFUNK_AUTH" "$@"; }
post_json() { call -H 'Content-Type: application/json' -d "$2" "$api/$1"; }

# Upload files first; collect their IDs.
files='[]'
for f in "$@"; do
	name=$(jq -rn --arg n "$(basename "$f")" '$n|@uri')
	type=$(file -b --mime-type "$f" 2>/dev/null || echo application/octet-stream)
	id=$(call -H "Content-Disposition: attachment; filename=\"$name\"" -H "Content-Type: $type" \
		--data-binary "@$f" "$api/files" | jq -r .id)
	files=$(jq -c --argjson id "$id" '. + [$id]' <<<"$files")
done

if [ -n "${FLURFUNK_REF:-}" ]; then
	# Find or create the thread for this key, then reply.
	tid=$(post_json threads "$(jq -n --arg c "$channel" --arg t "$title" --arg r "$FLURFUNK_REF" \
		'{channel: $c, title: $t, ref_key: $r}')" | jq -r .id)
	post_json "threads/$tid/messages" "$(jq -n --arg b "$body" --argjson f "$files" '{body: $b, files: $f}')" >/dev/null
else
	# New thread with the text; files follow as the first reply.
	tid=$(post_json threads "$(jq -n --arg c "$channel" --arg t "$title" --arg b "$body" \
		'{channel: $c, title: $t, body: $b}')" | jq -r .id)
	if [ "$files" != '[]' ]; then
		post_json "threads/$tid/messages" "$(jq -n --argjson f "$files" '{body: "", files: $f}')" >/dev/null
	fi
fi

echo "${FLURFUNK_SITE%/}/wp-admin/admin.php?page=flurfunk&view=thread:$tid"
```

Usage:

```bash
export FLURFUNK_SITE=https://example.com
export FLURFUNK_AUTH="backup-bot:abcd efgh ijkl mnop qrst uvwx"

echo "Nightly backup finished in 4 minutes." | ./flurfunk-post.sh ops "Backup" /var/log/backup.log
FLURFUNK_REF=server:web1 ./flurfunk-post.sh ops "web1" < /tmp/disk-warning.txt
```

## Posting from GitHub Actions

Posts the result of every deploy of `main` into one thread per repository and branch. Add two
repository secrets: `FLURFUNK_SITE` (`https://example.com`) and `FLURFUNK_AUTH`
(`login:application password` of an assistant). `jq` is preinstalled on GitHub's Ubuntu runners.

`.github/workflows/deploy.yml` (the relevant step):

<!-- {% raw %} -->

```yaml
name: Deploy
on:
  push:
    branches: [ main ]

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      # … build and deploy steps …

      - name: Tell the team in Flurfunk
        if: always()
        env:
          FLURFUNK_SITE: ${{ secrets.FLURFUNK_SITE }}
          FLURFUNK_AUTH: ${{ secrets.FLURFUNK_AUTH }}
          STATUS: ${{ job.status }}
        run: |
          set -euo pipefail
          api="${FLURFUNK_SITE%/}/wp-json/flurfunk/v1"
          thread=$(jq -n \
            --arg ref "ci:$GITHUB_REPOSITORY:$GITHUB_REF_NAME" \
            --arg title "Deploys: $GITHUB_REPOSITORY ($GITHUB_REF_NAME)" \
            '{channel: "dev", ref_key: $ref, title: $title}')
          tid=$(curl -fsS -u "$FLURFUNK_AUTH" -H 'Content-Type: application/json' \
            -d "$thread" "$api/threads" | jq -r .id)
          message=$(jq -n \
            --arg status "$STATUS" \
            --arg sha "${GITHUB_SHA::7}" \
            --arg subject "$(git log -1 --pretty=%s)" \
            --arg url "$GITHUB_SERVER_URL/$GITHUB_REPOSITORY/actions/runs/$GITHUB_RUN_ID" \
            '{body: ("Deploy *" + $status + "* for `" + $sha + "` " + $subject + "\n" + $url)}')
          curl -fsS -u "$FLURFUNK_AUTH" -H 'Content-Type: application/json' \
            -d "$message" "$api/threads/$tid/messages" > /dev/null
```
<!-- {% endraw %} -->

To ping someone on failures, add a mention to the body, for example
`(if $status == "failure" then " <@7>" else "" end)` in the jq expression (7 is the user ID).

## A daily report with WP-CLI

Runs on the server itself, posts a short daily summary as the default assistant, attaches a CSV of
yesterday's posts and mentions the person who reviews pending posts.

Once:

```bash
wp flurfunk bot-create --name="Report bot" --login=report-bot --default
wp flurfunk channel reports --name="Reports" --topic="Daily numbers" --team=all --guests=none
```

`/usr/local/bin/flurfunk-daily-report`:

```bash
#!/usr/bin/env bash
# Daily site report into the Flurfunk channel #reports. Run from cron.
set -euo pipefail

WP="wp --path=/var/www/example.com --quiet"
REVIEWER=7                                # user ID to mention when posts wait for review

y=$(date -d yesterday +%Y)                # GNU date (Linux)
m=$(date -d yesterday +%-m)
d=$(date -d yesterday +%-d)
day=$(date -d yesterday +%F)

published=$($WP post list --post_type=post --post_status=publish --year="$y" --monthnum="$m" --day="$d" --format=count)
pending=$($WP post list --post_type=post --post_status=pending --format=count)
comments=$($WP comment list --status=hold --format=count)
updates=$($WP plugin list --update=available --format=count)

csv=$(mktemp --suffix=.csv)
trap 'rm -f "$csv"' EXIT
$WP post list --post_type=post --post_status=publish --year="$y" --monthnum="$m" --day="$d" \
	--fields=ID,post_title,post_author,post_date --format=csv > "$csv"

{
	echo "*Report for $day*"
	echo "- Published: $published"
	echo "- Waiting for review: $pending"
	echo "- Comments to moderate: $comments"
	echo "- Plugin updates: $updates"
	if [ "$pending" -gt 0 ]; then
		echo
		echo "<@$REVIEWER> there are posts waiting for you."
	fi
} | $WP flurfunk post --channel=reports --ref="report:$day" --title="Report $day" --body=- --file="$csv"
```

Cron entry (every day at 7:00):

```cron
0 7 * * * /usr/local/bin/flurfunk-daily-report >> /var/log/flurfunk-report.log 2>&1
```

The `--ref` makes reruns on the same day reply in that day's thread instead of starting a new
one. The CSV becomes a Flurfunk file attached as the first reply.

## Filters

All snippets go into one mu-plugin or into your theme's or plugin's code.

### Authors count as team

By default editors and up are team, authors and contributors are guests. This makes authors team
members while contributors stay guests:

```php
add_filter(
	'flurfunk_user_kind',
	static function ( string $kind, WP_User $user ): string {
		if ( 'guest' === $kind && in_array( 'author', (array) $user->roles, true ) ) {
			return 'team';
		}
		return $kind;
	},
	10,
	2
);
```

The list of people you can mention contains users with `edit_posts` and assistants. If you make
users without `edit_posts` team or guests, they can use Flurfunk but will not appear in that list,
cannot be mentioned or assigned from the app, and get no notifications about new threads,
replies or mentions.

### A private channel

Only some people see `#management`; for everyone else it does not exist:

```php
add_filter(
	'flurfunk_channel_level',
	static function ( string $level, object $channel, int $user_id, string $kind ): string {
		if ( 'management' !== $channel->slug ) {
			return $level;
		}
		$members = array( 1, 7, 12 ); // user IDs
		return in_array( $user_id, $members, true ) ? 'all' : 'none';
	},
	10,
	4
);
```

Add an assistant's user ID to `$members` if a script should post there.

### Co-authors own posts too

If co-authors are stored as `co_author` post meta (one row per user ID), they should see the
threads about those posts in `own-posts` channels, and appear in the "… can read this" hint:

```php
add_filter(
	'flurfunk_owned_post_ids',
	static function ( array $ids, int $user_id ): array {
		$co = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'meta_key'       => 'co_author', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $user_id,    // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return array_merge( $ids, $co );
	},
	10,
	2
);

add_filter(
	'flurfunk_post_owners',
	static fn ( array $owners, WP_Post $post ): array => array_merge( $owners, array_map( 'intval', get_post_meta( $post->ID, 'co_author' ) ) ),
	10,
	2
);
```

### Notify a channel lead about every new thread

The lead of `#support` (user 7) gets a "channel" notification for each new thread there, even if
they did not set the channel to "every new post":

```php
add_filter(
	'flurfunk_notify_recipients',
	static function ( array $to, object $thread, int $message_id ): array {
		$lead = 7;
		// Only new threads (message 0), only in #support, not your own.
		if ( 0 !== $message_id || (int) $thread->author_id === $lead || isset( $to[ $lead ] ) ) {
			return $to;
		}
		// flurfunk_ensure_channel() returns the ID (and would create the channel if missing).
		if ( (int) $thread->channel_id !== flurfunk_ensure_channel( 'support' ) ) {
			return $to;
		}
		// Recipients added here are not checked by Flurfunk.
		if ( Flurfunk\Access\can_see( $thread, $lead ) ) {
			$to[ $lead ] = 'channel';
		}
		return $to;
	},
	10,
	3
);
```

The person still decides by their mail and push settings how the notification reaches them.

### Push without message text

Lock screens show push messages. This keeps the headline ("Ada mentioned you") but hides the
text:

```php
add_filter(
	'flurfunk_push_payload',
	static function ( array $payload, array $n ): array {
		$payload['body'] = 'Open Flurfunk to read it.';
		return $payload;
	},
	10,
	2
);
```

`$n['kind']` tells you what the notification is about (`mention`, `reply`, `due`, …) if you want
to treat kinds differently.

### Mail subject and footer

```php
add_filter(
	'flurfunk_mail',
	static function ( $mail, WP_User $user, ?object $thread, int $message_id ) {
		if ( ! is_array( $mail ) ) {
			return $mail;
		}
		$mail['subject'] = str_replace( '[', '[Team · ', $mail['subject'] );
		$mail['body']   .= "\n\n--\nQuestions about Flurfunk? Ask in #general.";
		return $mail;
	},
	10,
	4
);
```

`$thread` is `null` for the daily summary. Return `false` to skip a mail.

### A higher rate limit for one assistant

```php
add_filter(
	'flurfunk_rate_limit',
	static fn ( int $max, int $user_id ): int => 42 === $user_id ? 300 : $max,
	10,
	2
);
```

### Settings in code

Useful for staging copies or sites managed in Git:

```php
add_filter(
	'flurfunk_settings',
	static function ( array $s ): array {
		$s['post_types'] = array( 'post', 'page' );
		$s['keep_data']  = true;
		return $s;
	}
);
```

## Actions: a webhook for new threads

Sends every new thread in `#support` to an external system (a ticket tool, a chat). The request is
sent without waiting for the answer.

```php
<?php
/**
 * Plugin Name: Flurfunk support webhook
 * Description: Sends new #support threads to a webhook.
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'flurfunk_thread_created',
	static function ( object $thread ): void {
		if ( (int) $thread->channel_id !== flurfunk_ensure_channel( 'support' ) ) {
			return;
		}
		$author = get_userdata( (int) $thread->author_id );
		wp_remote_post(
			'https://hooks.example.com/flurfunk',
			array(
				'blocking' => false,
				'timeout'  => 2,
				'headers'  => array( 'Content-Type' => 'application/json' ),
				'body'     => wp_json_encode(
					array(
						'id'     => (int) $thread->id,
						'title'  => $thread->title,
						'text'   => $thread->body, // Mentions appear as <@ID>.
						'author' => $author ? $author->display_name : '',
						'url'    => flurfunk_url( 'thread:' . $thread->id ),
					)
				),
			)
		);
	}
);
```

Remember that this sends conversation content outside the site. Only do it where that is agreed.
