( function () {
	'use strict';

	var portal = window.ASTONA_PORTAL || {};
	var cfg = window.ASTONA_LIVE || {};
	var LEAD_MS = ( cfg.leadSeconds || 900 ) * 1000;
	var TICK_MS = 1000;
	var MS_PER_MIN = 60000;
	var HOURS_THRESHOLD_MIN = 120;
	var NONCE_ERROR = 'rest_cookie_invalid_nonce';
	// Server time minus browser time, so a wrong device clock cannot open or close a class early.
	var offsetMs = ( cfg.serverNow || 0 ) * 1000 - Date.now();
	var statusEl = document.getElementById( 'portal-status' );

	function now() { return Date.now() + offsetMs; }

	function say( message, isError ) {
		if ( ! statusEl ) { return; }
		statusEl.textContent = message;
		statusEl.classList.toggle( 'is-error', !! isError );
	}

	function countdownText( ms ) {
		var minutes = Math.max( 1, Math.ceil( ms / MS_PER_MIN ) );
		if ( minutes >= HOURS_THRESHOLD_MIN ) { return 'in ' + Math.round( minutes / 60 ) + ' h'; }
		return 'in ' + minutes + ' min';
	}

	function post( liveId ) {
		return fetch( portal.restBase + 'live-classes/' + liveId + '/join', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': portal.nonce }
		} ).then( function ( res ) {
			return res.json().catch( function () { return {}; } ).then( function ( body ) {
				return { ok: res.ok, status: res.status, body: body };
			} );
		} );
	}

	function refreshNonce() {
		return fetch( ( cfg.ajaxUrl || '/wp-admin/admin-ajax.php' ) + '?action=rest-nonce', { credentials: 'same-origin' } )
			.then( function ( res ) { return res.ok ? res.text() : ''; } )
			.then( function ( text ) {
				if ( text && /^[A-Za-z0-9]+$/.test( text ) ) { portal.nonce = text; return true; }
				return false;
			} );
	}

	function requestJoin( liveId ) {
		return post( liveId ).then( function ( res ) {
			if ( 403 === res.status && res.body && NONCE_ERROR === res.body.code ) {
				return refreshNonce().then( function ( ok ) { return ok ? post( liveId ) : res; } );
			}
			return res;
		} );
	}

	function LiveButton( el ) {
		this.el = el;
		this.id = el.getAttribute( 'data-live-id' );
		this.startsMs = Number( el.getAttribute( 'data-starts' ) ) * 1000;
		this.endsMs = Number( el.getAttribute( 'data-ends' ) ) * 1000;
		this.startText = el.getAttribute( 'data-start-text' ) || '';
		this.title = el.getAttribute( 'data-title' ) || 'Live class';
		this.nextText = el.getAttribute( 'data-next' ) || '';
		this.override = '';
		this.rendered = '';
		this.fallback = null;
		var self = this;
		el.addEventListener( 'click', function () { self.join(); } );
		this.render();
	}

	LiveButton.prototype.state = function () {
		var t = now();
		if ( t >= this.endsMs ) { return 'ended'; }
		if ( this.override ) { return this.override; }
		return t >= this.startsMs - LEAD_MS ? 'active' : 'inactive';
	};

	LiveButton.prototype.render = function () {
		var state = this.state();
		var el = this.el;
		var text;
		var label;
		if ( 'inactive' === state ) {
			text = 'Starts at ' + this.startText + ' (' + countdownText( this.startsMs - now() ) + ')';
			label = this.title + ': not open yet. ' + text;
		} else if ( 'active' === state ) {
			text = 'Join class';
			label = this.title + ': live now. Join class';
		} else if ( 'launching' === state ) {
			text = 'Opening class…';
			label = this.title + ': opening the class';
		} else if ( 'denied' === state ) {
			text = 'Your access has ended';
			label = this.title + ': your access has ended';
		} else {
			text = 'Class ended' + ( this.nextText ? '. ' + this.nextText : '' );
			label = this.title + ': class ended. ' + this.nextText;
		}
		el.className = 'live-join live-join--' + state;
		el.disabled = 'active' !== state;
		el.setAttribute( 'aria-busy', 'launching' === state ? 'true' : 'false' );
		if ( text !== el.textContent ) { el.textContent = text; }
		// Announce state changes only, not every countdown tick.
		if ( state !== this.rendered ) { el.setAttribute( 'aria-label', label ); this.rendered = state; }
	};

	LiveButton.prototype.fail = function ( message, override ) {
		this.override = override;
		this.render();
		say( message, true );
	};

	LiveButton.prototype.showFallback = function ( url ) {
		var link = document.createElement( 'a' );
		link.className = 'btn btn--primary live-join__fallback';
		link.textContent = 'Open class';
		link.href = url;
		link.target = '_blank';
		link.rel = 'noopener noreferrer';
		this.removeFallback();
		this.fallback = link;
		this.el.insertAdjacentElement( 'afterend', link );
	};

	LiveButton.prototype.removeFallback = function () {
		if ( this.fallback && this.fallback.parentNode ) { this.fallback.parentNode.removeChild( this.fallback ); }
		this.fallback = null;
	};

	LiveButton.prototype.join = function () {
		var self = this;
		if ( 'active' !== this.state() ) { return; }
		this.removeFallback();
		// Opened inside the click so popup blockers allow it; navigated once the URL is known.
		var win = window.open( '', '_blank' );
		if ( win ) { win.opener = null; }
		this.override = 'launching';
		this.render();
		say( 'Opening class…', false );
		requestJoin( this.id ).then( function ( res ) {
			var url = res.ok && res.body && 'string' === typeof res.body.url && 0 === res.body.url.indexOf( 'https://' ) ? res.body.url : '';
			if ( url ) {
				self.override = '';
				self.render();
				say( '', false );
				if ( win ) { win.location.replace( url ); } else { self.showFallback( url ); }
				return;
			}
			if ( win ) { win.close(); }
			if ( 403 === res.status ) { return self.fail( 'Your access to this class has ended. Please contact us.', 'denied' ); }
			if ( 409 === res.status ) { return self.fail( 'This class is not open right now.', '' ); }
			return self.fail( 'Could not open the class. Please try again.', '' );
		} ).catch( function () {
			if ( win ) { win.close(); }
			self.fail( 'Network problem. Please try again.', '' );
		} );
	};

	var buttons = Array.prototype.map.call( document.querySelectorAll( '.live-join[data-live-id]' ), function ( el ) {
		return new LiveButton( el );
	} );
	if ( buttons.length ) {
		window.setInterval( function () { buttons.forEach( function ( b ) { b.render(); } ); }, TICK_MS );
	}
}() );
