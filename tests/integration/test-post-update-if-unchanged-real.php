<?php
/**
 * Real-database integration test for cinatra/post-read and
 * cinatra/post-update-if-unchanged (cinatra#3745).
 *
 * Runs inside a real WordPress with the plugin active, against a real MySQL
 * or MariaDB server, through WP-CLI:
 *
 *   wp eval-file tests/integration/test-post-update-if-unchanged-real.php --path=<wp>
 *
 * CI runs it in the plugin-check job (mysql:8.4 service). It needs no Docker
 * of its own, but it cannot run without a database server and a WordPress
 * install, so the standalone stub tests (tests/test-post-update-if-unchanged.php)
 * stay the fast local check.
 *
 * The test creates its own posts and an editor user, deletes them at the end
 * (also after a failure), prints one line per case and exits non-zero on the
 * first failure. Each line carries the database diagnostics the detection
 * reads: the raw @@SESSION.autocommit, the in_transaction row (MariaDB) or its
 * absence (MySQL), and the number of information_schema.INNODB_TRX rows of
 * this connection.
 *
 * The abilities are invoked through the Abilities API (wp_get_ability() and
 * WP_Ability::execute(), which also runs the input and output schema checks
 * and the permission callback) when core provides it; otherwise the execute
 * callbacks are called directly. The first line says which path ran.
 *
 * @package Cinatra
 */

if ( ! defined( 'ABSPATH' ) || ! function_exists( 'cinatra_ability_post_update_if_unchanged' ) ) {
	fwrite( STDERR, "Run through 'wp eval-file' in a WordPress with the cinatra plugin active.\n" );
	exit( 1 );
}

/**
 * A test failure: carries the case name and the reason.
 */
class Cinatra_IT_Failure extends Exception {
}

/**
 * Print one line.
 *
 * @param string $line The line.
 * @return void
 */
function cinatra_it_log( string $line ): void {
	if ( class_exists( 'WP_CLI' ) ) {
		WP_CLI::log( $line );
	} else {
		echo $line, "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output.
	}
}

/**
 * Fail the current case.
 *
 * @param string $reason What went wrong.
 * @param mixed  $detail A value to print with it.
 * @throws Cinatra_IT_Failure Always.
 * @return void
 */
function cinatra_it_fail( string $reason, $detail = null ): void {
	$text = $reason;
	if ( null !== $detail ) {
		$text .= ' | ' . wp_json_encode( $detail );
	}
	throw new Cinatra_IT_Failure( $text );
}

/**
 * Assert a condition.
 *
 * @param bool   $condition The condition.
 * @param string $reason    The failure reason.
 * @param mixed  $detail    A value to print on failure.
 * @return void
 */
function cinatra_it_assert( bool $condition, string $reason, $detail = null ): void {
	if ( ! $condition ) {
		cinatra_it_fail( $reason, $detail );
	}
}

/**
 * The raw database diagnostics of this connection.
 *
 * @return string One line: autocommit, in_transaction, INNODB_TRX rows.
 */
function cinatra_it_diag(): string {
	global $wpdb;
	$autocommit = $wpdb->get_var( 'SELECT @@SESSION.autocommit' );
	$in_trx_row = $wpdb->get_row( "SHOW SESSION VARIABLES LIKE 'in_transaction'", ARRAY_N );
	$suppressed = $wpdb->suppress_errors( true );
	$trx_all    = $wpdb->get_var( 'SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = CONNECTION_ID()' );
	$trx_rw     = $wpdb->get_var( 'SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = CONNECTION_ID() AND trx_autocommit_non_locking = 0' );
	$trx_error  = $wpdb->last_error;
	$wpdb->suppress_errors( $suppressed );
	return sprintf(
		'@@autocommit=%s in_transaction=%s innodb_trx(own)=%s innodb_trx(own,not-autocommit-select)=%s%s',
		null === $autocommit ? 'NULL' : (string) $autocommit,
		is_array( $in_trx_row ) ? wp_json_encode( $in_trx_row ) : '(no row)',
		null === $trx_all ? 'NULL' : (string) $trx_all,
		null === $trx_rw ? 'NULL' : (string) $trx_rw,
		null === $trx_rw && '' !== $trx_error ? ' innodb_trx_error=' . $trx_error : ''
	);
}

