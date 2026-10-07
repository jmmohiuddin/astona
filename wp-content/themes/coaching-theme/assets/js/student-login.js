/* Student login: password or SMS one-time code. Vanilla JS, DOM text via textContent only. */
( function () {
	'use strict';

	var cfg = window.ASTONA_STUDENT_LOGIN || {};
	var REST = cfg.restBase || '/wp-json/cc/v1/';
	var form = document.getElementById( 'sl-form' );
	if ( ! form ) { return; }

	var phone = document.getElementById( 'sl-phone' );
	var password = document.getElementById( 'sl-password' );
	var code = document.getElementById( 'sl-code' );
	var remember = document.getElementById( 'sl-remember' );
	var passwordField = document.getElementById( 'sl-password-field' );
	var codeField = document.getElementById( 'sl-code-field' );
	var errorEl = document.getElementById( 'sl-error' );
	var statusEl = document.getElementById( 'sl-status' );
	var submit = document.getElementById( 'sl-submit' );
	var toggle = document.getElementById( 'sl-toggle' );
	var resend = document.getElementById( 'sl-resend' );

	var mode = 'password'; // 'password' | 'otp'
	var codeSent = false;
	var busy = false;

	function setError( message ) {
		statusEl.textContent = '';
		errorEl.textContent = message || '';
		phone.setAttribute( 'aria-invalid', message ? 'true' : 'false' );
	}

	function setStatus( message ) {
		errorEl.textContent = '';
		statusEl.textContent = message || '';
	}

	function setBusy( value ) {
		busy = value;
		submit.disabled = value;
		resend.disabled = value;
	}

	function render() {
		var otp = 'otp' === mode;
		passwordField.hidden = otp;
		password.required = ! otp;
		codeField.hidden = ! ( otp && codeSent );
		code.required = otp && codeSent;
		resend.hidden = ! ( otp && codeSent );
		toggle.textContent = otp ? 'Login with password instead' : 'Login with OTP instead';
		if ( ! otp ) {
			submit.textContent = 'Log in';
		} else {
			submit.textContent = codeSent ? 'Verify and log in' : 'Send code';
		}
	}

	function post( path, payload ) {
		return fetch( REST + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( payload )
		} ).then( function ( res ) {
			return res.json().catch( function () { return {}; } ).then( function ( body ) {
				return { ok: res.ok, status: res.status, body: body };
			} );
		} );
	}

	function failMessage( result, fallback ) {
		if ( 429 === result.status ) {
			return 'Too many attempts. Please wait a while and try again.';
		}
		return ( result.body && 'string' === typeof result.body.message && 'rest_invalid_param' !== result.body.code ) ? result.body.message : fallback;
	}

	/* Only follow same-site student paths from ?redirect=; anything else falls back to the server's choice. */
	function destination( serverPath ) {
		var wanted = new URLSearchParams( window.location.search ).get( 'redirect' ) || '';
		var forced = /[?&]change=1/.test( serverPath );
		var traversal = /\.\.|\\|%2e|%5c|%2f|%25/i.test( wanted );
		if ( ! forced && ! traversal && /^\/student\/[A-Za-z0-9\/_\-?=&%.]*$/.test( wanted ) && 0 !== wanted.indexOf( '/student/login' ) ) {
			return wanted;
		}
		return serverPath;
	}

	function onSignedIn( result ) {
		if ( result.ok && result.body && 'string' === typeof result.body.redirect ) {
			setStatus( 'Signed in. Redirecting...' );
			window.location.assign( destination( result.body.redirect ) );
			return;
		}
		setBusy( false );
	}

	function networkError() {
		setBusy( false );
		setError( 'We could not reach the server. Check your connection and try again.' );
	}

	function loginWithPassword() {
		if ( '' === password.value ) {
			setError( 'Enter your mobile number and password.' );
			return;
		}
		setBusy( true );
		post( 'auth/login', { phone: phone.value, password: password.value, remember: remember.checked } ).then( function ( result ) {
			if ( ! result.ok ) {
				setBusy( false );
				setError( failMessage( result, 'Invalid phone or password' ) );
				return;
			}
			onSignedIn( result );
		} ).catch( networkError );
	}

	function requestCode() {
		setBusy( true );
		post( 'auth/otp/request', { phone: phone.value } ).then( function ( result ) {
			setBusy( false );
			if ( ! result.ok ) {
				setError( failMessage( result, 'Enter a valid mobile number.' ) );
				return;
			}
			codeSent = true;
			render();
			setStatus( 'If this number is registered, a code has been sent by SMS.' );
			code.focus();
		} ).catch( networkError );
	}

	function verifyCode() {
		if ( ! /^\d{6}$/.test( code.value ) ) {
			setError( 'Enter the 6-digit code from your SMS.' );
			return;
		}
		setBusy( true );
		post( 'auth/otp/verify', { phone: phone.value, code: code.value, remember: remember.checked } ).then( function ( result ) {
			if ( ! result.ok ) {
				setBusy( false );
				setError( failMessage( result, 'Invalid or expired code' ) );
				return;
			}
			onSignedIn( result );
		} ).catch( networkError );
	}

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();
		if ( busy ) { return; }
		setError( '' );
		if ( '' === phone.value.trim() ) {
			setError( 'Enter your mobile number.' );
			phone.focus();
			return;
		}
		if ( 'password' === mode ) {
			loginWithPassword();
		} else if ( codeSent ) {
			verifyCode();
		} else {
			requestCode();
		}
	} );

	toggle.addEventListener( 'click', function () {
		mode = 'password' === mode ? 'otp' : 'password';
		codeSent = false;
		code.value = '';
		setError( '' );
		render();
		( 'otp' === mode ? phone : password ).focus();
	} );

	resend.addEventListener( 'click', function () {
		if ( busy ) { return; }
		setError( '' );
		requestCode();
	} );

	render();
} )();
