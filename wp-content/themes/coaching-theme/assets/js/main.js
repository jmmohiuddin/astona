( function () {
	'use strict';

	// Analytics (PRD 27). Nothing is loaded or sent until the visitor accepts; no personal data is ever attached.
	var GA_ID = window.ASTONA && window.ASTONA.ga4;
	var CONSENT_KEY = 'cc_consent';
	var gaLoaded = false;

	function consent() {
		try { return window.localStorage.getItem( CONSENT_KEY ); } catch ( e ) { return null; }
	}

	function loadGa() {
		if ( gaLoaded || ! GA_ID ) { return; }
		gaLoaded = true;
		window.dataLayer = window.dataLayer || [];
		window.gtag = function () { window.dataLayer.push( arguments ); };
		window.gtag( 'js', new Date() );
		window.gtag( 'config', GA_ID, { anonymize_ip: true, transport_type: 'beacon' } );
		var s = document.createElement( 'script' );
		s.async = true;
		s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent( GA_ID );
		document.head.appendChild( s );
	}

	window.astonaTrack = function ( name, params ) {
		if ( ! GA_ID || 'granted' !== consent() ) { return; }
		loadGa();
		window.gtag( 'event', name, params || {} );
	};

	function showBanner() {
		var bar = document.createElement( 'div' );
		bar.className = 'consent-bar';
		bar.setAttribute( 'role', 'region' );
		bar.setAttribute( 'aria-label', 'Analytics consent' );
		bar.innerHTML = '<p>We use anonymous analytics to improve this site. No personal details are shared.</p>' +
			'<div class="consent-bar__actions"><button type="button" class="btn btn--primary" data-consent="granted">Accept</button>' +
			'<button type="button" class="btn btn--ghost" data-consent="denied">Decline</button></div>';
		bar.addEventListener( 'click', function ( event ) {
			var choice = event.target && event.target.getAttribute && event.target.getAttribute( 'data-consent' );
			if ( ! choice ) { return; }
			try { window.localStorage.setItem( CONSENT_KEY, choice ); } catch ( e ) { /* private mode: ask again next visit */ }
			bar.remove();
			if ( 'granted' === choice ) { loadGa(); fireViews(); }
		} );
		document.body.appendChild( bar );
	}

	// Elements marked data-cc-view="event_name" report once when the page is seen.
	function fireViews() {
		document.querySelectorAll( '[data-cc-view]' ).forEach( function ( el ) {
			window.astonaTrack( el.getAttribute( 'data-cc-view' ), { course_id: el.getAttribute( 'data-cc-course' ) || undefined } );
		} );
	}

	// Elements marked data-cc-event="event_name" report on click (links keep working: gtag uses a beacon).
	document.addEventListener( 'click', function ( event ) {
		var el = event.target && event.target.closest ? event.target.closest( '[data-cc-event]' ) : null;
		if ( el ) { window.astonaTrack( el.getAttribute( 'data-cc-event' ), { course_id: el.getAttribute( 'data-cc-course' ) || undefined } ); }
	} );

	if ( GA_ID ) {
		var choice = consent();
		if ( 'granted' === choice ) { loadGa(); fireViews(); } else if ( null === choice ) { showBanner(); }
	}
} )();