/**
 * Invoke an ability: through the Abilities API when core provides it,
 * otherwise its execute callback directly.
 *
 * @param string $name  Ability name.
 * @param array  $input Ability input.
 * @return array The ability's result.
 */
function cinatra_it_call( string $name, array $input ): array {
	if ( 'ability' === $GLOBALS['cinatra_it_path'] ) {
		$result = wp_get_ability( $name )->execute( $input );
		if ( is_wp_error( $result ) ) {
			cinatra_it_fail( 'WP_Ability::execute returned a WP_Error', array( $result->get_error_code(), $result->get_error_message() ) );
		}
		return $result;
	}
	return CINATRA_ABILITY_POST_READ === $name ? cinatra_ability_post_read( $input ) : cinatra_ability_post_update_if_unchanged( $input );
}

/**
 * Read a post through cinatra/post-read and require success.
 *
 * @param int $id Post ID.
 * @return array The read result.
 */
function cinatra_it_read( int $id ): array {
	$read = cinatra_it_call( CINATRA_ABILITY_POST_READ, array( 'post_id' => $id ) );
	cinatra_it_assert( true === ( $read['success'] ?? null ), 'post-read did not succeed', $read );
	return $read;
}

/**
 * The update input based on a read, with the given changes.
 *
 * @param array $read    A post-read result.
 * @param array $changes title / content / excerpt / status.
 * @return array The input.
 */
function cinatra_it_input( array $read, array $changes ): array {
	return array_merge(
		array(
			'post_id'             => (int) $read['post_id'],
			'if_unmodified_since' => (string) $read['post_modified_gmt'],
			'expected'            => array(
				'title'     => (string) $read['post_title'],
				'content'   => (string) $read['post_content'],
				'excerpt'   => (string) $read['post_excerpt'],
				'status'    => (string) $read['post_status'],
				'row_token' => (string) $read['row_token'],
			),
		),
		$changes
	);
}

/**
 * The stored posts row, straight from the database.
 *
 * @param int $id Post ID.
 * @return array|null The row.
 */
function cinatra_it_row( int $id ): ?array {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID = %d", $id ), ARRAY_A );
	return is_array( $row ) ? $row : null;
}

/**
 * Create a post owned by the administrator, with modification times in the
 * past so that a write moves them also within the same second.
 *
 * @param string $status  publish or draft.
 * @param string $content The content.
 * @return int Post ID.
 */
