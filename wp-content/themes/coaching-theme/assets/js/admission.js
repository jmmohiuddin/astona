/* Admission form + payment status. Vanilla JS, DOM built with textContent only. */
( function () {
	'use strict';

	var cfg = window.ASTONA_ADMISSION || {};
	var REST = cfg.restBase || '/wp-json/cc/v1/';
	var MAX_PHOTO_BYTES = 2 * 1024 * 1024;
	var POLL_MS = 3000;
	var POLL_MAX_MS = 60000;
	var PHONE_RE = /^(?:\+?88)?(01[3-9]\d{8})$/;

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) { node.className = className; }
		if ( text ) { node.textContent = text; }
		return node;
	}

	function api( path, options ) {
		return fetch( REST + path, Object.assign( { credentials: 'same-origin' }, options ) ).then( function ( res ) {
			return res.json().catch( function () { return {}; } ).then( function ( body ) {
				return { ok: res.ok, status: res.status, body: body };
			} );
		} );
	}

	function goTo( url ) {
		var target;
		try { target = new URL( url, window.location.href ); } catch ( e ) { return false; }
		if ( 'https:' !== target.protocol && 'http:' !== target.protocol ) { return false; }
		window.location.assign( target.href );
		return true;
	}

	/* ---------- Turnstile ---------- */
	var TURNSTILE_FIELD = 'cf-turnstile-response';

	/**
	 * Wraps the explicitly rendered Turnstile widget. Without a widget on the page (no site key) it is inert:
	 * ready() is always true and token() is empty, so the form behaves as before.
	 * A token is single use: call spent() after every request that carried it, whatever the outcome.
	 */
	function initCaptcha() {
		var box = document.getElementById( 'adm-turnstile' );
		var value = '';
		var widgetId = null;
		var listeners = [];

		function changed() { listeners.forEach( function ( fn ) { fn(); } ); }

		function mount() {
			if ( null !== widgetId || ! window.turnstile ) { return; }
			widgetId = window.turnstile.render( box, {
				sitekey: box.getAttribute( 'data-sitekey' ),
				size: 'flexible',
				'response-field-name': TURNSTILE_FIELD,
				callback: function ( token ) { value = token; changed(); },
				'expired-callback': function () { value = ''; changed(); },
				'error-callback': function () { value = ''; changed(); },
				'timeout-callback': function () { value = ''; changed(); }
			} );
		}

		if ( box ) {
			window.astonaTurnstileReady = mount;
			mount(); // api.js may have loaded before this deferred script ran.
		}

		return {
			present: !! box,
			ready: function () { return ! box || '' !== value; },
			token: function () { return value; },
			onChange: function ( fn ) { listeners.push( fn ); },
			spent: function () {
				if ( ! box ) { return; }
				value = '';
				if ( null !== widgetId ) { window.turnstile.reset( widgetId ); }
				changed();
			}
		};
	}

	/* ---------- Phone ownership proof ---------- */
	var CODE_RE = /^\d{6}$/;
	var PROOF_EARLY_MS = 5000;

	/**
	 * Verify-number widget. States: idle, sending, sent, verifying, verified, locked.
	 * hooks.onChange() runs after every state change; hooks.phoneError( message ) shows a message on the phone field.
	 */
	function initPhoneVerify( form, hooks, captcha ) {
		var phoneInput = form.elements.student_phone;
		var proofInput = document.getElementById( 'adm-phone_proof' );
		var sendBtn = document.getElementById( 'adm-verify-send' );
		var codeWrap = document.getElementById( 'adm-verify-code-wrap' );
		var codeInput = document.getElementById( 'adm-verify-code' );
		var confirmBtn = document.getElementById( 'adm-verify-confirm' );
		var resendBtn = document.getElementById( 'adm-verify-resend' );
		var changeBtn = document.getElementById( 'adm-verify-change' );
		var msg = document.getElementById( 'adm-verify-msg' );
		var state = 'idle';
		var phone = '';
		var seq = 0;
		var cooldownTimer = null;
		var cooldownEnd = 0;
		var expiryTimer = null;

		function normalized() {
			var m = PHONE_RE.exec( phoneInput.value.replace( /[\s-]/g, '' ) );
			return m ? m[ 1 ] : '';
		}

		function say( text, tone ) {
			msg.textContent = text;
			msg.className = 'adm-verify__msg' + ( tone ? ' is-' + tone : '' );
		}

		function render() {
			var sending = 'sending' === state;
			var showCode = 'sent' === state || 'verifying' === state || 'locked' === state;
			sendBtn.hidden = 'idle' !== state && ! sending;
			sendBtn.disabled = sending || ! captcha.ready();
			sendBtn.textContent = sending ? 'Sending…' : 'Verify number';
			codeWrap.hidden = ! showCode;
			codeInput.disabled = 'locked' === state || 'verifying' === state;
			confirmBtn.disabled = 'locked' === state || 'verifying' === state;
			confirmBtn.textContent = 'verifying' === state ? 'Checking…' : 'Confirm code';
			changeBtn.hidden = 'idle' === state || sending;
			phoneInput.readOnly = 'verified' === state;
			phoneInput.setAttribute( 'aria-readonly', 'verified' === state ? 'true' : 'false' );
			if ( 'verified' !== state ) { proofInput.value = ''; }
			if ( 'sent' !== state ) { resendBtn.disabled = true; }
			hooks.onChange();
		}

		function stopTimers() {
			window.clearInterval( cooldownTimer );
			window.clearTimeout( expiryTimer );
			cooldownTimer = null;
			expiryTimer = null;
		}

		function startCooldown( seconds ) {
			window.clearInterval( cooldownTimer );
			var end = Date.now() + seconds * 1000;
			cooldownEnd = end;
			function tick() {
				var left = Math.ceil( ( end - Date.now() ) / 1000 );
				if ( left <= 0 || 'sent' !== state ) {
					window.clearInterval( cooldownTimer );
					resendBtn.disabled = 'sent' !== state || ! captcha.ready();
					resendBtn.textContent = 'Resend code';
					return;
				}
				resendBtn.disabled = true;
				resendBtn.textContent = 'Resend code in ' + left + 's';
			}
			tick();
			cooldownTimer = window.setInterval( tick, 1000 );
		}

		/** Back to idle: forgets the code, the proof and any in-flight response. */
		function reset( text, tone ) {
			seq++;
			stopTimers();
			state = 'idle';
			phone = '';
			codeInput.value = '';
			resendBtn.textContent = 'Resend code';
			render();
			say( text || 'We will text a 6-digit code to this number to confirm it is yours.', tone );
		}

		function lock( text ) {
			stopTimers();
			state = 'locked';
			render();
			say( text || 'Too many wrong codes. Please wait 15 minutes, then change the number or request a new code.', 'error' );
		}

		function requestCode( fromResend ) {
			var digits = normalized();
			if ( ! digits ) {
				hooks.phoneError( phoneInput.value.trim() ? 'Enter a valid Bangladeshi mobile number.' : 'This field is required.' );
				phoneInput.focus();
				return;
			}
			hooks.phoneError( '' );
			var mine = ++seq;
			phone = digits;
			state = 'sending';
			render();
			say( 'Sending the code…' );
			if ( fromResend ) { codeInput.value = ''; }
			var payload = { phone: digits };
			if ( captcha.present ) { payload[ TURNSTILE_FIELD ] = captcha.token(); }
			var sent = api( 'applications/verify-phone/request', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( payload )
			} );
			captcha.spent();
			sent.then( function ( r ) {
				if ( mine !== seq ) { return; }
				if ( r.ok ) {
					state = 'sent';
					render();
					say( 'Code sent to ' + ( r.body.phone || 'your number' ) + '. It expires in 5 minutes.' );
					startCooldown( r.body.resend_after || 60 );
					codeWrap.hidden = false;
					codeInput.focus();
				} else if ( 429 === r.status && ( 'locked' === r.body.code || 'locked_daily' === r.body.code ) ) {
					lock( r.body.message );
				} else if ( 429 === r.status && 'resend_too_soon' === r.body.code ) {
					state = fromResend ? 'sent' : 'idle';
					render();
					say( r.body.message + ( fromResend ? '' : ' The code we already sent still works.' ), 'error' );
					if ( 'sent' === state ) { startCooldown( r.body.retry_after || 60 ); }
				} else if ( 400 === r.status && 'bot_check_failed' === r.body.code ) {
					state = fromResend ? 'sent' : 'idle';
					render();
					say( r.body.message, 'error' );
					if ( 'sent' === state ) { startCooldown( 5 ); }
				} else if ( 429 === r.status ) {
					state = fromResend ? 'sent' : 'idle';
					render();
					say( 'Too many code requests. Please wait a while and try again.', 'error' );
					if ( 'sent' === state ) { startCooldown( 60 ); }
				} else if ( 422 === r.status ) {
					state = 'idle';
					render();
					say( 'Check the mobile number and try again.', 'error' );
					hooks.phoneError( r.body.message || 'Enter a valid Bangladeshi mobile number.' );
				} else {
					state = fromResend ? 'sent' : 'idle';
					render();
					say( r.body && r.body.message ? r.body.message : 'Could not send the code. Please try again.', 'error' );
					if ( 'sent' === state ) { startCooldown( 5 ); }
				}
			} ).catch( function () {
				if ( mine !== seq ) { return; }
				state = fromResend ? 'sent' : 'idle';
				render();
				say( 'Network problem. Please check your connection and try again.', 'error' );
				if ( 'sent' === state ) { startCooldown( 5 ); }
			} );
		}

		function confirmCode() {
			var code = codeInput.value.trim();
			if ( ! CODE_RE.test( code ) ) {
				say( 'Enter the 6-digit code from the SMS.', 'error' );
				codeInput.focus();
				return;
			}
			var mine = ++seq;
			state = 'verifying';
			render();
			say( 'Checking the code…' );
			api( 'applications/verify-phone/confirm', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( { phone: phone, code: code } )
			} ).then( function ( r ) {
				if ( mine !== seq ) { return; }
				if ( r.ok && r.body.phone_proof ) {
					window.clearInterval( cooldownTimer );
					state = 'verified';
					render();
					proofInput.value = r.body.phone_proof;
					say( '✓ Number verified.', 'ok' );
					var lifetime = ( r.body.expires_in || 900 ) * 1000 - PROOF_EARLY_MS;
					expiryTimer = window.setTimeout( function () { reset( 'The verification expired. Please verify your number again.', 'error' ); }, lifetime );
					hooks.onChange();
					return;
				}
				if ( 429 === r.status && ( 'locked' === r.body.code || 'locked_daily' === r.body.code ) ) {
					lock( r.body.message );
					return;
				}
				state = 'sent';
				render();
				startCooldown( 1 );
				if ( 429 === r.status ) {
					say( 'Too many attempts. Please wait a while and try again.', 'error' );
				} else if ( 422 === r.status ) {
					say( 'That code is not correct or has expired. Check the SMS and try again, or resend the code.', 'error' );
				} else {
					say( 'Something went wrong. Please try again.', 'error' );
				}
				codeInput.focus();
				codeInput.select();
			} ).catch( function () {
				if ( mine !== seq ) { return; }
				state = 'sent';
				render();
				startCooldown( 1 );
				say( 'Network problem. Please check your connection and try again.', 'error' );
			} );
		}

		captcha.onChange( function () {
			render();
			if ( 'sent' === state && Date.now() >= cooldownEnd ) { resendBtn.disabled = ! captcha.ready(); }
		} );
		sendBtn.addEventListener( 'click', function () { requestCode( false ); } );
		resendBtn.addEventListener( 'click', function () { requestCode( true ); } );
		confirmBtn.addEventListener( 'click', confirmCode );
		changeBtn.addEventListener( 'click', function () {
			reset();
			phoneInput.focus();
			phoneInput.select();
		} );
		codeInput.addEventListener( 'input', function () { codeInput.value = codeInput.value.replace( /\D/g, '' ).slice( 0, 6 ); } );
		codeInput.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key ) {
				event.preventDefault();
				if ( ! confirmBtn.disabled ) { confirmCode(); }
			}
		} );
		// A code or proof belongs to one number: any edit that changes it starts over.
		phoneInput.addEventListener( 'input', function () {
			if ( 'idle' !== state && normalized() !== phone ) { reset( 'The number changed. Verify it again.' ); }
		} );

		render();
		return {
			isVerified: function () { return 'verified' === state && '' !== proofInput.value; },
			invalidate: function ( text ) { reset( text, 'error' ); },
			focus: function () { ( 'idle' === state ? sendBtn : changeBtn ).focus(); }
		};
	}

	/* ---------- Form ---------- */
	function initForm( form ) {
		var submit = document.getElementById( 'adm-submit' );
		var summary = document.getElementById( 'adm-errors' );
		var progress = document.getElementById( 'adm-submitting' );
		var photo = document.getElementById( 'adm-photo' );
		var preview = document.getElementById( 'adm-photo-preview' );
		var submitHint = document.getElementById( 'adm-submit-hint' );
		var token = null;
		var busy = false;
		var verify = null;
		var captcha = initCaptcha();
		verify = initPhoneVerify( form, {
			onChange: function () { syncSubmit(); },
			phoneError: function ( message ) { setError( 'student_phone', message ); }
		}, captcha );
		captcha.onChange( function () { syncSubmit(); } );

		function fieldWrap( name ) { return form.querySelector( '[data-field="' + name + '"]' ); }

		function setError( name, message ) {
			var wrap = fieldWrap( name );
			if ( ! wrap ) { return; }
			var slot = wrap.querySelector( '.adm-error' );
			var input = wrap.querySelector( 'input, select' );
			slot.textContent = message || '';
			slot.hidden = ! message;
			wrap.classList.toggle( 'is-invalid', !! message );
			if ( input ) {
				if ( message ) { input.setAttribute( 'aria-invalid', 'true' ); } else { input.removeAttribute( 'aria-invalid' ); }
			}
		}

		function validateField( name ) {
			var input = form.elements[ name ];
			if ( ! input ) { return ''; }
			var value = 'file' === input.type ? '' : String( input.value ).trim();
			var message = '';
			if ( 'photo' === name ) {
				var file = input.files && input.files[ 0 ];
				if ( ! file ) { message = 'Upload a photo.'; }
				else if ( 'image/jpeg' !== file.type && 'image/png' !== file.type ) { message = 'Photo must be a JPEG or PNG image.'; }
				else if ( file.size > MAX_PHOTO_BYTES ) { message = 'Photo must be 2 MB or smaller.'; }
			} else if ( 'consent' === name ) {
				if ( ! input.checked ) { message = 'You must agree to continue.'; }
			} else if ( input.required && '' === value ) {
				message = 'This field is required.';
			} else if ( '' !== value ) {
				message = formatCheck( name, value );
			}
			setError( name, message );
			return message;
		}

		function formatCheck( name, value ) {
			if ( ( 'student_phone' === name || 'guardian_phone' === name ) && ! PHONE_RE.test( value.replace( /[\s-]/g, '' ) ) ) {
				return 'Enter a valid Bangladeshi mobile number.';
			}
			if ( 'email' === name && ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( value ) ) { return 'Enter a valid email address.'; }
			if ( 'passing_year' === name && ! /^\d{4}$/.test( value ) ) { return 'Enter a four-digit year.'; }
			if ( 'dob' === name && new Date( value ) > new Date() ) { return 'Date of birth cannot be in the future.'; }
			if ( 'id_doc_number' === name && ! /^[A-Za-z0-9-]{5,30}$/.test( value ) ) { return 'Use 5 to 30 letters, digits or dashes.'; }
			return '';
		}

		function names() {
			return Array.prototype.map.call( form.querySelectorAll( '[data-field]' ), function ( w ) { return w.getAttribute( 'data-field' ); } );
		}

		function showSummary( message ) {
			summary.textContent = message || '';
			if ( message ) { summary.focus(); }
		}

		function syncSubmit() {
			if ( ! verify ) { return; } // The widget renders once before it is assigned.
			var verified = verify.isVerified();
			submit.disabled = busy || ! verified || ! captcha.ready();
			submitHint.hidden = verified && captcha.ready();
			submitHint.textContent = verified ? 'Waiting for the security check to finish.' : 'Verify your student mobile number to enable this button.';
		}

		function setBusy( on, text ) {
			busy = on;
			syncSubmit();
			progress.hidden = ! on;
			progress.textContent = on ? text : '';
		}

		function loadToken() {
			return api( 'forms/admission-token' ).then( function ( r ) {
				if ( ! r.ok || ! r.body.idempotency_key ) { throw new Error( 'token' ); }
				token = r.body.idempotency_key;
			} );
		}

		function fail( message ) {
			setBusy( false );
			showSummary( message );
		}

		function applyServerErrors( details ) {
			var first = null;
			Object.keys( details || {} ).forEach( function ( name ) {
				setError( name, String( details[ name ] ) );
				if ( ! first && fieldWrap( name ) ) { first = fieldWrap( name ).querySelector( 'input, select' ); }
			} );
			if ( first ) { first.focus(); }
		}

		names().forEach( function ( name ) {
			var input = form.elements[ name ];
			if ( ! input ) { return; }
			input.addEventListener( 'blur', function () {
				if ( ( 'student_phone' === name || 'guardian_phone' === name ) ) {
					var m = PHONE_RE.exec( input.value.replace( /[\s-]/g, '' ) );
					if ( m ) { input.value = m[ 1 ].slice( 0, 5 ) + '-' + m[ 1 ].slice( 5 ); }
				}
				validateField( name );
			} );
			input.addEventListener( 'change', function () { validateField( name ); } );
		} );

		photo.addEventListener( 'change', function () {
			var file = photo.files && photo.files[ 0 ];
			if ( preview.src ) { URL.revokeObjectURL( preview.src ); }
			if ( file && ! validateField( 'photo' ) ) {
				preview.src = URL.createObjectURL( file );
				preview.hidden = false;
			} else {
				preview.removeAttribute( 'src' );
				preview.hidden = true;
			}
		} );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			if ( busy ) { return; }
			showSummary( '' );
			if ( ! verify.isVerified() ) {
				showSummary( 'Verify your student mobile number first.' );
				verify.focus();
				return;
			}

			var firstInvalid = null;
			names().forEach( function ( name ) {
				if ( validateField( name ) && ! firstInvalid ) { firstInvalid = fieldWrap( name ).querySelector( 'input, select' ); }
			} );
			if ( firstInvalid ) {
				showSummary( 'Please correct the highlighted fields.' );
				firstInvalid.focus();
				return;
			}

			setBusy( true, 'Submitting your application…' );
			( token ? Promise.resolve() : loadToken() ).then( function () {
				var data = new FormData( form );
				data.set( 'batch_id', form.getAttribute( 'data-batch-id' ) || '' );
				if ( captcha.present ) { data.set( TURNSTILE_FIELD, captcha.token() ); }
				var posted = api( 'applications', { method: 'POST', headers: { 'Idempotency-Key': token }, body: data } );
				captcha.spent();
				return posted;
			} ).then( function ( r ) {
				if ( r.ok && r.body.redirect_url ) {
					progress.textContent = 'Redirecting to payment…';
					if ( window.astonaTrack ) { window.astonaTrack( 'complete_admission' ); window.astonaTrack( 'payment_redirect' ); }
					if ( ! goTo( r.body.redirect_url ) ) { fail( 'Could not start payment. Please try again.' ); }
					return;
				}
				if ( 422 === r.status ) {
					// Summary first (no focus steal), then focus the first bad field so its blur does not clear the server error.
					setBusy( false );
					summary.textContent = r.body.message || 'Please correct the highlighted fields.';
					var details = r.body.details || {};
					if ( details.phone_proof ) {
						summary.textContent = String( details.phone_proof );
						verify.invalidate( String( details.phone_proof ) + ' Verify the number again.' );
						verify.focus();
						return;
					}
					applyServerErrors( details );
				} else if ( 400 === r.status && 'bot_check_failed' === r.body.code ) {
					fail( 'The security check failed. Wait for it to finish again, then submit once more.' );
				} else if ( 409 === r.status ) {
					fail( r.body.message || 'An application with this phone number already exists.' );
				} else if ( 429 === r.status ) {
					fail( 'Too many attempts. Please wait a few minutes and try again.' );
				} else {
					fail( 'Something went wrong. Please try again.' );
				}
			} ).catch( function () {
				fail( 'Network problem. Please check your connection and try again.' );
			} );
		} );

		syncSubmit();
		loadToken().catch( function () { /* retried on submit */ } );
	}

	/* ---------- Status / confirmation ---------- */
	function initStatus( box ) {
		var ref = box.getAttribute( 'data-ref' );
		var title = document.getElementById( 'adm-status-title' );
		var text = document.getElementById( 'adm-status-text' );
		var actions = document.getElementById( 'adm-status-actions' );
		var started = Date.now();

		function render( state, heading, message, buttons ) {
			box.classList.remove( 'adm-status--success', 'adm-status--failed' );
			if ( 'success' === state ) { box.classList.add( 'adm-status--success' ); }
			if ( 'failed' === state ) { box.classList.add( 'adm-status--failed' ); }
			title.textContent = heading;
			text.textContent = message;
			actions.textContent = '';
			( buttons || [] ).forEach( function ( b ) { actions.appendChild( b ); } );
		}

		function button( label, onClick, ghost ) {
			var b = el( 'button', 'btn ' + ( ghost ? 'btn--ghost' : 'btn--primary' ), label );
			b.type = 'button';
			b.addEventListener( 'click', function () { onClick( b ); } );
			return b;
		}

		function link( label, href ) {
			var a = el( 'a', 'btn btn--primary', label );
			a.href = href;
			return a;
		}

		function retryPayment( b ) {
			b.disabled = true;
			text.textContent = 'Starting a new payment…';
			api( 'applications/' + ref + '/payment', { method: 'POST' } ).then( function ( r ) {
				if ( r.ok && r.body.redirect_url && goTo( r.body.redirect_url ) ) { return; }
				if ( 409 === r.status && 'payment_needs_review' === r.body.code ) { renderNeedsReview(); return; }
				if ( 409 === r.status && ( 'payment_in_progress' === r.body.code || 'already_paid' === r.body.code ) ) {
					started = Date.now();
					render( 'pending', 'Checking your payment…', r.body.message || 'Please wait while we confirm your payment.' );
					poll();
					return;
				}
				if ( 409 === r.status && 'batch_full' === r.body.code ) { renderNeedsReview(); return; }
				b.disabled = false;
				text.textContent = r.body && r.body.message ? r.body.message : 'Could not start payment. Please try again.';
			} ).catch( function () {
				b.disabled = false;
				text.textContent = 'Network problem. Please try again.';
			} );
		}

		function renderNeedsReview() {
			render( 'pending', 'Payment needs review', 'Payment needs review. Please contact us and quote your reference: ' + ref + '.' );
		}

		function poll() {
			api( 'applications/' + ref + '/status' ).then( function ( r ) {
				if ( 404 === r.status ) {
					render( 'failed', 'Application not found', 'Please check the link or call us for help.' );
					return;
				}
				if ( ! r.ok ) { return schedule(); }
				var app = r.body.status;
				var pay = r.body.payment_status;
				if ( 'approved' === app || 'completed' === pay ) {
					render( 'success', 'Payment received', 'Thank you. Your seat is reserved. An SMS with your login details is on its way to your mobile number.', [ link( 'Go to student login', cfg.loginUrl || '/student/login/' ) ] );
				} else if ( 'failed' === pay || 'cancelled' === pay ) {
					render( 'failed', 'Payment not completed', 'Your payment did not go through.', [ button( 'Retry payment', retryPayment ) ] );
				} else if ( 'needs_review' === pay ) {
					renderNeedsReview();
				} else {
					schedule();
				}
			} ).catch( schedule );
		}

		function schedule() {
			if ( Date.now() - started >= POLL_MAX_MS ) {
				render( 'pending', 'Still confirming your payment', 'This is taking longer than usual. If you completed payment, we will contact you with login details. You can check again below.', [ button( 'Check again', function () {
					started = Date.now();
					render( 'pending', 'Checking your payment…', 'Please wait while we confirm your payment.' );
					poll();
				}, true ) ] );
				return;
			}
			window.setTimeout( poll, POLL_MS );
		}

		render( 'pending', 'Payment pending', 'Please wait while we confirm your payment. This page updates automatically.' );
		poll();
	}

	var form = document.getElementById( 'adm-form' );
	var status = document.getElementById( 'adm-status' );
	if ( form ) { initForm( form ); }
	if ( status ) { initStatus( status ); }
}() );
