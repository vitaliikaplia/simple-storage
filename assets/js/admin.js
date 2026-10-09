/* global SimpleStorage */
( function () {
	'use strict';

	const config = window.SimpleStorage;
	if ( ! config ) {
		return;
	}

	const t = config.i18n;
	let state = config.state;
	let running = false;

	const $ = ( name ) => document.querySelector( '[data-ss="' + name + '"]' );
	const $$ = ( selector ) => Array.from( document.querySelectorAll( selector ) );

	function el( tag, attrs, children ) {
		const node = document.createElement( tag );
		Object.entries( attrs || {} ).forEach( ( [ key, value ] ) => {
			if ( 'text' === key ) {
				node.textContent = value;
			} else if ( 'className' === key ) {
				node.className = value;
			} else {
				node.setAttribute( key, value );
			}
		} );
		( children || [] ).forEach( ( child ) => child && node.appendChild( child ) );

		return node;
	}

	function setText( name, value ) {
		const node = $( name );
		if ( node ) {
			node.textContent = value;
		}
	}

	async function post( action, data ) {
		const body = new FormData();
		body.append( 'action', 'simple_storage_' + action );
		body.append( 'nonce', config.nonce );
		Object.entries( data || {} ).forEach( ( [ key, value ] ) => body.append( key, value ) );

		const response = await fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body } );
		let json = null;
		try {
			json = await response.json();
		} catch ( error ) {
			throw new Error( t.networkError );
		}
		if ( json && json.data && json.data.state ) {
			state = json.data.state;
			render();
		}
		if ( ! json || ! json.success ) {
			const error = new Error( ( json && json.data && json.data.message ) || t.networkError );
			error.server = true;
			error.code = ( json && json.data && json.data.code ) || '';
			throw error;
		}

		return json.data;
	}

	function notice( message, type ) {
		const area = $( 'notices' );
		if ( ! area ) {
			window.alert( message ); // eslint-disable-line no-alert
			return;
		}
		area.replaceChildren( el( 'div', { className: 'notice inline notice-' + ( type || 'error' ) }, [ el( 'p', { text: message } ) ] ) );
	}

	function renderStats() {
		const stats = state.stats;
		[ 'total', 'local_only', 'both', 'remote_only' ].forEach( ( key ) => {
			setText( 'stat-' + key + '-files', stats[ key ].files );
			setText( 'stat-' + key + '-bytes', stats[ key ].bytes );
		} );
		setText( 'indexed', stats.indexed || t.never );
		setText( 'stat-errors', stats.errors );
		const errorsLink = $( 'errors-link' );
		if ( errorsLink ) {
			errorsLink.hidden = '0' === String( stats.errors );
		}
	}

	function renderStorage() {
		const connection = $( 'connection' );
		if ( connection ) {
			let text = '—';
			if ( state.configured ) {
				text = state.test.time ? ( state.test.ok ? t.testOk : t.testFailed ) : t.notTested;
			}
			connection.replaceChildren(
				el( 'span', { className: 'ss-badge ' + ( state.configured && state.test.ok ? 'ss-badge-ok' : 'ss-badge-muted' ), text } ),
				document.createTextNode( state.test.time ? ' ' + state.test.time : '' )
			);
		}

		const delivery = $( 'delivery' );
		if ( delivery ) {
			delivery.replaceChildren(
				el( 'span', { className: 'ss-badge ' + ( state.delivery ? 'ss-badge-ok' : 'ss-badge-muted' ), text: state.delivery ? t.on : t.off } ),
				document.createTextNode( ' ' + ( 'direct' === state.deliveryMode ? t.modeDirect : t.modeProxy ) )
			);
		}

		const proxyOn = state.delivery && 'proxy' === state.deliveryMode;
		let engine = '';
		if ( proxyOn ) {
			engine = 'php' === state.proxyEngine ? t.enginePhp : t.engineApache + ' (' + state.proxyEngine + ')';
			if ( state.proxyProbed ) {
				engine += ', ' + t.checked + ' ' + state.proxyProbed;
			}
		}
		setText( 'engine', engine );
		const verified = $( 'verified' );
		if ( verified ) {
			if ( ! state.delivery ) {
				verified.textContent = '';
			} else if ( state.verified.length ) {
				verified.textContent = t.verifiedFor + ' ' + state.verified.map( ( ext ) => ( ext ? '.' + ext : '—' ) ).join( ', ' );
			} else {
				verified.textContent = t.notVerified;
			}
		}
		const probeWrap = $( 'probe-wrap' );
		if ( probeWrap ) {
			probeWrap.hidden = ! proxyOn;
			const probeButton = $( 'probe' );
			probeButton.textContent = t.probe;
			probeButton.disabled = running;
		}

		const htaccess = { ok: t.htaccessOk, missing: t.htaccessMissing, stale: t.htaccessStale };
		setText( 'htaccess', state.delivery ? htaccess[ state.htaccess ] || '' : '' );
		setText( 'auto', state.auto ? t.autoOn : t.autoOff );

		const toggle = $( 'delivery-toggle' );
		if ( toggle ) {
			toggle.textContent = state.delivery ? t.disable : t.enable;
			toggle.disabled = ! state.configured || running;
		}
	}

	function percent( phase ) {
		if ( 'done' === phase.state ) {
			return 100;
		}
		if ( ! phase.total ) {
			return 0;
		}

		return Math.min( 100, Math.round( ( phase.done / phase.total ) * 100 ) );
	}

	function renderJob() {
		const box = $( 'job' );
		if ( ! box ) {
			return;
		}

		const job = state.job;
		if ( ! job || 'cancelled' === job.status ) {
			box.hidden = true;
			return;
		}
		box.hidden = false;

		setText( 'job-title', job.label );
		const status = $( 'job-status' );
		status.textContent = job.statusLabel;
		status.className = 'ss-badge ss-status-' + job.status;

		setText( 'job-meta', t.started + ' ' + job.started + ( job.finished ? ' · ' + t.finished + ' ' + job.finished : '' ) );

		$( 'job-phases' ).replaceChildren(
			...job.phases.map( ( phase ) => {
				const unit = 'local_scan' === phase.key || 'remote_scan' === phase.key ? t.folders : t.files;
				const children = [ el( 'span', { className: 'ss-phase-label', text: phase.label } ) ];
				if ( phase.measured && 'pending' !== phase.state ) {
					let detail = phase.done + ' / ' + phase.total + ' ' + unit + ' · ' + phase.bytes;
					if ( phase.total_bytes && 'local_scan' !== phase.key && 'remote_scan' !== phase.key ) {
						detail += ' / ' + phase.total_bytes;
					}
					if ( phase.failed ) {
						detail += ' · ' + phase.failed + ' ' + t.failed;
					}
					const bar = el( 'span', { className: 'ss-bar' }, [ el( 'span', { className: 'ss-bar-fill', style: 'width:' + percent( phase ) + '%' } ) ] );
					children.push( bar, el( 'span', { className: 'ss-phase-detail', text: detail } ) );
				}

				return el( 'li', { className: 'ss-phase ss-phase-' + phase.state }, children );
			} )
		);

		setText( 'job-current', 'running' === job.status && job.current ? t.current + ' ' + job.current : '' );

		const message = $( 'job-message' );
		message.replaceChildren();
		if ( job.message ) {
			message.appendChild( el( 'div', { className: 'notice inline ' + ( 'failed' === job.status ? 'notice-error' : 'notice-success' ) }, [ el( 'p', { text: job.message } ) ] ) );
		}
		if ( 'running' === job.status ) {
			message.appendChild( el( 'p', { className: 'description', text: t.keepOpen } ) );
		}

		$( 'job-warnings' ).replaceChildren( ...job.warnings.map( ( warning ) => el( 'li', { text: warning } ) ) );

		const buttons = [];
		if ( 'running' === job.status ) {
			buttons.push( el( 'button', { type: 'button', className: 'button', 'data-ss-job': 'pause', text: t.pause } ) );
		}
		if ( 'paused' === job.status || 'failed' === job.status ) {
			buttons.push( el( 'button', { type: 'button', className: 'button button-primary', 'data-ss-job': 'resume', text: t.resume } ) );
		}
		if ( [ 'running', 'paused', 'failed' ].includes( job.status ) ) {
			buttons.push( el( 'button', { type: 'button', className: 'button button-link-delete', 'data-ss-job': 'cancel', text: t.cancel } ) );
		}
		$( 'job-buttons' ).replaceChildren( ...buttons );
	}

	function renderErrors() {
		const box = $( 'errors' );
		if ( ! box ) {
			return;
		}
		if ( ! state.errors.length ) {
			box.replaceChildren( el( 'p', { text: t.noErrors } ) );
			return;
		}
		box.replaceChildren(
			el( 'table', { className: 'widefat striped' }, [
				el(
					'tbody',
					{},
					state.errors.map( ( error ) => el( 'tr', {}, [ el( 'td', {}, [ el( 'code', { text: error.path } ) ] ), el( 'td', { text: error.error } ) ] ) )
				),
			] )
		);
	}

	function renderLog() {
		const box = $( 'log' );
		if ( ! box ) {
			return;
		}
		box.replaceChildren(
			el(
				'ul',
				{ className: 'ss-log' },
				state.log.map( ( entry ) =>
					el( 'li', { className: 'ss-level-' + entry.level }, [ el( 'time', { text: entry.time } ), document.createTextNode( ' ' + entry.message ) ] )
				)
			)
		);
	}

	function render() {
		const active = state.job && [ 'running', 'paused', 'failed' ].includes( state.job.status );
		$$( '[data-ss-start]' ).forEach( ( button ) => {
			const needsStorage = 'index' !== button.getAttribute( 'data-ss-start' );
			button.disabled = running || active || ( needsStorage && ! state.configured );
		} );

		renderStats();
		renderStorage();
		renderJob();
		renderErrors();
		renderLog();
	}

	const sleep = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) );

	async function loop() {
		if ( running ) {
			return;
		}
		running = true;
		render();

		let failures = 0;
		while ( state.job && 'running' === state.job.status ) {
			try {
				await post( 'job', { do: 'step' } );
				failures = 0;
			} catch ( error ) {
				if ( 'simple_storage_job_locked' === error.code ) {
					// Another tab, request or WP-CLI holds the job: follow it, never pause it.
					notice( t.busy, 'info' );
					await sleep( 5000 );
					try {
						await post( 'state' );
					} catch ( ignored ) {}
					continue;
				}
				failures++;
				if ( failures >= 4 ) {
					try {
						await post( 'job', { do: 'pause' } );
					} catch ( ignored ) {}
					notice( error.server ? error.message : t.networkError );
					break;
				}
				await sleep( 3000 * failures );
			}
		}

		running = false;
		render();
	}

	async function start( type ) {
		if ( 'push' === type && ! window.confirm( t.confirmPush ) ) { // eslint-disable-line no-alert
			return;
		}
		if ( 'pull' === type && ! window.confirm( t.confirmPull ) ) { // eslint-disable-line no-alert
			return;
		}
		try {
			await post( 'job', { do: 'start', type } );
			loop();
		} catch ( error ) {
			notice( error.message );
		}
	}

	async function jobAction( action ) {
		if ( 'cancel' === action && ! window.confirm( t.confirmCancel ) ) { // eslint-disable-line no-alert
			return;
		}
		try {
			await post( 'job', { do: action } );
			if ( 'resume' === action ) {
				loop();
			}
		} catch ( error ) {
			notice( error.message );
		}
	}

	async function toggleDelivery() {
		if ( state.delivery && state.stats.raw.remote_only > 0 && ! window.confirm( t.confirmDisable ) ) { // eslint-disable-line no-alert
			return;
		}
		try {
			await post( 'delivery', { do: state.delivery ? 'disable' : 'enable' } );
		} catch ( error ) {
			notice( error.message );
		}
	}

	async function test( button ) {
		const list = $( 'test-steps' );
		button.disabled = true;
		list.replaceChildren( el( 'li', { text: t.testing } ) );
		try {
			const data = await post( 'test' );
			list.replaceChildren(
				...data.report.steps.map( ( step ) =>
					el( 'li', { className: step.ok ? 'ss-ok' : 'ss-fail' }, [
						el( 'strong', { text: step.label } ),
						step.message ? el( 'span', { text: ' — ' + step.message } ) : null,
					] )
				),
				el( 'li', { className: data.report.ok ? 'ss-ok ss-summary' : 'ss-fail ss-summary', text: data.report.ok ? t.testOk : t.testFailed } )
			);
		} catch ( error ) {
			list.replaceChildren( el( 'li', { className: 'ss-fail', text: error.message } ) );
		}
		button.disabled = false;
	}

	document.addEventListener( 'click', ( event ) => {
		const target = event.target.closest( 'button' );
		if ( ! target ) {
			return;
		}
		if ( target.hasAttribute( 'data-ss-start' ) ) {
			start( target.getAttribute( 'data-ss-start' ) );
		} else if ( target.hasAttribute( 'data-ss-job' ) ) {
			jobAction( target.getAttribute( 'data-ss-job' ) );
		} else if ( 'delivery-toggle' === target.getAttribute( 'data-ss' ) ) {
			toggleDelivery();
		} else if ( 'test' === target.getAttribute( 'data-ss' ) ) {
			test( target );
		} else if ( 'probe' === target.getAttribute( 'data-ss' ) ) {
			target.disabled = true;
			target.textContent = t.probing;
			post( 'probe' ).catch( ( error ) => notice( error.message ) ).finally( render );
		} else if ( target.hasAttribute( 'data-ss-clear' ) ) {
			post( 'clear', { what: target.getAttribute( 'data-ss-clear' ) } ).then( () => {
				if ( ! $( 'job' ) ) {
					window.location.reload();
				}
			} );
		}
	} );

	window.addEventListener( 'beforeunload', ( event ) => {
		if ( running ) {
			event.preventDefault();
			event.returnValue = t.leave;
		}
	} );

	render();
	if ( $( 'job' ) && state.job && 'running' === state.job.status ) {
		loop();
	}
}() );
