<?php
/**
 * WP-CLI commands.
 *
 * @package Spotlight_Posts
 */

declare( strict_types = 1 );

namespace Spotlight_Posts\Cli;

use Spotlight_Posts\Featured\Index;
use Spotlight_Posts\Registrable;

defined( 'ABSPATH' ) || exit;

/**
 * Manage the spotlight index.
 *
 * The index is derived data: post meta is the source of truth, so the index can always be
 * regenerated from it. That is what makes it safe to treat a lost update as self-healing
 * rather than as corruption -- provided there is a way to heal it, which is this.
 */
final class Command implements Registrable {

	/**
	 * Ordered ID index.
	 *
	 * @var Index
	 */
	private Index $index;

	/**
	 * @param Index $index Ordered ID index.
	 */
	public function __construct( Index $index ) {
		$this->index = $index;
	}

	/**
	 * Register the command with WP-CLI.
	 *
	 * Registration happens immediately rather than on a hook: WP-CLI collects commands as
	 * plugins load, and a command added later than that is never seen.
	 */
	public function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		\WP_CLI::add_command( 'spotlight', $this );
	}

	/**
	 * Rebuild the spotlight index from post meta.
	 *
	 * Run this after importing content, after a bulk meta change made outside WordPress,
	 * or any time the index and the meta appear to disagree.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would change without writing the option.
	 *
	 * ## EXAMPLES
	 *
	 *     wp spotlight rebuild
	 *     wp spotlight rebuild --dry-run
	 *
	 * @param array $args       Positional arguments. Unused.
	 * @param array $assoc_args Associative arguments.
	 */
	public function rebuild( $args, $assoc_args ): void {
		$before = $this->index->ids();

		if ( isset( $assoc_args['dry-run'] ) ) {
			\WP_CLI::log( sprintf( 'Current index holds %d post(s).', count( $before ) ) );
			\WP_CLI::log( 'Dry run: nothing written.' );

			return;
		}

		$after = $this->index->rebuild();

		\WP_CLI::log( sprintf( 'Before: %d post(s).', count( $before ) ) );
		\WP_CLI::log( sprintf( 'After:  %d post(s).', count( $after ) ) );

		if ( count( $after ) === Index::MAX_IDS ) {
			\WP_CLI::warning(
				sprintf(
					'The index hit its ceiling of %d. Posts beyond that are not indexed.',
					Index::MAX_IDS
				)
			);
		}

		\WP_CLI::success( 'Spotlight index rebuilt.' );
	}

	/**
	 * Print the current spotlight index.
	 *
	 * ## EXAMPLES
	 *
	 *     wp spotlight list
	 *
	 * @param array $args       Positional arguments. Unused.
	 * @param array $assoc_args Associative arguments. Unused.
	 */
	public function list( $args, $assoc_args ): void {
		$ids = $this->index->ids();

		if ( empty( $ids ) ) {
			\WP_CLI::log( 'The spotlight index is empty.' );

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
