( function () {
	'use strict';

	var cfg = window.ASTONA_PORTAL || {};
	var statusEl = document.getElementById( 'portal-status' );

	function say( message, isError ) {
		if ( ! statusEl ) { return; }
		statusEl.textContent = message;
		statusEl.classList.toggle( 'is-error', !! isError );
	}

	function api( method, path, body ) {
		return fetch( cfg.restBase + path, {
			method: method,
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: body ? JSON.stringify( body ) : undefined
		} ).then( function ( res ) {
			return res.json().catch( function () { return {}; } ).then( function ( data ) {
				return { ok: res.ok, status: res.status, body: data };
			} );
		} );
	}

	function clearErrors( form ) {
		form.querySelectorAll( '.adm-field' ).forEach( function ( field ) {
			field.classList.remove( 'is-invalid' );
			var err = field.querySelector( '.adm-error' );
			err.textContent = '';
			err.hidden = true;
		} );
	}

	function showFieldErrors( form, details ) {
		var first = null;
		Object.keys( details || {} ).forEach( function ( name ) {
			var field = form.querySelector( '[data-field="' + name + '"]' );
			if ( ! field ) { return; }
			var err = field.querySelector( '.adm-error' );
			err.textContent = details[ name ];
			err.hidden = false;
			field.classList.add( 'is-invalid' );
			first = first || field.querySelector( 'input' );
		} );
		if ( first ) { first.focus(); }
	}

	function values( form ) {
		var out = {};
		new FormData( form ).forEach( function ( value, key ) { out[ key ] = String( value ); } );
		return out;
	}

	function submitForm( form, run ) {
		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			var button = Array.prototype.filter.call( form.querySelectorAll( 'button[type="submit"]' ), function ( b ) { return ! b.closest( '[hidden]' ); } )[ 0 ] || form.querySelector( 'button[type="submit"]' );
			clearErrors( form );
			say( 'Saving…', false );
			button.disabled = true;
			run( values( form ) ).then( function ( res ) {
				button.disabled = false;
				if ( res.ok ) { return say( 'Saved.', false ), res; }
				say( res.body.message || 'Something went wrong. Please try again.', true );
				showFieldErrors( form, res.body.details );
				return res;
			} ).catch( function () {
				button.disabled = false;
				say( 'Network problem. Please try again.', true );
			} );
		} );
	}

	var profileForm = document.getElementById( 'portal-profile-form' );
	if ( profileForm ) {
		submitForm( profileForm, function ( data ) {
			return api( 'PATCH', 'me/profile', data );
		} );
	}

	var passwordForm = document.getElementById( 'portal-password-form' );
	if ( passwordForm ) {
		submitForm( passwordForm, function ( data ) {
			return api( 'POST', 'me/password', data ).then( function ( res ) {
				if ( res.ok ) {
					passwordForm.reset();
					if ( res.body && res.body.nonce ) { cfg.nonce = res.body.nonce; }
					say( 'Password updated.', false );
					var banner = document.getElementById( 'portal-force-change' );
					if ( banner ) { banner.hidden = true; }
					if ( cfg.dashboardUrl && banner ) {
						window.setTimeout( function () { window.location.assign( cfg.dashboardUrl ); }, 1200 );
					}
				} else if ( res.body && res.body.code && ! res.body.details ) {
					var onNewPassword = 'weak_password' === res.body.code || 'same_password' === res.body.code;
					res.body.details = {};
					res.body.details[ onNewPassword ? 'new_password' : 'current_password' ] = res.body.message;
				}
				return res;
			} );
		} );
	}

	var phoneForm = document.getElementById( 'portal-phone-form' );
	if ( phoneForm ) {
		var stepRequest = phoneForm.querySelector( '[data-step="request"]' );
		var stepConfirm = phoneForm.querySelector( '[data-step="confirm"]' );
		var pendingPhone = '';
		var showStep = function ( confirmStep ) {
			stepRequest.hidden = confirmStep;
			stepConfirm.hidden = ! confirmStep;
			var first = ( confirmStep ? stepConfirm : stepRequest ).querySelector( 'input' );
			if ( first ) { first.focus(); }
		};
		var back = phoneForm.querySelector( '[data-phone-back]' );
		if ( back ) { back.addEventListener( 'click', function () { showStep( false ); say( '', false ); } ); }
		submitForm( phoneForm, function ( data ) {
			if ( stepConfirm.hidden ) {
				pendingPhone = data.phone;
				return api( 'POST', 'me/phone/request', { phone: data.phone, current_password: data.current_password || '' } ).then( function ( res ) {
					if ( res.ok ) { showStep( true ); say( res.body.message, false ); }
					else if ( res.body && res.body.code && ! res.body.details ) {
						res.body.details = {};
						res.body.details[ 'wrong_password' === res.body.code ? 'current_password' : 'phone' ] = res.body.message;
					}
					return res;
				} );
			}
			return api( 'POST', 'me/phone/confirm', { phone: pendingPhone, code: data.code } ).then( function ( res ) {
				if ( res.ok ) {
					if ( res.body.nonce ) { cfg.nonce = res.body.nonce; }
					say( 'Your mobile number was changed. Reloading…', false );
					window.setTimeout( function () { window.location.reload(); }, 1000 );
				} else if ( res.body && res.body.code && ! res.body.details ) {
					res.body.details = { code: res.body.message };
				}
				return res;
			} );
		} );
	}

	var logout = document.getElementById( 'portal-logout' );
	if ( logout ) {
		logout.addEventListener( 'click', function () {
			logout.disabled = true;
			api( 'POST', 'auth/logout' ).then( function ( res ) {
				if ( res.ok ) {
					window.location.assign( cfg.loginUrl );
					return;
				}
				logout.disabled = false;
				say( 'Could not log out. Please refresh the page and try again.', true );
			} ).catch( function () {
				logout.disabled = false;
				say( 'Could not log out. Please try again.', true );
			} );
		} );
	}

	var print = document.getElementById( 'portal-print' );
	if ( print ) {
		print.addEventListener( 'click', function () { window.print(); } );
	}
}() );
