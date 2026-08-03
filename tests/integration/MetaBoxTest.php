<?php
/**
 * Tests for the editor save handler's guards.
 *
 * @package VIP_Featured_Posts
 */

declare( strict_types = 1 );

namespace VIP_Featured_Posts\Tests;

use VIP_Featured_Posts;
use VIP_Featured_Posts\Meta_Box;
use VIP_Featured_Posts\Schedule;

/**
 * @covers \VIP_Featured_Posts\Meta_Box
 */
class MetaBoxTest extends TestCase {

	/**
	 * Post being edited.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * Create a post and an editor to act as.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$_POST = array();
	}

	/**
	 * Clear request state so one test cannot leak into the next.
	 */
	public function tear_down(): void {
		$_POST = array();

		parent::tear_down();
	}

	/**
	 * Populate $_POST as the meta box would.
	 *
	 * @param bool $checked Whether the featured box is ticked.
	 * @param bool $valid_nonce Whether to send a valid nonce.
	 */
	private function post_form( bool $checked, bool $valid_nonce = true ): void {
		$_POST = array();

		if ( $valid_nonce ) {
			$_POST[ Meta_Box\NONCE_NAME ] = wp_create_nonce( Meta_Box\NONCE_ACTION );
		}

		if ( $checked ) {
			$_POST[ Meta_Box\FIELD_NAME ] = '1';
		}
	}

	/**
	 * Is the post currently flagged?
	 */
	private function is_featured(): bool {
		return '1' === get_post_meta( $this->post_id, VIP_Featured_Posts\META_KEY, true );
	}

	/**
	 * The happy path: a valid, authorised submission sets the flag.
	 */
	public function test_valid_submission_sets_the_flag(): void {
		$this->post_form( true );

		Meta_Box\save( $this->post_id );

		$this->assertTrue( $this->is_featured() );
	}

	/**
	 * Submitting with the box unticked clears the flag.
	 */
	public function test_unchecked_submission_clears_the_flag(): void {
		update_post_meta( $this->post_id, VIP_Featured_Posts\META_KEY, '1' );

		$this->post_form( false );

		Meta_Box\save( $this->post_id );

		$this->assertFalse( $this->is_featured() );
	}

	/**
	 * A missing nonce must abort before anything is written.
	 */
	public function test_missing_nonce_is_rejected(): void {
		$this->post_form( true, false );

		Meta_Box\save( $this->post_id );

		$this->assertFalse( $this->is_featured() );
	}

	/**
	 * A forged nonce must abort too.
	 */
	public function test_invalid_nonce_is_rejected(): void {
		$_POST = array(
			Meta_Box\NONCE_NAME => 'not-a-real-nonce',
			Meta_Box\FIELD_NAME => '1',
		);

		Meta_Box\save( $this->post_id );

		$this->assertFalse( $this->is_featured() );
	}

	/**
	 * A valid nonce is not authorisation. A subscriber must still be refused.
	 */
	public function test_user_without_the_capability_is_rejected(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->post_form( true );

		Meta_Box\save( $this->post_id );

		$this->assertFalse( $this->is_featured() );
	}

	/**
	 * Autosaves must be ignored, or the flag would be wiped every time WordPress
	 * autosaves a post whose form fields are not present.
	 */
	public function test_autosave_is_ignored(): void {
		update_post_meta( $this->post_id, VIP_Featured_Posts\META_KEY, '1' );

		$this->post_form( false );

		// Deliberately the filter and not define( 'DOING_AUTOSAVE' ): a constant cannot
		// be unset, so defining it here would silently disable saving for every test
		// that ran afterwards in this process.
		add_filter( 'vip_featured_posts_is_autosave', '__return_true' );

		Meta_Box\save( $this->post_id );

		remove_filter( 'vip_featured_posts_is_autosave', '__return_true' );

		$this->assertTrue(
			$this->is_featured(),
			'An autosave must not clear the flag.'
		);
	}

