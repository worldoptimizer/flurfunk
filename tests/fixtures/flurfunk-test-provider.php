<?php
/**
 * Plugin Name: Flurfunk test provider
 * Description: A card that uses every building block and five actions, for the e2e tests and
 *              the screenshots. Test sites only.
 *
 * Load it as a must-use plugin (copy or symlink into wp-content/mu-plugins/) and switch it on
 * with define( 'FLURFUNK_TEST_PROVIDERS', true ) in wp-config.php, or with
 * add_filter( 'flurfunk_test_providers', '__return_true' ). Without one of them it does nothing.
 *
 * Slash command /e2e-card. Its state lives in an option per reference ("flurfunk_test_card_<id>").
 *
 * @package Flurfunk
 */

// phpcs:disable

defined( 'ABSPATH' ) || exit;

add_action(
	'init',
	static function () {
		if ( ! function_exists( 'flurfunk_register_provider' ) || ! ( ( defined( 'FLURFUNK_TEST_PROVIDERS' ) && FLURFUNK_TEST_PROVIDERS ) || apply_filters( 'flurfunk_test_providers', false ) ) ) {
			return;
		}
		$state = static fn ( array $ref ) => (string) get_option( 'flurfunk_test_card_' . sanitize_key( (string) ( $ref['id'] ?? '' ) ), 'review' );
		$set   = static fn ( array $ref, string $s ) => update_option( 'flurfunk_test_card_' . sanitize_key( (string) ( $ref['id'] ?? '' ) ), $s, false );
		$snap  = static function ( array $ref ) use ( $state ) {
			$s      = $state( $ref );
			$people = array_slice( array_map( static fn ( $p ) => (int) $p['id'], \Flurfunk\Store\people() ), 0, 3 );
			$tones  = array(
				'review'   => array( 'In review', 'warning' ),
				'approved' => array( 'Approved', 'positive' ),
				'rejected' => array( 'Rejected', 'critical' ),
			);
			return array(
				'title'     => 'Spring newsletter',
				'subtitle'  => 'Campaign · goes out Friday, 9:00',
				'url'       => home_url( '/' ),
				'status'    => array(
					'label' => $tones[ $s ][0],
					'tone'  => $tones[ $s ][1],
				),
				'badges'    => array( 'Newsletter', 'A/B test', 'German' ),
				'text'      => 'The issue about the new opening hours.',
				'quote'     => "Could we move the opening hours to the top?\nPeople keep asking.",
				'fields'    => array(
					array( 'label' => 'Lists', 'value' => 'Customers, Partners', 'url' => home_url( '/' ) ),
					array( 'label' => 'Recipients', 'value' => '1,284' ),
					array( 'label' => 'Opened', 'value' => '41 %' ),
					array( 'label' => 'Clicked', 'value' => '7 %' ),
				),
				'progress'  => array(
					'value' => 'approved' === $s ? 4 : 3,
					'max'   => 4,
					'label' => 'Checklist',
				),
				'checklist' => array(
					array( 'label' => 'Subject line', 'done' => true ),
					array( 'label' => 'Images have alt text', 'done' => true ),
					array( 'label' => 'Links checked', 'done' => true ),
					array( 'label' => 'Approved by the editor', 'done' => 'approved' === $s ),
				),
				'table'     => array(
					'columns' => array( 'Variant', 'Subject', 'Opened' ),
					'rows'    => array(
						array( 'A', 'New opening hours from May', '43 %' ),
						array( 'B', 'We are open longer', '39 %' ),
					),
				),
				'people'    => $people,
				'meta'      => array( 'Updated just now' ),
				'actions'   => 'review' === $s ? array( 'approve', 'reject', 'note', 'fail' ) : array( 'reopen' ),
			);
		};
		flurfunk_register_provider(
			'flurfunk-test/everything',
			array(
				'command'  => 'e2e-card',
				'label'    => 'Test card',
				'hint'     => 'Every building block (test provider)',
				'search'   => static fn () => array( array( 'ref' => array( 'id' => 'demo' ), 'title' => 'Spring newsletter', 'subtitle' => 'Test card' ) ),
				'snapshot' => $snap,
				'actions'  => array(
					'approve' => array(
						'label'    => 'Approve',
						'done'     => '%s approved',
						'style'    => 'primary',
						'confirm'  => 'Approve the newsletter for sending?',
						'callback' => static function ( array $ref ) use ( $set, $snap ) {
							$set( $ref, 'approved' );
							return $snap( $ref );
						},
					),
					'reject'  => array(
						'label'    => 'Reject',
						'done'     => '%s rejected',
						'style'    => 'danger',
						'confirm'  => 'Reject it? The author is told.',
						'callback' => static function ( array $ref ) use ( $set, $snap ) {
							$set( $ref, 'rejected' );
							return $snap( $ref );
						},
					),
					'note'    => array(
						'label'    => 'Remind me',
						'callback' => static fn ( array $ref ) => $snap( $ref ),
					),
					'fail'    => array(
						'label'    => 'Break',
						'callback' => static fn () => new WP_Error( 'flurfunk_test', 'The mail service did not answer.' ),
					),
					'reopen'  => array(
						'label'    => 'Reopen',
						'done'     => '%s reopened the review',
						'callback' => static function ( array $ref ) use ( $set, $snap ) {
							$set( $ref, 'review' );
							return $snap( $ref );
						},
					),
				),
			)
		);
	},
	20
);
