/**
 * Featured controls in the posts list table.
 *
 * Two jobs: the per-row toggle, and seeding the Quick Edit checkbox from the row it
 * was opened for. Written against the DOM directly rather than through a build step,
 * because it is small enough that a bundler would add more moving parts than it removes.
 */

( function () {
	'use strict';

	var config = window.spotlightPosts || {};

	if ( ! config.ajaxUrl ) {
		return;
	}

	/**
	 * Reflect featured state on a toggle button.
	 *
	 * @param {HTMLElement} button   Toggle button.
	 * @param {boolean}     featured Whether the post is now featured.
	 */
	function setState( button, featured ) {
		var icon = button.querySelector( '.spotlight-icon' );
		var label = button.querySelector( '.screen-reader-text' );

		button.classList.toggle( 'is-featured', featured );
		button.setAttribute( 'aria-pressed', featured ? 'true' : 'false' );

		if ( icon ) {
			icon.classList.toggle( 'dashicons-star-filled', featured );
			icon.classList.toggle( 'dashicons-star-empty', ! featured );
		}

		if ( label && config.i18n ) {
			label.textContent = featured ? config.i18n.unfeature : config.i18n.feature;
		}
	}

	/**
	 * Send the toggle request.
	 *
	 * The button is disabled for the duration so a double click cannot race two
	 * requests whose responses could arrive out of order.
	 *
	 * @param {HTMLElement} button Toggle button.
	 */
	function toggle( button ) {
		if ( button.disabled ) {
			return;
		}

		button.disabled = true;
		button.classList.add( 'is-busy' );

		var body = new window.URLSearchParams();
		body.append( 'action', config.action );
		body.append( 'nonce', config.nonce );
		body.append( 'post_id', button.dataset.postId );

		window
			.fetch( config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body,
			} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( payload ) {
				if ( ! payload || ! payload.success ) {
					throw new Error(
						( payload && payload.data && payload.data.message ) ||
							'Request failed'
					);
				}

				setState( button, payload.data.featured );
			} )
			.catch( function ( error ) {
				window.console.error( error );

				if ( config.i18n && config.i18n.failed ) {
					window.alert( config.i18n.failed ); // eslint-disable-line no-alert
				}
			} )
			.finally( function () {
				button.disabled = false;
				button.classList.remove( 'is-busy' );
			} );
	}

	// Delegated, so rows replaced by Quick Edit keep working without rebinding.
	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '.spotlight-toggle' );

		if ( button ) {
			event.preventDefault();
			toggle( button );
		}
	} );

	/**
	 * Seed the Quick Edit checkbox from the row being edited.
	 *
	 * WordPress builds the inline editor by cloning a hidden template, so the checkbox
	 * always starts unticked regardless of the post's actual state. Without this, an
	 * editor opening Quick Edit on a featured post and saving would silently unfeature
	 * it.
	 */
	function hookQuickEdit() {
		if ( ! window.inlineEditPost || ! window.inlineEditPost.edit ) {
			return;
		}

		var original = window.inlineEditPost.edit;

		window.inlineEditPost.edit = function ( id ) {
			var result = original.apply( this, arguments );
			var postId = 0;

			if ( typeof id === 'object' ) {
				postId = parseInt( this.getId( id ), 10 );
			}

			if ( ! postId ) {
				return result;
			}

			var row = document.getElementById( 'post-' + postId );
			var editRow = document.getElementById( 'edit-' + postId );

			if ( ! row || ! editRow ) {
				return result;
			}

			var toggleButton = row.querySelector( '.spotlight-toggle' );
			var checkbox = editRow.querySelector(
				'input[name="' + config.fieldName + '"]'
			);

			if ( toggleButton && checkbox ) {
				checkbox.checked =
					toggleButton.getAttribute( 'aria-pressed' ) === 'true';
			}

			return result;
		};
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', hookQuickEdit );
	} else {
		hookQuickEdit();
	}
} )();
