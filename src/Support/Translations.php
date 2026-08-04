<?php
/**
 * Translation loading.
 *
 * @package Spotlight_Posts
 */

declare( strict_types = 1 );

namespace Spotlight_Posts\Support;

use Spotlight_Posts\Registrable;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the plugin's own text domain.
 *
 * Needed because this is not distributed through wordpress.org, where translations are
 * fetched and loaded automatically.
 */
final class Translations implements Registrable {

	/**
	 * Plugin main file, used to derive the languages path.
	 *
	 * @var string
	 */
	private string $plugin_file;

	/**
	 * @param string $plugin_file Plugin main file.
	 */
	public function __construct( string $plugin_file ) {
		$this->plugin_file = $plugin_file;
	}

	/**
	 * Load on init.
	 *
	 * Not earlier: since WordPress 6.7, loading a text domain before init triggers a
	 * _doing_it_wrong notice, because translations cannot be resolved until the locale is
	 * settled.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'load' ) );
	}

	/**
	 * Load the text domain.
	 */
	public function load(): void {
		load_plugin_textdomain(
			'spotlight-posts',
			false,
			dirname( plugin_basename( $this->plugin_file ) ) . '/languages'
		);
	}
}
