<?php
/**
 * Real-database integration test for cinatra/post-update-if-unchanged
 * (cinatra#3745), run inside a real WordPress with the plugin active against
 * a real MySQL server:
 *
 *   wp eval-file tests/integration/test-post-update-if-unchanged-real.php --path=<wp>
 *
 * CI runs it in the plugin-check job ( mysql:8.4 service ). Cases: a stored
 * write ( and the revision core stores on post_updated ); a change that
 * another save commits between the ability's read and its UPDATE gives
 * post_changed and nothing is written. The test creates its own post,
 * deletes it at the end ( also after a failure ) and exits non-zero on a
 * failure.
 *
 * @package Cinatra
 */

if ( ! defined( 'ABSPATH' ) || ! function_exists( 'cinatra_ability_post_update_if_unchanged' ) ) {
	fwrite( STDERR, "Run through 'wp eval-file' in a WordPress with the cinatra plugin active.\n" );
	exit( 1 );
}

/**
 * Print one line.
 *
 * @param string $line The line.
 * @return void
 */
function cinatra_it_log( string $line ): void {
	WP_CLI::log( $line );
}

/**
 * Fail unless the condition holds.
 *
 * @param bool   $condition The condition.
 * @param string $reason    The failure reason.
 * @param mixed  $detail    A value to print with it.
 * @throws RuntimeException When the condition does not hold.
 * @return void
 */
function cinatra_it_assert( bool $condition, string $reason, $detail = null ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $reason . ( null === $detail ? '' : ' | ' . wp_json_encode( $detail ) ) );
	}
}

/**
 * Call an ability through the Abilities API when core has it, else its callback.
 *
 * @param string $name  Ability name.
 * @param array  $input Ability input.
 * @return array The result.
 */
function cinatra_it_call( string $name, array $input ): array {
	if ( function_exists( 'wp_get_ability' ) ) {
		$result = wp_get_ability( $name )->execute( $input );
		cinatra_it_assert( ! is_wp_error( $result ), 'WP_Ability::execute returned a WP_Error', is_wp_error( $result ) ? $result->get_error_message() : null );
		return $result;
	}
	return CINATRA_ABILITY_POST_READ === $name ? cinatra_ability_post_read( $input ) : cinatra_ability_post_update_if_unchanged( $input );
}

/**
 * The write input based on a fresh read, with the given changes.
 *
 * @param int   $id      Post ID.
 * @param array $changes title / content / excerpt / status.
 * @return array The input.
 */
function cinatra_it_input( int $id, array $changes ): array {
	$read = cinatra_it_call( CINATRA_ABILITY_POST_READ, array( 'post_id' => $id ) );
	cinatra_it_assert( true === ( $read['success'] ?? null ), 'post-read did not succeed', $read );
	return array_merge(
		array(
			'post_id'             => $id,
			'if_unmodified_since' => $read['post_modified_gmt'],
			'expected'            => array(
				'title'     => $read['post_title'],
				'content'   => $read['post_content'],
				'excerpt'   => $read['post_excerpt'],
				'status'    => $read['post_status'],
				'row_token' => $read['row_token'],
			),
		),
		$changes
	);
}

/**
 * The stored row, straight from the database.
 *
 * @param int $id Post ID.
 * @return array The row.
 */
function cinatra_it_row( int $id ): array {
	global $wpdb;
	return (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID = %d", $id ), ARRAY_A );
}

$cinatra_it_post = 0;
$cinatra_it_fail = null;
try {
	global $wpdb;
	$admins = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
		)
	);
	cinatra_it_assert( ! empty( $admins ), 'no administrator user found' );
	wp_set_current_user( (int) $admins[0]->ID );
	$cinatra_it_post = wp_insert_post(
		array(
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_title'   => 'cinatra-it title',
			'post_content' => 'cinatra-it body',
			'post_excerpt' => 'cinatra-it excerpt',
		),
		true
	);
	cinatra_it_assert( ! is_wp_error( $cinatra_it_post ), 'wp_insert_post failed' );
	$cinatra_it_post = (int) $cinatra_it_post;
	// An older modification time, so the write's new one differs.
	$wpdb->update(
		$wpdb->posts,
		array(
			'post_modified'     => '2020-01-01 00:00:00',
			'post_modified_gmt' => '2020-01-01 00:00:00',
		),
		array( 'ID' => $cinatra_it_post )
	);
	clean_post_cache( $cinatra_it_post );

	// Stored, and core stores a revision on post_updated.
	$revisions = count( wp_get_post_revisions( $cinatra_it_post ) );
	$result    = cinatra_it_call( CINATRA_ABILITY_POST_UPDATE_IF_UNCHANGED, cinatra_it_input( $cinatra_it_post, array( 'content' => 'cinatra-it "new" body \\ ok' ) ) );
	cinatra_it_assert( true === ( $result['success'] ?? null ), 'the write did not succeed', $result );
	$row = cinatra_it_row( $cinatra_it_post );
	cinatra_it_assert( 'cinatra-it "new" body \\ ok' === $row['post_content'] && 'cinatra-it title' === $row['post_title'], 'the stored row is not the written change', $row );
	cinatra_it_assert( cinatra_content_row_token( $row ) === $result['row_token'], 'the answer does not describe the stored row', $result );
	$after = wp_get_post_revisions( $cinatra_it_post );
	cinatra_it_assert( count( $after ) > $revisions && 'cinatra-it "new" body \\ ok' === reset( $after )->post_content, 'no revision of the stored change was created', count( $after ) );
	cinatra_it_log( 'PASS stored, the revision created' );

	// Another save commits between the ability's read and its UPDATE: a site
	// query filter sends it right before the conditional UPDATE.
	$input  = cinatra_it_input( $cinatra_it_post, array( 'content' => 'cinatra-it lost body' ) );
	$hits   = 0;
	$other  = function ( $query ) use ( &$other, &$hits, $cinatra_it_post ) {
		global $wpdb;
		if ( 0 === strpos( ltrim( (string) $query ), 'UPDATE `' . $wpdb->posts . '` SET' ) && false !== strpos( (string) $query, 'SHA2(' ) ) {
			++$hits;
			remove_filter( 'query', $other );
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_title = %s WHERE ID = %d", 'cinatra-it saved by another request', $cinatra_it_post ) );
		}
		return $query;
	};
	add_filter( 'query', $other );
	try {
		$result = cinatra_it_call( CINATRA_ABILITY_POST_UPDATE_IF_UNCHANGED, $input );
	} finally {
		remove_filter( 'query', $other );
	}
	$row = cinatra_it_row( $cinatra_it_post );
	cinatra_it_assert( 1 === $hits, 'the concurrent save did not run before the conditional UPDATE (setup)' );
	cinatra_it_assert( false === ( $result['success'] ?? null ) && 'post_changed' === ( $result['code'] ?? null ), 'the write was not refused with post_changed', $result );
	cinatra_it_assert( 'cinatra-it saved by another request' === $row['post_title'] && 'cinatra-it "new" body \\ ok' === $row['post_content'], 'something was written, or the other save was overwritten', $row );
	cinatra_it_log( 'PASS a concurrent change gives post_changed, nothing written' );
} catch ( Throwable $e ) {
	$cinatra_it_fail = get_class( $e ) . ': ' . $e->getMessage();
} finally {
	if ( 0 < $cinatra_it_post ) {
		wp_delete_post( $cinatra_it_post, true );
	}
}
if ( null !== $cinatra_it_fail ) {
	WP_CLI::error( 'content write integration test failed: ' . $cinatra_it_fail );
}
cinatra_it_log( 'OK content write integration cases passed' );
