<?php
/**
 * PHPUnit bootstrap: loads the WordPress test suite, then this plugin.
 *
 * @package Spotlight_Posts
 */

declare( strict_types = 1 );

$spotlight_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $spotlight_tests_dir ) {
	$spotlight_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $spotlight_tests_dir . '/includes/functions.php' ) ) {
	fwrite(
		STDERR,
		sprintf(
			"Could not find the WordPress test suite at %s.\n" .
			"Run bin/install-wp-tests.sh, or set WP_TESTS_DIR to an existing checkout.\n",
			$spotlight_tests_dir
		)
	);

	exit( 1 );
}

require_once $spotlight_tests_dir . '/includes/functions.php';

/**
 * Load the plugin into the test WordPress install.
 *
 * muplugins_loaded fires before regular plugins would normally load, which is early
 * enough for the plugin's own init hooks to register normally.
 */
tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/spotlight-posts.php';
	}
);

require $spotlight_tests_dir . '/includes/bootstrap.php';

/*
 * Loaded after the WordPress bootstrap, not before: the shared base class extends
 * WP_UnitTestCase, which does not exist until the suite above has run. It also lives
 * outside tests/integration/ so PHPUnit does not try to collect an abstract class as
 * a test.
 */
require __DIR__ . '/TestCase.php';
