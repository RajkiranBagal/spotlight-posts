<?php
/**
 * Versioned object cache.
 *
 * Exists to break a dependency cycle. Cache invalidation is a cross-cutting concern, and
 * embedding it inside the index and the scheduler meant both called back into the query
 * module while the query module called into them:
 *
 *     Index    -> Query::bump_cache_version()      Query -> Index::get_ids()
 *     Schedule -> Query::bump_cache_version()      Query -> Schedule::is_expired()
 *
 * PHP resolves those at call time so nothing broke, but neither module could be reasoned
 * about or tested in isolation. Pulling the cache out gives all three a collaborator they
 * depend on in one direction only.
 *
 * @package Spotlight_Posts
 */

declare( strict_types = 1 );

namespace Spotlight_Posts\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and invalidates the plugin's cached lists.
 */
final class Cache {

	/**
	 * Object cache group for everything this plugin stores.
	 */
	public const GROUP = 'spotlight_posts';

	/**
	 * Key holding the current cache version number.
	 */
	private const VERSION_KEY = 'cache_version';

	/**
	 * Read the current cache version, seeding it when absent.
	 *
	 * Versioning the key means invalidation is a single integer increment rather than an
	 * enumerate-and-delete over every cached permutation -- which matters on VIP, where
	 * the object cache is shared, remote, and offers no delete-by-prefix.
	 *
	 * @return int Current cache version.
	 */
	public function version(): int {
		$version = wp_cache_get( self::VERSION_KEY, self::GROUP );

		if ( false === $version ) {
			$version = 1;
			wp_cache_set( self::VERSION_KEY, $version, self::GROUP );
		}

		return (int) $version;
	}

	/**
	 * Invalidate every cached list by moving the version forward.
	 *
	 * wp_cache_incr() returns false when the key is missing, in which case it is seeded
	 * instead.
	 */
	public function flush(): void {
		if ( false === wp_cache_incr( self::VERSION_KEY, 1, self::GROUP ) ) {
			wp_cache_set( self::VERSION_KEY, 1, self::GROUP );
		}
	}

	/**
	 * Build a cache key that carries the current version.
	 *
	 * @param string               $name  Logical name for the entry.
	 * @param array<string, mixed> $parts Values that vary the result.
	 * @return string Versioned cache key.
	 */
	public function key( string $name, array $parts = array() ): string {
		$suffix = '';

		foreach ( $parts as $label => $value ) {
			$suffix .= sprintf( '_%s%s', $label, (string) $value );
		}

		return sprintf( '%s_v%d%s', $name, $this->version(), $suffix );
	}

	/**
	 * Read a cached array.
	 *
	 * Returns null rather than false for a miss, so a legitimately cached empty array is
	 * not mistaken for one.
	 *
	 * @param string $key Cache key.
	 * @return array|null Cached value, or null when absent.
	 */
	public function get( string $key ): ?array {
		$cached = wp_cache_get( $key, self::GROUP );

		return is_array( $cached ) ? $cached : null;
	}

	/**
	 * Store a value.
	 *
	 * The TTL is a literal rather than a named constant so VIP's LowExpiryCacheTime sniff
	 * can statically verify it clears the 300s floor. It is only a backstop: the versioned
	 * key already gives immediate invalidation on write.
	 *
	 * @param string $key   Cache key.
	 * @param array  $value Value to store.
	 */
	public function set( string $key, array $value ): void {
		wp_cache_set( $key, $value, self::GROUP, 300 );
	}
}