	/**
	 * The registered sanitize callback only ever stores '1' or an empty string.
	 */
	public function test_sanitize_callback_normalises_values(): void {
		$this->assertSame( '1', Meta_Box\sanitize_meta( '1' ) );
		$this->assertSame( '', Meta_Box\sanitize_meta( 'yes' ) );
		$this->assertSame( '', Meta_Box\sanitize_meta( '<script>alert(1)</script>' ) );
		$this->assertSame( '', Meta_Box\sanitize_meta( '' ) );
	}

	/**
	 * An empty "featured until" means no expiry.
	 */
	public function test_parse_until_treats_empty_as_no_expiry(): void {
		$this->assertSame( 0, Meta_Box\parse_until( '' ) );
		$this->assertSame( 0, Meta_Box\parse_until( '   ' ) );
	}

	/**
	 * A malformed value means no expiry, not an arbitrary one.
	 */
	public function test_parse_until_rejects_garbage(): void {
		$this->assertSame( 0, Meta_Box\parse_until( 'not a date' ) );
		$this->assertSame( 0, Meta_Box\parse_until( '2026-13-45T99:99' ) );
	}

	/**
	 * The value is interpreted in the site's timezone, not the server's.
	 *
	 * datetime-local sends wall-clock time with no offset. Reading it as server time
	 * would mean "featured until 5pm" landing at a different real moment depending on
	 * where the server sits.
	 */
	public function test_parse_until_uses_the_site_timezone(): void {
		$original = get_option( 'timezone_string' );

		update_option( 'timezone_string', 'Asia/Kolkata' );
		$kolkata = Meta_Box\parse_until( '2026-08-07T17:00' );

		update_option( 'timezone_string', 'UTC' );
		$utc = Meta_Box\parse_until( '2026-08-07T17:00' );

		update_option( 'timezone_string', (string) $original );

		// Kolkata is UTC+5:30, so the same wall-clock time is 5.5 hours earlier in UTC.
		$this->assertSame(
			5.5 * HOUR_IN_SECONDS,
			(float) ( $utc - $kolkata ),
			'The same wall-clock time in two zones must be two different instants.'
		);
	}

	/**
	 * Saving with an expiry stores it and arms the cron event.
	 */
	public function test_save_stores_the_expiry(): void {
		$this->post_form( true );

		$when = wp_date( 'Y-m-d\TH:i', time() + DAY_IN_SECONDS );

		$_POST[ Meta_Box\UNTIL_FIELD_NAME ] = $when;

		Meta_Box\save( $this->post_id );

		$this->assertTrue( $this->is_featured() );
		$this->assertGreaterThan( time(), Schedule\get_expiry( $this->post_id ) );
		$this->assertNotFalse( wp_next_scheduled( Schedule\CRON_HOOK, array( $this->post_id ) ) );
	}

	/**
	 * Saving without an expiry clears any previously stored one.
	 */
	public function test_save_without_an_expiry_clears_it(): void {
		Schedule\set_expiry( $this->post_id, time() + DAY_IN_SECONDS );

		$this->post_form( true );
		$_POST[ Meta_Box\UNTIL_FIELD_NAME ] = '';

		Meta_Box\save( $this->post_id );

		$this->assertSame( 0, Schedule\get_expiry( $this->post_id ) );
		$this->assertFalse( wp_next_scheduled( Schedule\CRON_HOOK, array( $this->post_id ) ) );
	}

	/**
	 * Unfeaturing clears the expiry too, rather than leaving an orphaned event armed.
	 */
	public function test_unfeaturing_clears_the_expiry(): void {
		update_post_meta( $this->post_id, VIP_Featured_Posts\META_KEY, '1' );
		Schedule\set_expiry( $this->post_id, time() + DAY_IN_SECONDS );

		$this->post_form( false );

		Meta_Box\save( $this->post_id );

		$this->assertFalse( $this->is_featured() );
		$this->assertSame( 0, Schedule\get_expiry( $this->post_id ) );
		$this->assertFalse( wp_next_scheduled( Schedule\CRON_HOOK, array( $this->post_id ) ) );
	}
}