( function () {
	'use strict';

	// Mobile navigation.
	var toggle = document.querySelector( '.nav-toggle' );
	var nav = document.getElementById( 'primary-nav' );
	if ( toggle && nav ) {
		toggle.addEventListener( 'click', function () {
			var open = nav.classList.toggle( 'is-open' );
			toggle.setAttribute( 'aria-expanded', String( open ) );
			toggle.setAttribute( 'aria-label', open ? 'Close menu' : 'Open menu' );
		} );
		window.matchMedia( '(min-width: 1024px)' ).addEventListener( 'change', function ( event ) {
			if ( event.matches ) {
				nav.classList.remove( 'is-open' );
				toggle.setAttribute( 'aria-expanded', 'false' );
				toggle.setAttribute( 'aria-label', 'Open menu' );
			}
		} );
		document.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Escape' && nav.classList.contains( 'is-open' ) ) {
				toggle.click();
				toggle.focus();
			}
		} );
	}

	// Batch selector keeps the Apply links in step with the chosen batch.
	var batchForm = document.querySelector( '[data-batch-form]' );
	if ( batchForm ) {
		var base = batchForm.getAttribute( 'data-apply-base' );
		batchForm.addEventListener( 'change', function ( event ) {
			if ( ! event.target || event.target.name !== 'batch' ) {
				return;
			}
			var url = new URL( base, window.location.href );
			url.searchParams.set( 'batch', event.target.value );
			document.querySelectorAll( '[data-apply-cta]' ).forEach( function ( link ) {
				link.setAttribute( 'href', url.toString() );
			} );
		} );
	}

	// Course filters: enhance the server-rendered GET form with a REST fetch.
	var form = document.querySelector( '[data-course-filters]' );
	var grid = document.querySelector( '[data-course-grid]' );
	var emptyBox = document.querySelector( '[data-course-empty]' );
	var status = document.querySelector( '[data-course-status]' );
	if ( ! form || ! grid || ! window.ASTONA || ! window.fetch ) {
		return;
	}

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text !== undefined ) {
			node.textContent = text;
		}
		return node;
	}

	function buildCard( course ) {
		var article = el( 'article', 'card course-card' );
		var link = el( 'a', 'card__link' );
		link.href = course.url;

		var media = el( 'div', 'card__media' );
		if ( course.thumbnail ) {
			var img = el( 'img' );
			img.src = course.thumbnail;
			img.alt = '';
			img.width = 640;
			img.height = 400;
			img.loading = 'lazy';
			media.appendChild( img );
		} else {
			media.appendChild( el( 'span', 'card__placeholder', ( course.title || '?' ).charAt( 0 ) ) );
		}

		var body = el( 'div', 'card__body' );
		var meta = el( 'div', 'card__meta' );
		meta.appendChild( el( 'span', 'chip chip--' + course.status, course.status_label ) );
		( course.modes || [] ).forEach( function ( mode ) {
			meta.appendChild( el( 'span', 'tag', mode.charAt( 0 ).toUpperCase() + mode.slice( 1 ) ) );
		} );
		body.appendChild( meta );
		body.appendChild( el( 'h3', 'card__title', course.title ) );
		body.appendChild( el( 'p', 'card__text', course.excerpt ) );
		if ( course.price_display ) {
			var price = el( 'p', 'card__price' );
			price.appendChild( el( 'span', 'muted', 'From ' ) );
			price.appendChild( el( 'strong', '', course.price_display ) );
			body.appendChild( price );
		}

		link.appendChild( media );
		link.appendChild( body );
		article.appendChild( link );
		return article;
	}

	function load() {
		var params = new URLSearchParams( new FormData( form ) );
		var query = new URLSearchParams();
		params.forEach( function ( value, key ) {
			if ( value ) {
				query.set( key, value );
			}
		} );
		grid.classList.add( 'is-loading' );

		fetch( window.ASTONA.coursesUrl + ( query.toString() ? '?' + query.toString() : '' ), { headers: { Accept: 'application/json' } } )
			.then( function ( res ) {
				if ( ! res.ok ) {
					throw new Error( 'bad status' );
				}
				return res.json();
			} )
			.then( function ( data ) {
				var items = data.items || [];
				grid.replaceChildren.apply( grid, items.map( buildCard ) );
				if ( emptyBox ) {
					emptyBox.hidden = items.length > 0;
				}
				if ( status ) {
					status.textContent = '';
					window.setTimeout( function () {
						status.textContent = items.length + ( items.length === 1 ? ' course found' : ' courses found' );
					}, 50 );
				}
				history.replaceState( null, '', query.toString() ? '?' + query.toString() : window.location.pathname );
			} )
			.catch( function () {
				form.submit(); // Fall back to the server-rendered page.
			} )
			.finally( function () {
				grid.classList.remove( 'is-loading' );
			} );
	}

	form.addEventListener( 'change', load );
	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();
		load();
	} );
} )();
