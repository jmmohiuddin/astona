/* Contact form. Vanilla JS; all dynamic text is set with textContent. */
( function () {
	'use strict';

	var cfg = window.ASTONA_CONTACT || {};
	var ENDPOINT = ( cfg.restBase || '/wp-json/cc/v1/' ) + 'contact';
	var PHONE_RE = /^(?:\+?88)?(01[3-9]\d{8})$/;
	var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
	var NAME_MIN = 2, NAME_MAX = 190, MSG_MIN = 10, MSG_MAX = 2000;

	var form = document.getElementById( 'ct-form' );
	if ( ! form ) { return; }
	var summary = document.getElementById( 'ct-errors' );
	var submit = document.getElementById( 'ct-submit' );
	var sending = document.getElementById( 'ct-sending' );
	var success = document.getElementById( 'ct-success' );
	var busy = false;

	function wrap( name ) { return form.querySelector( '[data-field="' + name + '"]' ); }

	function setError( name, message ) {
		var box = wrap( name );
		if ( ! box ) { return; }
		var slot = box.querySelector( '.ct-error' );
		var input = box.querySelector( 'input, select, textarea' );
		slot.textContent = message || '';
		slot.hidden = ! message;
		if ( input ) {
			if ( message ) { input.setAttribute( 'aria-invalid', 'true' ); } else { input.removeAttribute( 'aria-invalid' ); }
		}
	}

	function check( name ) {
		var input = form.elements[ name ];
		if ( ! input ) { return ''; }
		var value = String( input.value ).replace( /\r\n/g, '\n' ).trim();
		var message = '';
		if ( 'name' === name && ( value.length < NAME_MIN || value.length > NAME_MAX ) ) { message = 'Enter your name (2 to 190 characters).'; }
		if ( 'phone' === name && ! PHONE_RE.test( value.replace( /[\s\-().]/g, '' ) ) ) { message = 'Enter a valid Bangladeshi mobile number.'; }
		if ( 'email' === name && '' !== value && ! EMAIL_RE.test( value ) ) { message = 'Enter a valid email address.'; }
		if ( 'topic' === name && '' === value ) { message = 'Choose a topic.'; }
		if ( 'message' === name && ( value.length < MSG_MIN || value.length > MSG_MAX ) ) { message = 'Write a message of 10 to 2000 characters.'; }
		setError( name, message );
		return message;
	}

	function fieldNames() {
		return Array.prototype.map.call( form.querySelectorAll( '[data-field]' ), function ( node ) { return node.getAttribute( 'data-field' ); } );
	}

	function setBusy( on, text ) {
		busy = on;
		submit.disabled = on;
		sending.hidden = ! on;
		sending.textContent = on ? text : '';
	}

	// A Turnstile token is single use, so a rejected attempt needs a fresh challenge.
	function resetCaptcha() {
		if ( window.turnstile && form.querySelector( '.cf-turnstile' ) ) { window.turnstile.reset(); }
	}

	function fail( message ) {
		resetCaptcha();
		setBusy( false, '' );
		summary.textContent = message;
		summary.focus();
	}

	function showServerErrors( details ) {
		var first = null;
		Object.keys( details || {} ).forEach( function ( name ) {
			setError( name, String( details[ name ] ) );
			if ( ! first && wrap( name ) ) { first = wrap( name ).querySelector( 'input, select, textarea' ); }
		} );
		if ( first ) { first.focus(); }
	}

	fieldNames().forEach( function ( name ) {
		var input = form.elements[ name ];
		if ( input ) { input.addEventListener( 'blur', function () { check( name ); } ); }
	} );

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();
		if ( busy ) { return; }
		summary.textContent = '';

		var firstInvalid = null;
		fieldNames().forEach( function ( name ) {
			if ( check( name ) && ! firstInvalid ) { firstInvalid = wrap( name ).querySelector( 'input, select, textarea' ); }
		} );
		if ( firstInvalid ) {
			summary.textContent = 'Please correct the highlighted fields.';
			firstInvalid.focus();
			return;
		}

		var payload = {};
		Array.prototype.forEach.call( form.elements, function ( input ) {
			if ( input.name ) { payload[ input.name ] = String( input.value ).trim(); }
		} );

		setBusy( true, 'Sending your message…' );
		fetch( ENDPOINT, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( payload )
		} ).then( function ( res ) {
			return res.json().catch( function () { return {}; } ).then( function ( body ) {
				if ( res.ok && body.ok ) {
					form.hidden = true;
					success.hidden = false;
					success.focus();
					return;
				}
				if ( 422 === res.status ) {
					setBusy( false, '' );
					summary.textContent = body.message || 'Please correct the highlighted fields.';
					showServerErrors( body.details );
				} else if ( 429 === res.status ) {
					fail( 'Too many messages from this connection. Please try again later or call us.' );
				} else {
					fail( 'Something went wrong. Please try again or call us.' );
				}
			} );
		} ).catch( function () {
			fail( 'Network problem. Please check your connection and try again.' );
		} );
	} );
}() );
