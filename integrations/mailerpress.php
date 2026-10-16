<?php
/**
 * Integration: MailerPress. Loaded only when MailerPress is active.
 *
 * /newsletter inserts a card for a campaign: subject, status (draft, scheduled for …, sending,
 * sent), the lists it goes to with the number of subscribed contacts, numbers after sending,
 * and a button into the MailerPress editor. Team only.
 *
 * Reads MailerPress's tables directly (read-only); if their layout changes, the card simply
 * shows less.
 *
 * @package Flurfunk
 */

namespace Flurfunk\Integrations\MailerPress;

defined( 'ABSPATH' ) || exit;

/**
 * Table name.
 *
 * @param string $name Table without prefix.
 */
function table( string $name ): string {
	global $wpdb;
	return $wpdb->prefix . 'mailerpress_' . $name;
}

/**
 * May this person see campaigns?
 *
 * @param int $user_id User.
 */
function can( int $user_id ): bool {
	return 'team' === \Flurfunk\Access\kind( $user_id ) && ( user_can( $user_id, 'mailerpress_manage_campaigns' ) || user_can( $user_id, 'manage_options' ) );
}

/**
 * Status in words.
 *
 * @param object $c   Campaign row.
 * @param array  $cfg Decoded config.
 */
function status_label( object $c, array $cfg ): string {
	switch ( $c->status ) {
		case 'scheduled':
			$at = ! empty( $cfg['sendAt'] ) ? date_create( (string) $cfg['sendAt'], wp_timezone() ) : false;
			/* translators: %s: date and time */
			return $at ? sprintf( __( 'Scheduled for %s', 'flurfunk' ), wp_date( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), $at->getTimestamp() ) ) : __( 'Scheduled', 'flurfunk' );
		case 'in_progress':
			return __( 'Sending', 'flurfunk' );
		case 'sent':
			return __( 'Sent', 'flurfunk' );
		case 'pending':
			return __( 'Pending', 'flurfunk' );
		case 'error':
			return __( 'Error while sending', 'flurfunk' );
		default:
			return __( 'Draft', 'flurfunk' );
	}
}

add_action(
	'init',
	static function (): void {
		flurfunk_register_provider(
			'mailerpress/campaign',
			array(
				'command'  => _x( 'newsletter', 'slash command', 'flurfunk' ),
				'label'    => __( 'Newsletter', 'flurfunk' ),
				'hint'     => __( 'A campaign from MailerPress', 'flurfunk' ),
				'can'      => __NAMESPACE__ . '\\can',
				'search'   => __NAMESPACE__ . '\\search',
				'snapshot' => __NAMESPACE__ . '\\snapshot',
			)
		);
	},
	20
);

/**
 * Campaigns matching a search, newest first.
 *
 * @param string $q Search text.
 */
function search( string $q ): array {
	global $wpdb;
	$like = '%' . $wpdb->esc_like( $q ) . '%';
	$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT campaign_id, name, subject, status, config FROM ' . table( 'campaigns' ) . " WHERE campaign_type IN ('newsletter','ab_test') AND status <> 'trash' AND ( name LIKE %s OR subject LIKE %s ) ORDER BY updated_at DESC LIMIT 12", $like, $like ) ); // phpcs:ignore WordPress.DB
	return array_map(
		static fn ( $c ) => array(
			'ref'      => array( 'id' => (int) $c->campaign_id ),
			'title'    => $c->subject ?: $c->name,
			'subtitle' => status_label( $c, (array) json_decode( (string) $c->config, true ) ),
		),
		$rows
	);
}

/**
 * Card for a campaign.
 *
 * @param array $ref { id }.
 */
function snapshot( array $ref ): ?array {
	global $wpdb;
	$c = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . table( 'campaigns' ) . ' WHERE campaign_id = %d', (int) ( $ref['id'] ?? 0 ) ) ); // phpcs:ignore WordPress.DB
	if ( ! $c || 'trash' === $c->status ) {
		return null;
	}
	$cfg   = (array) json_decode( (string) $c->config, true );
	$lists = array_values( array_filter( array_map( 'intval', (array) ( $cfg['lists'] ?? array() ) ) ) );
	$meta  = array();
	$count = 0;
	if ( $lists ) {
		$in    = implode( ',', $lists );
		$names = (array) $wpdb->get_col( 'SELECT name FROM ' . table( 'lists' ) . " WHERE list_id IN ($in)" ); // phpcs:ignore WordPress.DB
		$count = (int) $wpdb->get_var( 'SELECT COUNT(DISTINCT c.contact_id) FROM ' . table( 'contact_lists' ) . ' cl JOIN ' . table( 'contact' ) . " c ON c.contact_id = cl.contact_id WHERE cl.list_id IN ($in) AND c.subscription_status = 'subscribed'" ); // phpcs:ignore WordPress.DB
		if ( $names ) {
			/* translators: %s: list names */
			$meta[] = sprintf( __( 'List: %s', 'flurfunk' ), implode( ', ', $names ) );
		}
	}
	if ( 'classic' !== ( $cfg['recipientTargeting'] ?? 'classic' ) || ! empty( $cfg['segment'] ) ) {
		$meta[] = __( 'with segment', 'flurfunk' );
	}
	$metric = array(
		'value' => number_format_i18n( $count ),
		'label' => __( 'subscribed recipients', 'flurfunk' ),
	);
	$stats = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . table( 'campaign_stats' ) . ' WHERE campaign_id = %d ORDER BY id DESC LIMIT 1', $c->campaign_id ) ); // phpcs:ignore WordPress.DB
	if ( $stats && (int) $stats->total_sent ) {
		$metric = array(
			'value' => number_format_i18n( (int) $stats->total_sent ),
			'label' => __( 'sent', 'flurfunk' ),
		);
		if ( 'yes' === ( $cfg['openTracking'] ?? 'no' ) ) {
			/* translators: %s: number */
			$meta[] = sprintf( __( '%s opened', 'flurfunk' ), number_format_i18n( (int) $stats->total_open ) );
		}
		if ( 'yes' === ( $cfg['clickTracking'] ?? 'no' ) ) {
			/* translators: %s: number */
			$meta[] = sprintf( __( '%s clicked', 'flurfunk' ), number_format_i18n( (int) $stats->total_click ) );
		}
		/* translators: %s: number */
		$meta[] = sprintf( __( '%s unsubscribed', 'flurfunk' ), number_format_i18n( (int) $stats->total_unsubscribe ) );
		/* translators: %s: number */
		$meta[] = sprintf( __( '%s bounced', 'flurfunk' ), number_format_i18n( (int) $stats->total_bounce ) );
	}
	$edit = admin_url( 'admin.php?page=mailerpress/new&edit=' . (int) $c->campaign_id );
	return array(
		'title'    => $c->subject ?: $c->name,
		'subtitle' => status_label( $c, $cfg ),
		'metric'   => $metric,
		'meta'     => $meta,
		'url'      => $edit,
		'action'   => array(
			'label' => __( 'Open in MailerPress', 'flurfunk' ),
			'url'   => $edit,
		),
	);
}
