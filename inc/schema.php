<?php
/**
 * Own tables. Each has one job and the indexes the interface needs.
 * To change the schema: bump DB_VERSION; dbDelta adds columns and indexes, anything it cannot
 * do (renames, rewriting data) goes into upgrade() as a step.
 *
 * @package Flurfunk
 */

namespace Flurfunk\Schema;

defined( 'ABSPATH' ) || exit;

const DB_VERSION = 5;
const OPTION     = 'flurfunk_db_version';

/**
 * Table name.
 *
 * @param string $name channels|threads|messages|mentions|reads|attachments|assignees|notifications|push.
 */
function table( string $name ): string {
	global $wpdb;
	return $wpdb->prefix . 'flurfunk_' . $name;
}

/**
 * Create or update the tables.
 */
function install(): void {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$c = $wpdb->get_charset_collate();

	// dbDelta wants two spaces after PRIMARY KEY and one column per line.
	dbDelta(
		'CREATE TABLE ' . table( 'channels' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  slug varchar(64) NOT NULL,
  name varchar(190) NOT NULL DEFAULT '',
  topic text NOT NULL,
  access_team varchar(16) NOT NULL DEFAULT 'all',
  access_guests varchar(16) NOT NULL DEFAULT 'none',
  position int(11) NOT NULL DEFAULT 100,
  archived tinyint(1) NOT NULL DEFAULT 0,
  created datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug)
) $c;
CREATE TABLE " . table( 'threads' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  channel_id bigint(20) unsigned NOT NULL,
  ref_key varchar(191) NOT NULL DEFAULT '',
  target_type varchar(32) NOT NULL DEFAULT '',
  target_id bigint(20) unsigned NOT NULL DEFAULT 0,
  title varchar(255) NOT NULL DEFAULT '',
  body longtext NOT NULL,
  files longtext,
  author_id bigint(20) unsigned NOT NULL,
  status varchar(8) NOT NULL DEFAULT '',
  due_date date DEFAULT NULL,
  resolved_by bigint(20) unsigned NOT NULL DEFAULT 0,
  resolved_at datetime DEFAULT NULL,
  created datetime NOT NULL,
  last_activity datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY channel_activity (channel_id,last_activity),
  KEY target (target_type,target_id),
  KEY ref_key (ref_key),
  KEY activity (last_activity),
  KEY due (status,due_date)
) $c;
CREATE TABLE " . table( 'assignees' ) . " (
  thread_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  assigned_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created datetime NOT NULL,
  PRIMARY KEY  (thread_id,user_id),
  KEY user_id (user_id)
) $c;
CREATE TABLE " . table( 'notifications' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  thread_id bigint(20) unsigned NOT NULL,
  message_id bigint(20) unsigned NOT NULL DEFAULT 0,
  kind varchar(16) NOT NULL,
  actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created datetime NOT NULL,
  read_at datetime DEFAULT NULL,
  sent varchar(32) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY user_unread (user_id,read_at),
  KEY thread (thread_id),
  KEY kind_thread (kind,thread_id)
) $c;
CREATE TABLE " . table( 'push' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  endpoint text NOT NULL,
  endpoint_hash char(64) NOT NULL,
  p256dh varchar(255) NOT NULL,
  auth varchar(64) NOT NULL,
  label varchar(190) NOT NULL DEFAULT '',
  created datetime NOT NULL,
  last_ok datetime DEFAULT NULL,
  failures int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY endpoint_hash (endpoint_hash),
  KEY user_id (user_id)
) $c;
CREATE TABLE " . table( 'messages' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  thread_id bigint(20) unsigned NOT NULL,
  type varchar(16) NOT NULL DEFAULT 'message',
  author_id bigint(20) unsigned NOT NULL,
  body longtext NOT NULL,
  status varchar(8) NOT NULL DEFAULT '',
  files longtext NOT NULL,
  created datetime NOT NULL,
  edited datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY thread (thread_id,id)
) $c;
CREATE TABLE " . table( 'mentions' ) . " (
  thread_id bigint(20) unsigned NOT NULL,
  message_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_id bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (thread_id,message_id,user_id),
  KEY user_thread (user_id,thread_id)
) $c;
CREATE TABLE " . table( 'reads' ) . " (
  user_id bigint(20) unsigned NOT NULL,
  thread_id bigint(20) unsigned NOT NULL,
  last_message_id bigint(20) unsigned NOT NULL DEFAULT 0,
  seen datetime NOT NULL,
  follow tinyint(4) NOT NULL DEFAULT 0,
  remind_at datetime DEFAULT NULL,
  PRIMARY KEY  (user_id,thread_id),
  KEY thread (thread_id),
  KEY remind (remind_at)
) $c;
CREATE TABLE " . table( 'attachments' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  thread_id bigint(20) unsigned NOT NULL,
  message_id bigint(20) unsigned NOT NULL DEFAULT 0,
  provider varchar(64) NOT NULL,
  ref_key varchar(191) NOT NULL DEFAULT '',
  ref longtext NOT NULL,
  snapshot longtext NOT NULL,
  created datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY thread (thread_id,message_id),
  KEY provider_ref (provider,ref_key)
) $c;"
	);
	upgrade( (int) get_option( OPTION, 0 ) );
	update_option( OPTION, DB_VERSION, true );
}

/**
 * Upgrade steps dbDelta cannot do.
 *
 * @param int $from Previous version.
 */
function upgrade( int $from ): void {
	// None yet. Example: if ( $from < 2 ) { … rewrite data … }
	unset( $from );
}

/**
 * Activation: tables plus a first channel so the app is never empty.
 */
function activate(): void {
	install();
	\Flurfunk\Store\ensure_channel(
		'general',
		array(
			'name'  => __( 'General', 'flurfunk' ),
			'topic' => __( 'Anything that concerns everyone', 'flurfunk' ),
		)
	);
}

// After an update without re-activation (rsync, Git): bring the tables up to date.
add_action(
	'plugins_loaded',
	static function (): void {
		if ( (int) get_option( OPTION, 0 ) < DB_VERSION ) {
			install();
		}
	}
);

/**
 * Drop all tables (only on uninstall, if the site chose so).
 */
function drop(): void {
	global $wpdb;
	foreach ( array( 'channels', 'threads', 'messages', 'mentions', 'reads', 'attachments', 'assignees', 'notifications', 'push' ) as $t ) {
		$wpdb->query( 'DROP TABLE IF EXISTS ' . table( $t ) ); // phpcs:ignore WordPress.DB
	}
	delete_option( OPTION );
}
