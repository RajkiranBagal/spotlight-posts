/**
 * Reordering the featured posts list.
 *
 * Dragging is the fast path; the move buttons are the accessible one. Both mutate the
 * same DOM order, and saving reads that order back off the list, so neither is a
 * special case.
 */

( function ( $ ) {
	'use strict';

	var config = window.vipFeaturedOrder || {};

	if ( ! config.ajaxUrl ) {
		return;
	}

	var $list = $( '#vip-featured-order-list' );

	if ( ! $list.length ) {
		return;
	}

	var $feedback = $( '.vip-featured-order-feedback' );
	var $save = $( '#vip-featured-order-save' );

	/**
	 * Announce status. The container is aria-live, so this reaches screen readers.
	 *
	 * @param {string}  message Text to show.
	 * @param {boolean} isError Whether to style it as a failure.
	 */
	function announce( message, isError ) {
		$feedback
			.text( message )
			.toggleClass( 'is-error', Boolean( isError ) );
	}

	/**
	 * Mark the list as having changes that have not been saved.
	 */
	function markDirty() {
		$save.addClass( 'is-dirty' );
		announce( config.i18n.unsaved, false );
	}

	/**
	 * Read the current order out of the DOM.
	 *
	 * @return {number[]} Post IDs in display order.
	 */
	function currentOrder() {
		return $list
			.children( '[data-post-id]' )
			.map( function () {
				return parseInt( $( this ).attr( 'data-post-id' ), 10 );
			} )
			.get();
	}

	$list.sortable( {
		handle: '.vip-featured-order-handle',
		axis: 'y',
		cursor: 'grabbing',
		placeholder: 'vip-featured-order-placeholder',
		forcePlaceholderSize: true,
		update: markDirty,
	} );

	// Keyboard-reachable equivalent of dragging.
	$list.on( 'click', '.vip-featured-order-move', function () {
		var $button = $( this );
		var $item = $button.closest( '.vip-featured-order-item' );
		var goingUp = $button.attr( 'data-direction' ) === 'up';
		var $sibling = goingUp ? $item.prev( '.vip-featured-order-item' ) : $item.next( '.vip-featured-order-item' );

		if ( ! $sibling.length ) {
			return;
		}

		if ( goingUp ) {
			$item.insertBefore( $sibling );
		} else {
			$item.insertAfter( $sibling );
		}

		// Focus follows the item, or the moved row loses the keyboard entirely.
		$button.trigger( 'focus' );

		markDirty();
	} );

	$save.on( 'click', function () {
		var order = currentOrder();

		$save.prop( 'disabled', true );
		announce( config.i18n.saving, false );

		$.post( config.ajaxUrl, {
			action: config.action,
			nonce: config.nonce,
			order: order,
		} )
			.done( function ( payload ) {
				if ( ! payload || ! payload.success ) {
					throw new Error( 'Request failed' );
				}

				$save.removeClass( 'is-dirty' );
				announce( config.i18n.saved, false );
			} )
			.fail( function () {
				announce( config.i18n.failed, true );
			} )
			.always( function () {
				$save.prop( 'disabled', false );
			} );
	} );

	$list.on( 'click', '.vip-featured-order-remove', function () {
		var $button = $( this );
		var $item = $button.closest( '.vip-featured-order-item' );

		$button.prop( 'disabled', true );

		$.post( config.ajaxUrl, {
			action: config.removeAction,
			nonce: config.nonce,
			post_id: $button.attr( 'data-post-id' ),
		} )
			.done( function ( payload ) {
				if ( ! payload || ! payload.success ) {
					throw new Error( 'Request failed' );
				}

				$item.remove();
				announce( config.i18n.removed, false );
			} )
			.fail( function () {
				$button.prop( 'disabled', false );
				announce( config.i18n.failed, true );
			} );
	} );

	// Leaving with a dirty list would silently discard the arrangement.
	window.addEventListener( 'beforeunload', function ( event ) {
		if ( $save.hasClass( 'is-dirty' ) ) {
			event.preventDefault();
			event.returnValue = '';
		}
	} );
} )( window.jQuery );
