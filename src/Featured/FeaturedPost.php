<?php
/**
 * A single spotlighted post, as consumers see it.
 *
 * @package Spotlight_Posts
 */

declare( strict_types = 1 );

namespace Spotlight_Posts\Featured;

defined( 'ABSPATH' ) || exit;

/**
 * The light payload the block and the REST route both render.
 *
 * Replaces the associative array this used to be. The array shape was documented in a
 * docblock and enforced by nothing, so a typo in a key produced a silent empty string at
 * render time rather than an error. Readonly properties make the shape the type.
 *
 * Deliberately not a WP_Post wrapper: only the four fields a consumer actually renders
 * are carried, so what goes into the object cache stays small.
 */
final class FeaturedPost implements \JsonSerializable {

	/**
	 * Post ID.
	 *
	 * @var int
	 */
	public readonly int $id;

	/**
	 * Rendered post title.
	 *
	 * @var string
	 */
	public readonly string $title;

	/**
	 * Permalink.
	 *
	 * @var string
	 */
	public readonly string $url;

	/**
	 * Plain-text excerpt.
	 *
	 * @var string
	 */
	public readonly string $excerpt;

	/**
	 * @param int    $id      Post ID.
	 * @param string $title   Rendered title.
	 * @param string $url     Permalink.
	 * @param string $excerpt Plain-text excerpt.
	 */
	public function __construct( int $id, string $title, string $url, string $excerpt ) {
		$this->id      = $id;
		$this->title   = $title;
		$this->url     = $url;
		$this->excerpt = $excerpt;
	}

	/**
	 * Build from a WP_Post.
	 *
	 * @param \WP_Post $post Source post.
	 * @return self Featured post.
	 */
	public static function from_post( \WP_Post $post ): self {
		return new self(
			(int) $post->ID,
			get_the_title( $post ),
			(string) get_permalink( $post ),
			wp_strip_all_tags( get_the_excerpt( $post ) )
		);
	}

	/**
	 * Rebuild from a cached array.
	 *
	 * The object cache stores plain arrays rather than serialized objects: a serialized
	 * class in the cache breaks the moment the class changes shape, and every entry
	 * written before a deploy would fail to unserialize after it.
	 *
	 * @param array $data Cached representation.
	 * @return self Featured post.
	 */
	public static function from_array( array $data ): self {
		return new self(
			(int) ( $data['id'] ?? 0 ),
			(string) ( $data['title'] ?? '' ),
			(string) ( $data['url'] ?? '' ),
			(string) ( $data['excerpt'] ?? '' )
		);
	}

	/**
	 * Plain representation, for caching and for the REST response.
	 *
	 * @return array{id:int, title:string, url:string, excerpt:string} Post data.
	 */
	public function to_array(): array {
		return array(
			'id'      => $this->id,
			'title'   => $this->title,
			'url'     => $this->url,
			'excerpt' => $this->excerpt,
		);
	}

	/**
	 * Serialize for JSON, so the REST route can return these directly.
	 *
	 * @return array{id:int, title:string, url:string, excerpt:string} Post data.
	 */
	public function jsonSerialize(): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Defined by JsonSerializable.
		return $this->to_array();
	}
}
