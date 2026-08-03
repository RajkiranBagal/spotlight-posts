<?php
/**
 * WP-CLI commands.
 *
 * @package VIP_Featured_Posts
 */

declare( strict_types = 1 );

namespace VIP_Featured_Posts\CLI;

use VIP_Featured_Posts\Index;

defined( 'ABSPATH' ) || exit;

/**
 * Manage the featured posts index.
 */
class Command {

	/**
	 * Rebuild the featured index from post meta.
	 *
	 * The index is derived data. Post meta is the source of truth, so the index can
	 * always be regenerated from it -- which is what makes it safe to treat a lost
	 * update as self-healing rather than as corruption.
	 *
	 * Run this after importing content, after a bulk meta change made outside
	 * WordPress, or any time the index and the meta appear to disagree.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would change without writing the option.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vip-featured rebuild
	 *     wp vip-featured rebuild --dry-run
	 *
	 * @param array $args       Positional arguments. Unused.
	 * @param array $assoc_args Associative arguments.
	 */
	public function rebuild( $args, $assoc_args ): void {
		$dry_run = isset( $assoc_args['dry-run'] );

		$before = Index\get_ids();

		if ( $dry_run ) {
			\WP_CLI::log( sprintf( 'Current index holds %d post(s).', count( $before ) ) );
			\WP_CLI::log( 'Dry run: nothing written.' );

			return;
		}

		$after = Index\rebuild();

		\WP_CLI::log( sprintf( 'Before: %d post(s).', count( $before ) ) );
		\WP_CLI::log( sprintf( 'After:  %d post(s).', count( $after ) ) );

		if ( count( $after ) === Index\MAX_IDS ) {
			\WP_CLI::warning(
				sprintf(
					'The index hit its ceiling of %d. Posts beyond that are not indexed.',
					Index\MAX_IDS
				)
			);
		}

		\WP_CLI::success( 'Featured index rebuilt.' );
	}

	/**
	 * Print the current featured index.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vip-featured list
	 *
	 * @param array $args       Positional arguments. Unused.
	 * @param array $assoc_args Associative arguments. Unused.
	 */
	public function list( $args, $assoc_args ): void {
		$ids = Index\get_ids();

		if ( empty( $ids ) ) {
			\WP_CLI::log( 'The featured index is empty.' );

			return;
		}

		$rows = array();

		foreach ( $ids as $position => $id ) {
			$rows[] = array(
				'position' => $position + 1,
				'id'       => $id,
				'status'   => (string) get_post_status( $id ),
				'title'    => (string) get_the_title( $id ),
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'position', 'id', 'status', 'title' ) );
	}
}

/**
 * Register the command with WP-CLI.
 */
function register(): void {
	\WP_CLI::add_command( 'vip-featured', __NAMESPACE__ . '\\Command' );
}
