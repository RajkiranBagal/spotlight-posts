<?php
/**
 * Composition root.
 *
 * @package Spotlight_Posts
 */

declare( strict_types = 1 );

namespace Spotlight_Posts;

use Spotlight_Posts\Featured\Index;
use Spotlight_Posts\Featured\Repository;
use Spotlight_Posts\Featured\Schedule;
use Spotlight_Posts\Support\Cache;
use Spotlight_Posts\Support\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the plugin's services and lets each register its own hooks.
 *
 * Objects are constructed once, here, and passed to whoever needs them. Nothing reaches
 * out for a dependency it was not given, which is what keeps the dependency graph
 * readable -- and testable, since a test can build the same graph with substitutes.
 */
final class Plugin {

	/**
	 * The booted instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Constructed services, keyed by class name.
	 *
	 * @var array<string, object>
	 */
	private array $services = array();

	/**
	 * Build the object graph.
	 */
	private function __construct() {
		$post_types = new PostTypes();
		$cache      = new Cache();

		$index    = new Index( $cache, $post_types );
		$schedule = new Schedule( $cache, $post_types );

		// Repository depends on all three; none of them depend back on it.
		$repository = new Repository( $index, $schedule, $cache, $post_types );

		$this->services = array(
			PostTypes::class  => $post_types,
			Cache::class      => $cache,
			Index::class      => $index,
			Schedule::class   => $schedule,
			Repository::class => $repository,
		);
	}

	/**
	 * Boot the plugin once.
	 */
	public static function boot(): void {
		if ( null !== self::$instance ) {
			return;
		}

		self::$instance = new self();
		self::$instance->register_all();
	}

	/**
	 * The booted instance, booting it if necessary.
	 *
	 * Exists so the remaining procedural modules can reach the services while they are
	 * converted. It is transitional scaffolding, not the intended way to obtain a
	 * dependency -- each module that becomes a class takes what it needs through its
	 * constructor instead, and this goes away with the last of them.
	 *
	 * @return Plugin Booted instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::boot();
		}

		return self::$instance;
	}

	/**
	 * Fetch a service.
	 *
	 * @template T of object
	 * @param class-string<T> $class_name Service class name.
	 * @return T Service instance.
	 * @throws \InvalidArgumentException When the service was never registered.
	 */
	public function get( string $class_name ): object {
		if ( ! isset( $this->services[ $class_name ] ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Unknown service: %s', esc_html( $class_name ) )
			);
		}

		return $this->services[ $class_name ];
	}

	/**
	 * Let every registrable service attach its hooks.
	 */
	private function register_all(): void {
		foreach ( $this->services as $service ) {
			if ( $service instanceof Registrable ) {
				$service->register();
			}
		}
	}

	/**
	 * Rebuild the index when the plugin is activated.
	 *
	 * A site whose posts were already flagged surfaces them immediately rather than
	 * waiting for the first read to notice the option is missing.
	 */
	public static function activate(): void {
		self::instance()->get( Index::class )->rebuild();
	}
}
