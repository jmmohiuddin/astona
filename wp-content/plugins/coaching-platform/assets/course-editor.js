/* Course editor: Batch > Module > Lesson, edited inline and saved in one transactional REST call.
 * Plain wp.element (React shipped with WordPress): no build step, no JSX. */
( function () {
	'use strict';

	var wp = window.wp;
	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var cfg = window.CC_COURSE_EDITOR || {};
	var keySeq = 0;

	var MODES = [ [ 'physical', 'Physical' ], [ 'online', 'Online' ], [ 'hybrid', 'Hybrid' ] ];
	var STATUSES = [ [ 'draft', 'Draft' ], [ 'open', 'Open' ], [ 'closed', 'Closed' ], [ 'completed', 'Completed' ] ];

	/* ---------- data helpers ---------- */

	function withKeys( tree ) {
		var t = JSON.parse( JSON.stringify( tree ) );
		t.batches.forEach( function ( b ) {
			b._k = 'b' + ( ++keySeq );
			b.modules.forEach( function ( m ) {
				m._k = 'm' + ( ++keySeq );
				m.lessons.forEach( function ( l ) { l._k = 'l' + ( ++keySeq ); } );
			} );
		} );
		return t;
	}

	function clone( x ) { return JSON.parse( JSON.stringify( x ) ); }

	function emptyBatch() {
		return { _k: 'b' + ( ++keySeq ), id: 0, name: '', delivery_mode: 'physical', capacity: 30, seats_taken: 0, price: 0, start_date: '', end_date: '', schedule_text: '', status: 'draft', application_open: false, waitlist_enabled: false, installments_enabled: false, first_payment_percent: 50, installment_days: 30, modules: [] };
	}
	function emptyModule() { return { _k: 'm' + ( ++keySeq ), id: 0, title: '', lessons: [] }; }
	function emptyLesson() { return { _k: 'l' + ( ++keySeq ), id: 0, title: '', scheduled_local: '', has_attachment: false, attachment_name: '' }; }

	function move( list, from, to ) {
		if ( to < 0 || to >= list.length ) { return list; }
		var copy = list.slice();
		var item = copy.splice( from, 1 )[ 0 ];
		copy.splice( to, 0, item );
		return copy;
	}

	/* ---------- small components ---------- */

	function Field( props ) {
		var err = props.errors[ props.path ];
		var id = 'cce-' + props.path.replace( /\./g, '-' );
		var control = props.render( { id: id, 'aria-invalid': err ? 'true' : undefined, 'aria-describedby': err ? id + '-err' : undefined } );
		return el( 'div', { className: 'cce-field' + ( props.wide ? ' cce-field--wide' : '' ) + ( err ? ' is-invalid' : '' ) },
			el( 'label', { htmlFor: id }, props.label ),
			control,
			err ? el( 'p', { className: 'cce-error', id: id + '-err', role: 'alert' }, err ) : null
		);
	}

	function TextField( p ) {
		return el( Field, { path: p.path, label: p.label, errors: p.errors, wide: p.wide, render: function ( a ) {
			return el( 'input', Object.assign( { type: p.type || 'text', value: p.value === null || p.value === undefined ? '' : p.value, maxLength: p.max, min: p.min, step: p.step, onChange: function ( e ) { p.onChange( e.target.value ); } }, a ) );
		} } );
	}

	function SelectField( p ) {
		return el( Field, { path: p.path, label: p.label, errors: p.errors, render: function ( a ) {
			return el( 'select', Object.assign( { value: p.value, onChange: function ( e ) { p.onChange( e.target.value ); } }, a ),
				p.options.map( function ( o ) { return el( 'option', { key: o[ 0 ], value: o[ 0 ] }, o[ 1 ] ); } ) );
		} } );
	}

	function Check( p ) {
		return el( 'label', { className: 'cce-check' }, el( 'input', { type: 'checkbox', checked: !! p.value, onChange: function ( e ) { p.onChange( e.target.checked ); } } ), ' ' + p.label );
	}

	function IconBtn( p ) {
		return el( 'button', { type: 'button', className: 'button button-small cce-icon' + ( p.danger ? ' cce-danger' : '' ), onClick: p.onClick, disabled: p.disabled, title: p.title || p.label, 'aria-label': p.label }, p.text );
	}

	function Capacity( p ) {
		var cap = parseInt( p.capacity, 10 ) || 0;
		var pct = cap > 0 ? Math.min( 100, Math.round( ( p.seats / cap ) * 100 ) ) : 0;
		var over = cap > 0 && p.seats > cap;
		return el( 'div', { className: 'cce-capacity' + ( pct >= 80 ? ' is-filling' : '' ) + ( p.seats >= cap && cap > 0 ? ' is-full' : '' ) },
			el( 'div', { className: 'cce-capacity__bar', role: 'progressbar', 'aria-valuemin': 0, 'aria-valuemax': cap, 'aria-valuenow': p.seats, 'aria-label': 'Seats taken' }, el( 'span', { style: { width: pct + '%' } } ) ),
			el( 'span', null, p.seats + ' of ' + cap + ' seats taken' + ( cap > 0 ? ' (' + pct + '%)' : '' ) + ( over ? ' - over capacity' : '' ) )
		);
	}

	/* ---------- lessons, modules, batches ---------- */

	function Lesson( p ) {
		var l = p.lesson;
		var base = p.base;
		return el( 'li', { className: 'cce-lesson' },
			el( 'div', { className: 'cce-row' },
				el( TextField, { path: base + '.title', label: 'Lesson title', value: l.title, max: 190, errors: p.errors, wide: true, onChange: function ( v ) { p.set( 'title', v ); } } ),
				el( TextField, { path: base + '.scheduled_local', label: 'Date and time (' + ( cfg.timezone || 'site time' ) + ')', type: 'datetime-local', value: l.scheduled_local, errors: p.errors, onChange: function ( v ) { p.set( 'scheduled_local', v ); } } ),
				el( 'div', { className: 'cce-lesson__tools' },
					l.has_attachment ? el( 'span', { className: 'cce-pill', title: 'Upload or replace PDFs in Course content' }, 'PDF: ' + ( l.attachment_name || 'attached' ) ) : null,
					p.otherModules.length ? el( 'select', { 'aria-label': 'Move lesson to module', value: '', onChange: function ( e ) { if ( e.target.value !== '' ) { p.moveTo( parseInt( e.target.value, 10 ) ); } } },
						[ el( 'option', { key: 'x', value: '' }, 'Move to...' ) ].concat( p.otherModules.map( function ( o ) { return el( 'option', { key: o.index, value: o.index }, o.title || '(untitled module)' ); } ) ) ) : null,
					el( IconBtn, { text: '↑', label: 'Move lesson up', disabled: p.index === 0, onClick: function () { p.shift( -1 ); } } ),
					el( IconBtn, { text: '↓', label: 'Move lesson down', disabled: p.last, onClick: function () { p.shift( 1 ); } } ),
					el( IconBtn, { text: '×', label: 'Delete lesson', danger: true, onClick: p.remove } )
				)
			)
		);
	}

	function Module( p ) {
		var m = p.module;
		return el( 'section', { className: 'cce-module', 'aria-label': 'Module ' + ( m.title || '(untitled)' ) },
			el( 'div', { className: 'cce-row cce-module__head' },
				el( TextField, { path: p.base + '.title', label: 'Module title', value: m.title, max: 190, errors: p.errors, wide: true, onChange: function ( v ) { p.set( 'title', v ); } } ),
				el( 'div', { className: 'cce-lesson__tools' },
					el( IconBtn, { text: '↑', label: 'Move module up', disabled: p.index === 0, onClick: function () { p.shift( -1 ); } } ),
					el( IconBtn, { text: '↓', label: 'Move module down', disabled: p.last, onClick: function () { p.shift( 1 ); } } ),
					el( IconBtn, { text: '×', label: 'Delete module', danger: true, onClick: p.remove } )
				)
			),
			el( 'ul', { className: 'cce-lessons' }, m.lessons.map( function ( l, li ) {
				return el( Lesson, {
					key: l._k, lesson: l, index: li, last: li === m.lessons.length - 1, base: p.base + '.lessons.' + li, errors: p.errors,
					set: function ( k, v ) { p.change( function ( mod ) { mod.lessons[ li ][ k ] = v; } ); },
					shift: function ( d ) { p.change( function ( mod ) { mod.lessons = move( mod.lessons, li, li + d ); } ); },
					remove: function () { if ( ! l.title || window.confirm( 'Delete the lesson "' + l.title + '"?' ) ) { p.change( function ( mod ) { mod.lessons.splice( li, 1 ); } ); } },
					otherModules: p.siblings, moveTo: function ( to ) { p.moveLesson( li, to ); }
				} );
			} ) ),
			el( 'p', null, el( 'button', { type: 'button', className: 'button', onClick: function () { p.change( function ( mod ) { mod.lessons.push( emptyLesson() ); } ); } }, '+ Add lesson' ) )
		);
	}

	function Batch( p ) {
		var b = p.batch;
		var i = p.index;
		var base = 'batches.' + i;
		var set = function ( k ) { return function ( v ) { p.change( function ( batch ) { batch[ k ] = v; } ); }; };
		var hasErr = Object.keys( p.errors ).some( function ( k ) { return k.indexOf( base + '.' ) === 0 || k === base; } );
		var open = p.open || hasErr;
		var protectedBatch = b.id > 0 && b.seats_taken > 0;
		return el( 'article', { className: 'cce-batch' + ( open ? ' is-open' : '' ) + ( hasErr ? ' has-error' : '' ) },
			el( 'header', { className: 'cce-batch__head' },
				el( 'button', { type: 'button', className: 'cce-toggle', 'aria-expanded': open ? 'true' : 'false', onClick: p.toggle }, ( open ? '▾ ' : '▸ ' ) + ( b.name || 'New batch' ) ),
				el( 'span', { className: 'cce-pill cce-pill--' + b.status }, b.status ),
				b.id > 0 ? el( Capacity, { capacity: b.capacity, seats: b.seats_taken } ) : null,
				el( IconBtn, { text: 'Delete', label: 'Delete batch', danger: true, disabled: protectedBatch, title: protectedBatch ? 'This batch has applications. Set it to Closed instead.' : 'Delete batch', onClick: function () { if ( window.confirm( 'Delete the batch "' + ( b.name || 'New batch' ) + '" with all its modules and lessons?' ) ) { p.remove(); } } } )
			),
			! open ? null : el( 'div', { className: 'cce-batch__body' },
				el( 'div', { className: 'cce-grid' },
					el( TextField, { path: base + '.name', label: 'Batch name', value: b.name, max: 190, errors: p.errors, onChange: set( 'name' ) } ),
					el( SelectField, { path: base + '.delivery_mode', label: 'Mode', value: b.delivery_mode, options: MODES, errors: p.errors, onChange: set( 'delivery_mode' ) } ),
					el( SelectField, { path: base + '.status', label: 'Status', value: b.status, options: STATUSES, errors: p.errors, onChange: set( 'status' ) } ),
					el( TextField, { path: base + '.capacity', label: 'Capacity' + ( b.seats_taken ? ' (at least ' + b.seats_taken + ')' : '' ), type: 'number', min: b.seats_taken || 0, value: b.capacity, errors: p.errors, onChange: set( 'capacity' ) } ),
					el( TextField, { path: base + '.price', label: 'Price (BDT)', type: 'number', min: 0, step: '0.01', value: b.price, errors: p.errors, onChange: set( 'price' ) } ),
					el( TextField, { path: base + '.start_date', label: 'Start date', type: 'date', value: b.start_date, errors: p.errors, onChange: set( 'start_date' ) } ),
					el( TextField, { path: base + '.end_date', label: 'End date (optional)', type: 'date', value: b.end_date, errors: p.errors, onChange: set( 'end_date' ) } ),
					el( TextField, { path: base + '.schedule_text', label: 'Schedule, e.g. Sat, Mon 6:00-7:30 PM', value: b.schedule_text, max: 255, errors: p.errors, wide: true, onChange: set( 'schedule_text' ) } )
				),
				el( 'div', { className: 'cce-checks' },
					el( Check, { label: 'Applications open', value: b.application_open, onChange: set( 'application_open' ) } ),
					el( Check, { label: 'Waitlist when full', value: b.waitlist_enabled, onChange: set( 'waitlist_enabled' ) } ),
					el( Check, { label: 'Allow two-part payment', value: b.installments_enabled, onChange: set( 'installments_enabled' ) } )
				),
				! b.installments_enabled ? null : el( 'div', { className: 'cce-grid' },
					el( TextField, { path: base + '.first_payment_percent', label: 'First payment %', type: 'number', min: 10, step: 1, value: b.first_payment_percent, errors: p.errors, onChange: set( 'first_payment_percent' ) } ),
					el( TextField, { path: base + '.installment_days', label: 'Balance due after (days)', type: 'number', min: 1, value: b.installment_days, errors: p.errors, onChange: set( 'installment_days' ) } )
				),
				el( 'h3', null, 'Modules and lessons' ),
				b.modules.length === 0 ? el( 'p', { className: 'description' }, 'No modules yet.' ) : null,
				b.modules.map( function ( m, mi ) {
					return el( Module, {
						key: m._k, module: m, index: mi, last: mi === b.modules.length - 1, base: base + '.modules.' + mi, errors: p.errors,
						siblings: b.modules.map( function ( o, oi ) { return { index: oi, title: o.title }; } ).filter( function ( o ) { return o.index !== mi; } ),
						set: function ( k, v ) { p.change( function ( batch ) { batch.modules[ mi ][ k ] = v; } ); },
						change: function ( fn ) { p.change( function ( batch ) { fn( batch.modules[ mi ] ); } ); },
						shift: function ( d ) { p.change( function ( batch ) { batch.modules = move( batch.modules, mi, mi + d ); } ); },
						remove: function () { if ( ( ! m.title && ! m.lessons.length ) || window.confirm( 'Delete the module "' + m.title + '" and its ' + m.lessons.length + ' lesson(s)?' ) ) { p.change( function ( batch ) { batch.modules.splice( mi, 1 ); } ); } },
						moveLesson: function ( li, to ) { p.change( function ( batch ) { var lesson = batch.modules[ mi ].lessons.splice( li, 1 )[ 0 ]; batch.modules[ to ].lessons.push( lesson ); } ); }
					} );
				} ),
				el( 'p', null, el( 'button', { type: 'button', className: 'button', onClick: function () { p.change( function ( batch ) { batch.modules.push( emptyModule() ); } ); } }, '+ Add module' ) )
			)
		);
	}

	/* ---------- app ---------- */

	function App() {
		var _tree = useState( null ), tree = _tree[ 0 ], setTree = _tree[ 1 ];
		var _dirty = useState( false ), dirty = _dirty[ 0 ], setDirty = _dirty[ 1 ];
		var _saving = useState( false ), saving = _saving[ 0 ], setSaving = _saving[ 1 ];
		var _errors = useState( {} ), errors = _errors[ 0 ], setErrors = _errors[ 1 ];
		var _msg = useState( null ), msg = _msg[ 0 ], setMsg = _msg[ 1 ];
		var _open = useState( {} ), open = _open[ 0 ], setOpen = _open[ 1 ];
		var dirtyRef = useRef( false );
		dirtyRef.current = dirty;

		function load() {
			setMsg( { type: 'info', text: 'Loading...' } );
			wp.apiFetch( { path: '/cc/v1/admin/courses/' + cfg.courseId + '/tree' } ).then( function ( data ) {
				var t = withKeys( data );
				setTree( t );
				setDirty( false );
				setErrors( {} );
				setMsg( null );
				var o = {};
				if ( t.batches.length === 1 ) { o[ t.batches[ 0 ]._k ] = true; }
				setOpen( o );
			} ).catch( function () { setMsg( { type: 'error', text: 'Could not load the course. Reload the page.' } ); } );
		}

		useEffect( function () {
			load();
			function warn( e ) { if ( dirtyRef.current ) { e.preventDefault(); e.returnValue = ''; return ''; } }
			window.addEventListener( 'beforeunload', warn );
			return function () { window.removeEventListener( 'beforeunload', warn ); };
		}, [] );

		function mutate( fn ) {
			setTree( function ( prev ) { var next = clone( prev ); fn( next ); return next; } );
			setDirty( true );
			setMsg( null );
		}

		function save() {
			setSaving( true );
			setMsg( null );
			var body = { rev: tree.rev, batches: tree.batches };
			// Keys are regenerated on load, so remember which batches were open by id (new ones by name).
			var wasOpen = tree.batches.filter( function ( b ) { return open[ b._k ]; } ).map( function ( b ) { return { id: b.id, name: b.name }; } );
			wp.apiFetch( { path: '/cc/v1/admin/courses/' + cfg.courseId + '/tree', method: 'PUT', data: body } ).then( function ( data ) {
				var t = withKeys( data );
				var reopen = {};
				t.batches.forEach( function ( b ) {
					reopen[ b._k ] = wasOpen.some( function ( w ) { return w.id ? w.id === b.id : w.name === b.name; } );
				} );
				setOpen( reopen );
				setTree( t );
				setDirty( false );
				setErrors( {} );
				setSaving( false );
				setMsg( { type: 'success', text: 'Saved.' } );
			} ).catch( function ( err ) {
				setSaving( false );
				if ( err && err.code === 'invalid' ) {
					setErrors( err.errors || {} );
					setMsg( { type: 'error', text: err.message || 'Please fix the highlighted fields.' } );
				} else if ( err && err.code === 'conflict' ) {
					setMsg( { type: 'conflict', text: err.message } );
				} else {
					setMsg( { type: 'error', text: ( err && err.message ) || 'Could not save. Check your connection and try again.' } );
				}
			} );
		}

		if ( ! tree ) { return el( 'p', { role: 'status' }, msg ? msg.text : 'Loading...' ); }

		return el( 'div', { className: 'cce' },
			el( 'div', { className: 'cce-bar' },
				el( 'strong', null, tree.course.title ),
				dirty ? el( 'span', { className: 'cce-unsaved' }, 'Unsaved changes' ) : el( 'span', { className: 'cce-saved' }, 'All changes saved' ),
				el( 'span', { className: 'cce-bar__spacer' } ),
				el( 'button', { type: 'button', className: 'button', disabled: ! dirty || saving, onClick: function () { if ( window.confirm( 'Discard your unsaved changes?' ) ) { load(); } } }, 'Discard changes' ),
				el( 'button', { type: 'button', className: 'button button-primary', disabled: ! dirty || saving, onClick: save }, saving ? 'Saving...' : 'Save all' )
			),
			msg ? el( 'div', { className: 'notice notice-' + ( msg.type === 'success' ? 'success' : msg.type === 'info' ? 'info' : 'error' ) + ' cce-msg', role: msg.type === 'error' || msg.type === 'conflict' ? 'alert' : 'status' },
				el( 'p', null, msg.text, msg.type === 'conflict' ? el( 'span', null, ' ', el( 'button', { type: 'button', className: 'button', onClick: load }, 'Reload now' ) ) : null ) ) : null,
			tree.batches.length === 0 ? el( 'p', { className: 'description' }, 'This course has no batches yet. A course needs at least one open batch to take applications.' ) : null,
			tree.batches.map( function ( b, i ) {
				return el( Batch, {
					key: b._k, batch: b, index: i, errors: errors, open: !! open[ b._k ],
					toggle: function () { setOpen( function ( o ) { var n = Object.assign( {}, o ); n[ b._k ] = ! n[ b._k ]; return n; } ); },
					change: function ( fn ) { mutate( function ( t ) { fn( t.batches[ i ] ); } ); },
					remove: function () { mutate( function ( t ) { t.batches.splice( i, 1 ); } ); }
				} );
			} ),
			el( 'p', null, el( 'button', { type: 'button', className: 'button button-secondary', onClick: function () {
				var nb = emptyBatch();
				mutate( function ( t ) { t.batches.push( nb ); } );
				setOpen( function ( o ) { var n = Object.assign( {}, o ); n[ nb._k ] = true; return n; } );
			} }, '+ Add batch' ) )
		);
	}

	var root = document.getElementById( 'cc-course-editor' );
	if ( root && wp.element ) {
		if ( wp.element.createRoot ) { wp.element.createRoot( root ).render( el( App ) ); } else { wp.element.render( el( App ), root ); }
	}
}() );
