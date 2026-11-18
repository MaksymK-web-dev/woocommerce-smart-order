/**
 * "Test connection" buttons on the Smart Order settings screen.
 *
 * The whole form travels with the request: the check has to describe the
 * configuration currently on screen, not the one last saved.
 */
( function () {
	'use strict';

	var config = window.wsoSmartOrderAdmin;

	if ( ! config ) {
		return;
	}

	/**
	 * Writes the outcome next to the button.
	 *
	 * @param {HTMLElement} node    Result span.
	 * @param {string}      message Text to show.
	 * @param {boolean}     ok      Whether the check passed.
	 */
	function report( node, message, ok ) {
		node.textContent = message;
		node.className = 'wso-check-result ' + ( ok ? 'wso-check-result--pass' : 'wso-check-result--fail' );
	}

	/**
	 * Builds the request body from the form the button belongs to.
	 *
	 * @param {HTMLElement} button Clicked button.
	 * @param {string}      target Check to run.
	 * @return {FormData} Request body.
	 */
	function bodyFor( button, target ) {
		var form = button.closest( 'form' );
		var body = form ? new FormData( form ) : new FormData();

		body.append( 'action', config.action );
		body.append( 'nonce', config.nonce );
		body.append( 'target', target );

		return body;
	}

	/**
	 * Posts one check and renders the answer.
	 *
	 * @param {HTMLElement} button Clicked button.
	 */
	function check( button ) {
		var target = button.getAttribute( 'data-target' );
		var output = document.querySelector( '.wso-check-result[data-target="' + target + '"]' );

		if ( ! output ) {
			return;
		}

		button.disabled = true;
		report( output, config.strings.checking, true );

		fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: bodyFor( button, target ),
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( payload ) {
				var data = payload && payload.data ? payload.data : {};
				var message = data.message || config.strings.failed;

				report( output, message, Boolean( payload && payload.success ) );
			} )
			.catch( function () {
				report( output, config.strings.failed, false );
			} )
			.finally( function () {
				button.disabled = false;
			} );
	}

	document.querySelectorAll( '.wso-check' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			check( button );
		} );
	} );
}() );