function cinatra_it_post( string $status, string $content ): int {
	global $wpdb;
	$id = wp_insert_post(
		array(
			'post_type'    => 'post',
			'post_status'  => $status,
			'post_title'   => 'cinatra-it ' . $status,
			'post_content' => $content,
			'post_excerpt' => 'cinatra-it excerpt',
			'post_author'  => (int) $GLOBALS['cinatra_it_admin'],
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		cinatra_it_fail( 'wp_insert_post failed', $id->get_error_message() );
	}
	$GLOBALS['cinatra_it_posts'][] = (int) $id;
	$wpdb->update(
		$wpdb->posts,
		array(
			'post_modified'     => '2020-01-01 00:00:00',
			'post_modified_gmt' => '2020-01-01 00:00:00',
		),
		array( 'ID' => $id )
	);
	clean_post_cache( $id );
	return (int) $id;
}

/**
 * Record every SQL statement while recording is on.
 *
 * @param string $query The statement.
 * @return string The statement, unchanged.
 */
function cinatra_it_record_query( $query ) {
	if ( $GLOBALS['cinatra_it_recording'] ) {
		$GLOBALS['cinatra_it_sql'][] = (string) $query;
	}
	return $query;
}

/**
 * Start recording statements.
 *
 * @return void
 */
function cinatra_it_record(): void {
	$GLOBALS['cinatra_it_sql']       = array();
	$GLOBALS['cinatra_it_recording'] = true;
}

/**
 * Whether a recorded statement is an UPDATE of the posts table that stores
 * post_status 'publish'.
 *
 * @return bool True when one was recorded.
 */
function cinatra_it_publish_stored(): bool {
	global $wpdb;
	foreach ( $GLOBALS['cinatra_it_sql'] as $sql ) {
		if ( preg_match( '/^\s*UPDATE\s+`?' . preg_quote( $wpdb->posts, '/' ) . '`?\s.*`?post_status`?\s*=\s*\'publish\'/is', $sql ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Whether a refusal has the given code (and cause).
 *
 * @param array       $result The result.
 * @param string      $code   The code.
 * @param string|null $cause  data.cause, or null to not check it.
 * @return bool True when it matches.
 */
function cinatra_it_refused( array $result, string $code, ?string $cause = null ): bool {
	return false === ( $result['success'] ?? null )
		&& ( $result['code'] ?? null ) === $code
		&& ( null === $cause || ( $result['data']['cause'] ?? null ) === $cause );
}

/**
 * Run one case and print its line.
 *
 * @param string   $name The case name.
 * @param callable $run  The case; returns extra text for the line.
 * @return void
 */
function cinatra_it_case( string $name, callable $run ): void {
	$GLOBALS['cinatra_it_current'] = $name;
	$extra                         = (string) $run();
	cinatra_it_log( 'PASS ' . $name . ( '' === $extra ? '' : ' | ' . $extra ) . ' | ' . cinatra_it_diag() );
}

$GLOBALS['cinatra_it_posts']     = array();
$GLOBALS['cinatra_it_editor']    = 0;
$GLOBALS['cinatra_it_recording'] = false;
$GLOBALS['cinatra_it_sql']       = array();
$GLOBALS['cinatra_it_current']   = 'setup';
$GLOBALS['cinatra_it_option']    = 'cinatra_it_foreign_probe';
$cinatra_it_failure              = null;

add_filter( 'query', 'cinatra_it_record_query' );

try {
	global $wpdb;

	$admin = get_user_by( 'login', 'admin' );
	if ( ! $admin ) {
		$admins = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
			)
		);
		$admin  = $admins ? $admins[0] : null;
	}
	cinatra_it_assert( $admin instanceof WP_User, 'no administrator user found' );
	$GLOBALS['cinatra_it_admin'] = (int) $admin->ID;
	wp_set_current_user( (int) $admin->ID );

	if ( function_exists( 'wp_get_ability' ) ) {
		cinatra_it_assert( null !== wp_get_ability( CINATRA_ABILITY_POST_READ ), 'core has the Abilities API but cinatra/post-read is not registered' );
		cinatra_it_assert( null !== wp_get_ability( CINATRA_ABILITY_POST_UPDATE_IF_UNCHANGED ), 'core has the Abilities API but cinatra/post-update-if-unchanged is not registered' );
		$GLOBALS['cinatra_it_path'] = 'ability';
	} else {
		$GLOBALS['cinatra_it_path'] = 'direct';
	}
	cinatra_it_log(
		sprintf(
			'INFO WordPress %s, server %s, posts engine %s, invocation: %s',
			get_bloginfo( 'version' ),
			(string) $wpdb->get_var( 'SELECT VERSION()' ),
			(string) $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $wpdb->posts ) ),
			'ability' === $GLOBALS['cinatra_it_path'] ? 'Abilities API (wp_get_ability()->execute())' : 'execute callbacks called directly (core has no wp_get_ability)'
		)
	);
	cinatra_it_log( 'INFO start | ' . cinatra_it_diag() );

	cinatra_it_case(
		'detection: no transaction open',
		function () {
			$state = cinatra_content_open_transaction_state();
			cinatra_it_assert( '' === $state, 'the detection reports a transaction on a fresh connection', $state );
			return 'state=""';
		}
	);

	cinatra_it_case(
		'content-only update succeeds; post_updated fires after COMMIT',
		function () {
			$id     = cinatra_it_post( 'publish', 'before' );
			$read   = cinatra_it_read( $id );
			$seen   = array();
			$listen = function ( $post_id ) use ( $id, &$seen ) {
				if ( (int) $post_id === $id ) {
					$seen[] = array(
						'tracker_open' => cinatra_content_transaction()['open'],
						'commit_seen'  => in_array( 'COMMIT', $GLOBALS['cinatra_it_sql'], true ),
						'diag'         => cinatra_it_diag(),
					);
				}
			};
			add_action( 'post_updated', $listen, 1 );
			cinatra_it_record();
			try {
				$result = cinatra_it_call( CINATRA_ABILITY_POST_UPDATE_IF_UNCHANGED, cinatra_it_input( $read, array( 'content' => 'after' ) ) );
			} finally {
				$GLOBALS['cinatra_it_recording'] = false;
				remove_action( 'post_updated', $listen, 1 );
			}
			cinatra_it_assert( true === ( $result['success'] ?? null ), 'the update did not succeed', $result );
			$row = cinatra_it_row( $id );
			cinatra_it_assert( 'after' === $row['post_content'], 'the content was not stored', $row['post_content'] );
			cinatra_it_assert( $read['post_title'] === $row['post_title'] && $read['post_excerpt'] === $row['post_excerpt'] && 'publish' === $row['post_status'], 'an unwritten field changed', $row );
			cinatra_it_assert( '2020-01-01 00:00:00' !== $row['post_modified_gmt'], 'post_modified_gmt did not move', $row['post_modified_gmt'] );
			cinatra_it_assert( $row['post_modified_gmt'] === $result['post_modified_gmt'], 'the answer does not report the stored post_modified_gmt', $result );
			cinatra_it_assert( 1 === count( $seen ), 'post_updated did not fire exactly once', $seen );
			cinatra_it_assert( false === $seen[0]['tracker_open'] && true === $seen[0]['commit_seen'], 'post_updated fired before the COMMIT', $seen );
			return 'post_modified_gmt 2020-01-01 00:00:00 -> ' . $row['post_modified_gmt'] . '; at post_updated: ' . $seen[0]['diag'];
		}
	);

	cinatra_it_case(
		'publish -> draft stores draft; no statement stores publish',
		function () {
			$id   = cinatra_it_post( 'publish', 'body' );
			$read = cinatra_it_read( $id );
			cinatra_it_record();
			try {
				$result = cinatra_it_call(
					CINATRA_ABILITY_POST_UPDATE_IF_UNCHANGED,
					cinatra_it_input(
						$read,
						array(
							'title'  => 'cinatra-it unpublished',
							'status' => 'draft',
						)
					)
				);
			} finally {
				$GLOBALS['cinatra_it_recording'] = false;
			}
			cinatra_it_assert( true === ( $result['success'] ?? null ), 'the update did not succeed', $result );
			$row = cinatra_it_row( $id );
			cinatra_it_assert( 'draft' === $row['post_status'] && 'draft' === $result['post_status'], 'the post was not stored as draft', array( $row['post_status'], $result['post_status'] ?? null ) );
			cinatra_it_assert( ! cinatra_it_publish_stored(), 'a statement stored post_status publish', $GLOBALS['cinatra_it_sql'] );
			return count( $GLOBALS['cinatra_it_sql'] ) . ' statements recorded, none stores publish';
		}
	);

	cinatra_it_case(
		'foreign START TRANSACTION + options write -> transaction_conflict; ROLLBACK removes it',
		function () {
			global $wpdb;
			$id     = cinatra_it_post( 'publish', 'body' );
			$read   = cinatra_it_read( $id );
			$before = cinatra_it_row( $id );
			$wpdb->query( 'START TRANSACTION' );
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)", $GLOBALS['cinatra_it_option'], 'foreign', 'off' ) );
			$during = cinatra_it_diag();
			$state  = cinatra_content_open_transaction_state();
			$result = cinatra_it_call( CINATRA_ABILITY_POST_UPDATE_IF_UNCHANGED, cinatra_it_input( $read, array( 'content' => 'must not be written' ) ) );
			$wpdb->query( 'ROLLBACK' );
			cinatra_it_assert( 'open_transaction' === $state, 'the detection did not see the foreign transaction', array( $state, $during ) );
			cinatra_it_assert( cinatra_it_refused( $result, 'transaction_conflict', 'open_transaction' ), 'the write was not refused with transaction_conflict/open_transaction', array( $result, $during ) );
			$option = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", $GLOBALS['cinatra_it_option'] ) );
			cinatra_it_assert( '0' === (string) $option, 'the foreign options write survived the ROLLBACK (the ability committed it)', $option );
			cinatra_it_assert( cinatra_it_row( $id ) === $before, 'the post row changed' );
			return 'during the foreign transaction: ' . $during;
		}
	);

	cinatra_it_case(
		'foreign START TRANSACTION + posts write -> transaction_conflict; ROLLBACK removes it',
		function () {
			global $wpdb;
			$id     = cinatra_it_post( 'publish', 'body' );
			$read   = cinatra_it_read( $id );
			$before = cinatra_it_row( $id );
			$wpdb->query( 'START TRANSACTION' );
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_excerpt = %s WHERE ID = %d", 'foreign', $id ) );
			$during = cinatra_it_diag();
			$result = cinatra_it_call( CINATRA_ABILITY_POST_UPDATE_IF_UNCHANGED, cinatra_it_input( $read, array( 'content' => 'must not be written' ) ) );
			$wpdb->query( 'ROLLBACK' );
			cinatra_it_assert( cinatra_it_refused( $result, 'transaction_conflict', 'open_transaction' ), 'the write was not refused with transaction_conflict/open_transaction', array( $result, $during ) );
			cinatra_it_assert( cinatra_it_row( $id ) === $before, 'the foreign posts write survived the ROLLBACK or the ability wrote', cinatra_it_row( $id ) );
			return 'during the foreign transaction: ' . $during;
		}
	);

	cinatra_it_case(
		'SET autocommit = 0 -> transaction_conflict/autocommit_off',
		function () {
			global $wpdb;
			$id     = cinatra_it_post( 'publish', 'body' );
			$read   = cinatra_it_read( $id );
			$before = cinatra_it_row( $id );
			$wpdb->query( 'SET autocommit = 0' );
			try {
				$during = cinatra_it_diag();
				$result = cinatra_it_call( CINATRA_ABILITY_POST_UPDATE_IF_UNCHANGED, cinatra_it_input( $read, array( 'content' => 'must not be written' ) ) );
			} finally {
				$wpdb->query( 'ROLLBACK' );
				$wpdb->query( 'SET autocommit = 1' );
			}
			cinatra_it_assert( cinatra_it_refused( $result, 'transaction_conflict', 'autocommit_off' ), 'the write was not refused with transaction_conflict/autocommit_off', array( $result, $during ) );
			cinatra_it_assert( '1' === (string) $wpdb->get_var( 'SELECT @@SESSION.autocommit' ), 'autocommit was not restored' );
			cinatra_it_assert( cinatra_it_row( $id ) === $before, 'the post row changed' );
			return 'with autocommit off: ' . $during;
		}
	);

	cinatra_it_case(
		'nested call from pre_post_update -> inner nested refusal, outer completes',
		function () {
			$outer_id     = cinatra_it_post( 'publish', 'outer' );
			$inner_id     = cinatra_it_post( 'publish', 'inner' );
			$outer_read   = cinatra_it_read( $outer_id );
			$inner_read   = cinatra_it_read( $inner_id );
			$inner_before = cinatra_it_row( $inner_id );
			$inner_result = null;
			$nest         = function ( $post_id ) use ( $outer_id, $inner_read, &$inner_result ) {
				if ( (int) $post_id === $outer_id && null === $inner_result ) {
					$inner_result = cinatra_it_call( CINATRA_ABILITY_POST_UPDATE_IF_UNCHANGED, cinatra_it_input( $inner_read, array( 'content' => 'inner must not be written' ) ) );
				}
			};
			add_action( 'pre_post_update', $nest );
			try {
				$outer = cinatra_it_call( CINATRA_ABILITY_POST_UPDATE_IF_UNCHANGED, cinatra_it_input( $outer_read, array( 'content' => 'outer after' ) ) );
			} finally {
				remove_action( 'pre_post_update', $nest );
			}
			cinatra_it_assert( is_array( $inner_result ) && cinatra_it_refused( $inner_result, 'transaction_conflict', 'nested' ), 'the inner call was not refused with transaction_conflict/nested', $inner_result );
			cinatra_it_assert( true === ( $outer['success'] ?? null ), 'the outer call did not complete', $outer );
			cinatra_it_assert( 'outer after' === cinatra_it_row( $outer_id )['post_content'], 'the outer content was not stored' );
			cinatra_it_assert( cinatra_it_row( $inner_id ) === $inner_before, 'the inner post changed' );
			return 'inner cause=' . $inner_result['data']['cause'];
		}
	);

	cinatra_it_case(
		'row_token stable across reads; same-second slug change -> post_changed',
		function () {
			global $wpdb;
			$id    = cinatra_it_post( 'publish', 'body' );
			$first = cinatra_it_read( $id );
			$again = cinatra_it_read( $id );
			cinatra_it_assert( $first['row_token'] === $again['row_token'], 'two reads of an unchanged row gave different tokens', array( $first['row_token'], $again['row_token'] ) );
			$updated = wp_update_post(
				array(
					'ID'        => $id,
					'post_name' => 'cinatra-it-changed-slug',
				),
				true
			);
			cinatra_it_assert( ! is_wp_error( $updated ), 'wp_update_post failed', is_wp_error( $updated ) ? $updated->get_error_message() : null );
			// Put the modification times back, as for a change within the same
			// second as the read: only the row token can see the slug change.
			$wpdb->update(
				$wpdb->posts,
				array(
					'post_modified'     => '2020-01-01 00:00:00',
					'post_modified_gmt' => '2020-01-01 00:00:00',
				),
				array( 'ID' => $id )
			);
			clean_post_cache( $id );
			$before = cinatra_it_row( $id );
			cinatra_it_assert( 'cinatra-it-changed-slug' === $before['post_name'] && $first['post_modified_gmt'] === $before['post_modified_gmt'], 'the slug-only change was not set up', $before );
			$result = cinatra_it_call( CINATRA_ABILITY_POST_UPDATE_IF_UNCHANGED, cinatra_it_input( $first, array( 'content' => 'must not be written' ) ) );
			cinatra_it_assert( cinatra_it_refused( $result, 'post_changed' ), 'the stale token was not refused with post_changed', $result );
			cinatra_it_assert( cinatra_it_row( $id ) === $before, 'something was written' );
			return 'changed=' . wp_json_encode( $result['data']['changed'] ?? null );
		}
	);

	cinatra_it_case(
		'stripping wp_insert_post_data filter is honoured',
		function () {
			$id    = cinatra_it_post( 'publish', 'body' );
			$read  = cinatra_it_read( $id );
			$strip = function ( $data ) {
				$data['post_content'] = str_replace( 'STRIPME', '', $data['post_content'] );
				return $data;
			};
			add_filter( 'wp_insert_post_data', $strip );
			try {
				$result = cinatra_it_call( CINATRA_ABILITY_POST_UPDATE_IF_UNCHANGED, cinatra_it_input( $read, array( 'content' => 'keep STRIPME this' ) ) );
			} finally {
				remove_filter( 'wp_insert_post_data', $strip );
			}
			cinatra_it_assert( true === ( $result['success'] ?? null ), 'the update did not succeed', $result );
			cinatra_it_assert( 'keep  this' === cinatra_it_row( $id )['post_content'], 'the filter result was not stored', cinatra_it_row( $id )['post_content'] );
			cinatra_it_assert( in_array( 'post_content', $result['sanitized_fields'], true ), 'sanitized_fields does not name post_content', $result['sanitized_fields'] );
			return 'stored "keep  this"';
		}
	);

	cinatra_it_case(
		'publish-setting wp_insert_post_data filter -> site_policy_conflict',
		function () {
			$id      = cinatra_it_post( 'draft', 'body' );
			$read    = cinatra_it_read( $id );
			$before  = cinatra_it_row( $id );
			$publish = function ( $data ) {
				$data['post_status'] = 'publish';
				return $data;
			};
			add_filter( 'wp_insert_post_data', $publish );
			cinatra_it_record();
			try {
				$result = cinatra_it_call( CINATRA_ABILITY_POST_UPDATE_IF_UNCHANGED, cinatra_it_input( $read, array( 'content' => 'must not be written' ) ) );
			} finally {
				$GLOBALS['cinatra_it_recording'] = false;
				remove_filter( 'wp_insert_post_data', $publish );
			}
			cinatra_it_assert( cinatra_it_refused( $result, 'site_policy_conflict' ), 'the publishing filter was not refused with site_policy_conflict', $result );
			cinatra_it_assert( cinatra_it_row( $id ) === $before, 'something was written' );
			cinatra_it_assert( ! cinatra_it_publish_stored(), 'a statement stored post_status publish', $GLOBALS['cinatra_it_sql'] );
			return 'changed=' . wp_json_encode( $result['data']['changed'] ?? null );
		}
	);

	cinatra_it_case(
		'pre_post_update callback writing the slug -> refused by the post-write check, rolled back',
		function () {
			global $wpdb;
			$id     = cinatra_it_post( 'publish', 'body' );
			$read   = cinatra_it_read( $id );
			$before = cinatra_it_row( $id );
			$sneak  = function ( $post_id ) use ( $id ) {
				global $wpdb;
				if ( (int) $post_id === $id ) {
					$wpdb->update( $wpdb->posts, array( 'post_name' => 'cinatra-it-sneaky' ), array( 'ID' => $id ) );
				}
			};
			add_action( 'pre_post_update', $sneak );
			try {
				$result = cinatra_it_call( CINATRA_ABILITY_POST_UPDATE_IF_UNCHANGED, cinatra_it_input( $read, array( 'content' => 'must not be written' ) ) );
			} finally {
				remove_action( 'pre_post_update', $sneak );
			}
			cinatra_it_assert( cinatra_it_refused( $result, 'site_policy_conflict' ) && in_array( 'post_name', (array) ( $result['data']['changed'] ?? array() ), true ), 'the slug write was not refused by the post-write check', $result );
			cinatra_it_assert( cinatra_it_row( $id ) === $before, 'the slug or the content survived the rollback', cinatra_it_row( $id ) );
			return 'changed=' . wp_json_encode( $result['data']['changed'] );
		}
	);

	cinatra_it_case(
		'throwing pre_post_update callback -> no open transaction afterwards, nothing written',
		function () {
			$id     = cinatra_it_post( 'publish', 'body' );
			$read   = cinatra_it_read( $id );
			$before = cinatra_it_row( $id );
			$throw  = function ( $post_id ) use ( $id ) {
				if ( (int) $post_id === $id ) {
					throw new RuntimeException( 'cinatra-it deliberate throw' );
				}
			};
			$caught = null;
			add_action( 'pre_post_update', $throw );
			try {
				cinatra_it_call( CINATRA_ABILITY_POST_UPDATE_IF_UNCHANGED, cinatra_it_input( $read, array( 'content' => 'must not be written' ) ) );
			} catch ( RuntimeException $e ) {
				$caught = $e->getMessage();
			} finally {
				remove_action( 'pre_post_update', $throw );
			}
			cinatra_it_assert( 'cinatra-it deliberate throw' === $caught, 'the callback exception did not propagate unchanged', $caught );
			cinatra_it_assert( false === cinatra_content_transaction()['open'], 'the tracker still marks the transaction open' );
			$state = cinatra_content_open_transaction_state();
			cinatra_it_assert( '' === $state, 'a transaction is still open on the connection', array( $state, cinatra_it_diag() ) );
			cinatra_it_assert( cinatra_it_row( $id ) === $before, 'something was written' );
			return 'state="" after the throw';
		}
	);

	cinatra_it_case(
		'editor non-author without edit_others_posts -> not_found on read',
		function () {
			$id      = cinatra_it_post( 'publish', 'body' );
			$user_id = wp_insert_user(
				array(
					'user_login' => 'cinatra-it-editor-' . wp_generate_password( 8, false ),
					'user_pass'  => wp_generate_password( 24 ),
					'user_email' => 'cinatra-it-' . wp_generate_password( 8, false ) . '@example.com',
					'role'       => 'editor',
				)
			);
			cinatra_it_assert( ! is_wp_error( $user_id ), 'the editor user could not be created', is_wp_error( $user_id ) ? $user_id->get_error_message() : null );
			$GLOBALS['cinatra_it_editor'] = (int) $user_id;
			$editor                       = new WP_User( (int) $user_id );
			$editor->add_cap( 'edit_others_posts', false );
			wp_set_current_user( (int) $user_id );
			try {
				$can    = current_user_can( 'edit_post', $id );
				$result = cinatra_it_call( CINATRA_ABILITY_POST_READ, array( 'post_id' => $id ) );
			} finally {
				wp_set_current_user( (int) $GLOBALS['cinatra_it_admin'] );
			}
			cinatra_it_assert( false === $can, 'the editor without edit_others_posts can still edit the post (setup)' );
			cinatra_it_assert( cinatra_it_refused( $result, 'not_found' ) && ! isset( $result['post_content'] ), 'the read was not refused with not_found', $result );
			return 'code=not_found';
		}
	);
} catch ( Throwable $e ) {
	$cinatra_it_failure = 'FAIL ' . $GLOBALS['cinatra_it_current'] . ': ' . ( $e instanceof Cinatra_IT_Failure ? '' : get_class( $e ) . ': ' ) . $e->getMessage();
} finally {
	global $wpdb;
	$GLOBALS['cinatra_it_recording'] = false;
	remove_filter( 'query', 'cinatra_it_record_query' );
	// Close anything a failed case left open, then remove the fixtures.
	$wpdb->query( 'ROLLBACK' );
	$wpdb->query( 'SET autocommit = 1' );
	if ( function_exists( 'cinatra_content_transaction' ) ) {
		cinatra_content_transaction( false );
	}
	if ( ! empty( $GLOBALS['cinatra_it_admin'] ) ) {
		wp_set_current_user( (int) $GLOBALS['cinatra_it_admin'] );
	}
	foreach ( $GLOBALS['cinatra_it_posts'] as $cinatra_it_post_id ) {
		wp_delete_post( $cinatra_it_post_id, true );
	}
	$wpdb->delete( $wpdb->options, array( 'option_name' => $GLOBALS['cinatra_it_option'] ) );
	if ( 0 < $GLOBALS['cinatra_it_editor'] ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $GLOBALS['cinatra_it_editor'] );
	}
}

if ( null !== $cinatra_it_failure ) {
	cinatra_it_log( $cinatra_it_failure );
	cinatra_it_log( 'INFO at failure | ' . cinatra_it_diag() );
	if ( class_exists( 'WP_CLI' ) ) {
		WP_CLI::error( 'content abilities integration test failed' );
	}
	exit( 1 );
}
cinatra_it_log( 'OK all content abilities integration cases passed' );
