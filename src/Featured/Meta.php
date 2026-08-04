<?php
/**
 * Registration for the post meta this plugin owns.
 *
 * @package Spotlight_Posts
 */

declare( strict_types = 1 );

namespace Spotlight_Posts\Featured;

use Spotlight_Posts\Registrable;
use Spotlight_Posts\Support\Cache;
use Spotlight_Posts\Support\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Declares the featured flag and the editorial label to WordPress.
 *
 * Separate from the meta box on purpose. Registration is a data concern that has to
 * happen on every request; the meta box is a UI concern that only exists in the admin.
 * They lived together until the label was added, which meant the featured flag's
 * sanitize and auth callbacks were never registered outside wp-admin -- so a WP-CLI or
 * REST write stored whatever it was given, verbatim.
 */
final class Meta implements Registrable {

	/**
	 * Meta key holding an editorial label.
	 */
	public const LABEL_KEY = '_spotlight_label';

	/**
	 * Longest label that will be stored.
	 *
	 * A badge is a couple of words. Truncating on write rather than on render keeps the
	 * constraint in one place and stops a long label from breaking a card layout.
	 */
	public const LABEL_MAX_LENGTH = 40;

	/**
	 * Supported post types.
	 *
	 * @var PostTypes
	 */
	private PostTypes $post_types;

	/**
	 * Object cache.
	 *
	 * @var Cache
	 */
	private Cache $cache;

	/**
	 * @param PostTypes $post_types Supported post types.
	 * @param Cache     $cache      Object cache.
	 */
	public function __construct( PostTypes $post_types, Cache $cache ) {
		$this->post_types = $post_types;
		$this->cache      = $cache;
	}

	/**
	 * Register the meta and keep the cache honest about label changes.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_meta' ) );

		// A label is part of the rendered payload, so changing one invalidates the
		// cached lists exactly as changing the flag does.
		add_action( 'added_post_meta', array( $this, 'invalidate_on_label_change' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'invalidate_on_label_change' ), 10, 3 );
		add_action( 'deleted_post_meta', array( $this, 'invalidate_on_label_change' ), 10, 3 );
	}

	/**
	 * Register both keys against every supported post type.
	 */
	public function register_meta(): void {
		foreach ( $this->post_types->all() as $post_type ) {
			register_post_meta(
				$post_type,
				Index::META_KEY,
				array(
					'type'              => 'string',
					'single'            => true,
					'default'           => '',
					// Protected meta stays out of the REST post object; the plugin
					// exposes its own read-only endpoint instead.
					'show_in_rest'      => false,
					'sanitize_callback' => array( $this, 'sanitize_featured' ),
					'auth_callback'     => array( $this, 'auth' ),
				)
			);

			register_post_meta(
				$post_type,
				self::LABEL_KEY,
				array(
					'type'              => 'string',
					'single'            => true,
					'default'           => '',
					'show_in_rest'      => false,
					'sanitize_callback' => array( $this, 'sanitize_label' ),
					'auth_callback'     => array( $this, 'auth' ),
				)
			);
		}
	}

	/**
	 * Normalise the featured flag to either '1' or an empty string.
	 *
	 * @param mixed $value Incoming meta value.
	 * @return string Sanitized value.
	 */
	public function sanitize_featured( $value ): string {
		return '1' === (string) $value ? '1' : '';
	}

	/**
	 * Normalise a label to a short plain-text string.
	 *
	 * @param mixed $value Incoming meta value.
	 * @return string Sanitized label.
	 */
	public function sanitize_label( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$label = sanitize_text_field( (string) $value );

		// mb_substr, not substr: cutting a multibyte string mid-character produces
		// invalid UTF-8 that then has to be escaped or repaired downstream.
		if ( function_exists( 'mb_substr' ) ) {
			$label = mb_substr( $label, 0, self::LABEL_MAX_LENGTH );
		} else {
			$label = substr( $label, 0, self::LABEL_MAX_LENGTH );
		}

		return trim( $label );
	}

	/**
	 * Authorise writes to either protected key.
	 *
	 * @param bool  $allowed   Whether the user can add the meta. Unused.
	 * @param mixed $meta_key  Meta key being written. Unused.
	 * @param mixed $object_id Post being written to.
	 * @return bool Whether the current user may write this meta.
	 */
	public function auth( $allowed, $meta_key, $object_id ): bool {
		return current_user_can( 'edit_post', (int) $object_id );
	}

	/**
	 * Flush cached lists when a label is written.
	 *
	 * @param int|int[] $meta_id   Meta row ID. Unused.
	 * @param int       $object_id Post the meta belongs to. Unused.
	 * @param mixed     $meta_key  Meta key that was written.
	 */
	public function invalidate_on_label_change( $meta_id, $object_id, $meta_key ): void {
		if ( self::LABEL_KEY !== $meta_key ) {
			return;
		}

		$this->cache->flush();
	}
}
