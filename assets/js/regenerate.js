/**
 * The "Regenerar" button of the report: asks for a collection, then asks how
 * far it is every few seconds and reloads the report when it ends.
 *
 * Every call goes to admin-ajax, which calls the hub from PHP with the token;
 * the browser never sees the token. Messages come translated from PHP.
 */
( function () {
	'use strict';

	var config = window.pharmaHubRuns;
	var panel = document.getElementById( 'pharma-hub-run' );
	if ( ! config || ! panel ) {
		return;
	}
	var button = document.getElementById( 'pharma-hub-regenerate' );
	var status = panel.querySelector( '.pharma-hub-run-status' );
	var failures = 0;

	function say( text ) {
		status.textContent = text || '';
	}

	function post( action, fields ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( '_ajax_nonce', config.nonce );
		Object.keys( fields || {} ).forEach( function ( key ) {
			body.append( key, fields[ key ] );
		} );
		return fetch( config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.json();
			} );
	}

	function follow( runId ) {
		window.setTimeout( function () {
			post( config.statusAction, { runId: runId } )
				.then( function ( answer ) {
					if ( ! answer.success ) {
						say( answer.data && answer.data.message );
						button.disabled = false;
						return;
					}
					failures = 0;
					say( answer.data.message );
					if ( answer.data.finished ) {
						window.setTimeout( function () {
							window.location.reload();
						}, 1500 );
					} else {
						follow( runId );
					}
				} )
				.catch( function () {
					// A dropped connection or a timeout: try a few more times.
					failures += 1;
					if ( failures >= 3 ) {
						say( config.failed );
						button.disabled = false;
					} else {
						follow( runId );
					}
				} );
		}, config.intervalMs );
	}

	button.addEventListener( 'click', function () {
		button.disabled = true;
		say( '…' );
		post( config.startAction )
			.then( function ( answer ) {
				say( answer.data && answer.data.message );
				if ( answer.success && answer.data.runId ) {
					follow( answer.data.runId );
				} else {
					button.disabled = false;
				}
			} )
			.catch( function () {
				say( config.failed );
				button.disabled = false;
			} );
	} );

	// A collection already running when the page opened is followed at once.
	if ( panel.dataset.runId ) {
		follow( panel.dataset.runId );
	}
}() );
