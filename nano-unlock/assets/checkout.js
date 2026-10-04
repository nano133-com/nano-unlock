/**
 * Nano Unlock: the reader's checkout.
 *
 * A click on "Unlock" asks this site for a checkout (a unique amount), shows
 * it as a QR code and a nano: link, and asks the site every two seconds
 * whether the payment arrived. When it has, the site sets a receipt cookie
 * and the page reloads with the paid part in it. The paid part is never in
 * this page before that.
 *
 * The open checkout is kept in sessionStorage (per item), so a reload of
 * this tab picks it up again instead of losing a payment in flight. When the
 * site answers that the payment is recorded but the receipt cookie could not
 * be set, the checkout stays, with a "Try again" button.
 */
( function () {
	'use strict';

	var cfg = window.nanoUnlock;
	if ( ! cfg ) {
		return;
	}
	var t = cfg.text;
	var POLL_MS = 2000;
	var LATE_POLL_MS = 5000;
	var KEY = 'nano-unlock:';
	// After a "paid" reload, a box that is still locked means the browser didn't keep the receipt.
	var RELOAD_GRACE_MS = 10 * 60 * 1000;

	function saved( item ) {
		try {
			var v = window.sessionStorage.getItem( KEY + item );
			return v ? JSON.parse( v ) : null;
		} catch ( e ) {
			return null;
		}
	}

	function save( item, entry ) {
		try {
			window.sessionStorage.setItem( KEY + item, JSON.stringify( entry ) );
		} catch ( e ) {}
	}

	function forget( item ) {
		try {
			window.sessionStorage.removeItem( KEY + item );
		} catch ( e ) {}
	}

	function api( path, body ) {
		return fetch( cfg.rest + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: JSON.stringify( body ),
		} ).then( function ( r ) {
			return r
				.json()
				.catch( function () {
					return {};
				} )
				.then( function ( data ) {
					return { status: r.status, data: data };
				} );
		} );
	}

	function node( tag, className, text ) {
		var n = document.createElement( tag );
		if ( className ) {
			n.className = className;
		}
		if ( text !== undefined ) {
			n.textContent = text;
		}
		return n;
	}

	function copyButton( value ) {
		var b = node( 'button', 'nano-unlock__copy', t.copy );
		b.type = 'button';
		b.addEventListener( 'click', function () {
			var done = function () {
				b.textContent = t.copied;
				setTimeout( function () {
					b.textContent = t.copy;
				}, 1500 );
			};
			if ( navigator.clipboard ) {
				navigator.clipboard.writeText( value ).then( done, function () {} );
			}
		} );
		return b;
	}

	function mmss( s ) {
		s = Math.max( 0, Math.floor( s ) );
		var m = Math.floor( s / 60 );
		var r = s % 60;
		return m + ':' + ( r < 10 ? '0' : '' ) + r;
	}

	function start( box, resume ) {
		var item = box.getAttribute( 'data-nano-unlock-item' ) || '';
		var button = box.querySelector( '.nano-unlock__button' );
		var panel = box.querySelector( '.nano-unlock__checkout' );
		var timer = null;
		var clock = null;
		var stopped = false;

		function stop() {
			stopped = true;
			clearTimeout( timer );
			clearInterval( clock );
		}

		// The checkout is over: show why, and offer a new one. keep: a reload may still pick it up.
		function fail( message, keep ) {
			stop();
			if ( ! keep ) {
				forget( item );
			}
			panel.hidden = false;
			panel.textContent = '';
			panel.appendChild( node( 'p', 'nano-unlock__error', message ) );
			button.disabled = false;
			button.hidden = false;
		}

		if ( resume ) {
			show( resume.c, !! resume.reloaded );
			return;
		}

		button.disabled = true;
		button.textContent = t.starting;
		api( 'checkout', { offer: box.getAttribute( 'data-nano-unlock-offer' ) } ).then(
			function ( res ) {
				button.textContent = button.getAttribute( 'data-label' );
				if ( res.status !== 200 ) {
					fail( ( res.data && res.data.message ) || t.busy );
					return;
				}
				// The server's clock minus this browser's, kept with the checkout for a reload.
				res.data.skew = res.data.now * 1000 - Date.now();
				save( item, { c: res.data } );
				show( res.data, false );
			},
			function () {
				button.textContent = button.getAttribute( 'data-label' );
				fail( t.busy );
			}
		);

		// reloaded: the page already reloaded for this paid checkout, and the box is still locked.
		function show( c, reloaded ) {
			var skew = c.skew || 0;
			button.hidden = true;
			panel.hidden = false;
			panel.textContent = '';

			var wrap = node( 'div', 'nano-unlock__pay' );
			var qrLink = node( 'a', 'nano-unlock__qr' );
			qrLink.href = c.uri;
			if ( window.qrcode ) {
				var qr = window.qrcode( 0, 'M' );
				qr.addData( c.uri );
				qr.make();
				var img = node( 'img' );
				img.src = qr.createDataURL( 5, 2 );
				img.alt = t.qr + ' Ӿ' + c.xno + ' ' + t.to + ' ' + c.address;
				img.width = img.height = 200;
				qrLink.appendChild( img );
			}
			wrap.appendChild( qrLink );

			var info = node( 'div', 'nano-unlock__info' );
			if ( c.test ) {
				info.appendChild( node( 'p', 'nano-unlock__test', t.test ) );
			}
			// The QR code and "Open in wallet" fill in the exact amount; they come first.
			info.appendChild( node( 'p', 'nano-unlock__label', t.scan ) );
			var open = node( 'a', 'nano-unlock__open', t.open );
			open.href = c.uri;
			info.appendChild( open );

			// Typed by hand, only the full amount matches: every digit, with the unique tail. Never a rounded one.
			info.appendChild( node( 'p', 'nano-unlock__label nano-unlock__label--exact', t.pay ) );
			var price = node( 'p', 'nano-unlock__price' );
			price.appendChild( node( 'code', 'nano-unlock__amount', 'Ӿ' + c.xno ) );
			price.appendChild( copyButton( c.xno ) );
			info.appendChild( price );
			info.appendChild( node( 'p', 'nano-unlock__usd', '≈ $' + c.usd ) );

			var list = node( 'dl', 'nano-unlock__fields' );
			list.appendChild( node( 'dt', '', t.address ) );
			var dd = node( 'dd' );
			dd.appendChild( node( 'code', '', c.address ) );
			dd.appendChild( copyButton( c.address ) );
			list.appendChild( dd );
			info.appendChild( list );
			info.appendChild( node( 'p', 'nano-unlock__hint', t.exact ) );
			var status = node( 'p', 'nano-unlock__status', t.waiting );
			status.setAttribute( 'role', 'status' );
			status.setAttribute( 'aria-live', 'polite' );
			info.appendChild( status );
			var cancel = node( 'button', 'nano-unlock__cancel', t.cancel );
			cancel.type = 'button';
			cancel.addEventListener( 'click', function () {
				stop();
				forget( item );
				panel.hidden = true;
				panel.textContent = '';
				button.hidden = false;
				button.disabled = false;
			} );
			info.appendChild( cancel );
			wrap.appendChild( info );
			panel.appendChild( wrap );

			var message = t.waiting;
			function left() {
				return c.expiresAt - ( Date.now() + skew ) / 1000;
			}
			function paint() {
				var s = left();
				status.textContent = s > 0 ? message + ' · ' + mmss( s ) + ' ' + t.left : t.expired;
			}
			var retry = node( 'button', 'nano-unlock__retry', t.retry );
			retry.type = 'button';
			retry.hidden = true;
			retry.addEventListener( 'click', function () {
				retry.hidden = true;
				stopped = false;
				message = t.waiting;
				status.textContent = message;
				poll();
			} );
			info.insertBefore( retry, cancel );

			// The payment is recorded but this browser has no receipt yet: keep the checkout, ask again on a click.
			function stuck( text ) {
				stop();
				status.textContent = text;
				retry.hidden = false;
			}

			function unlocked() {
				stop();
				status.textContent = t.paid;
				save( item, { c: c, reloaded: Date.now() } );
				var url = new URL( window.location.href );
				url.searchParams.set( 'nano_unlocked', String( Date.now() ) );
				url.hash = box.id;
				window.location.replace( url.toString() );
			}

			function poll() {
				if ( stopped ) {
					return;
				}
				api( 'claim', { id: c.id } ).then(
					function ( res ) {
						if ( stopped ) {
							return;
						}
						var d = res.data || {};
						if ( res.status === 200 && d.paid ) {
							unlocked();
							return;
						}
						if ( d.code === 'nano_unlock_receipt' ) {
							stuck( d.message || t.kept );
							return;
						}
						if ( res.status === 200 && d.gone ) {
							fail( t.gone );
							return;
						}
						if ( d.code === 'nano_unlock_nonce' || d.code === 'rest_cookie_invalid_nonce' ) {
							// The page is out of date; a reload picks this checkout up again.
							fail( t.reload, true );
							return;
						}
						if ( res.status === 403 || res.status === 404 || res.status === 409 || res.status === 410 ) {
							fail( d.message || t.gone );
							return;
						}
						message = res.status >= 500 ? t.busy : d.pending ? t.pending : t.waiting;
						paint();
						next();
					},
					function () {
						message = t.busy;
						next();
					}
				);
			}
			function next() {
				timer = setTimeout( poll, left() > 0 ? POLL_MS : LATE_POLL_MS );
			}

			if ( reloaded ) {
				paint();
				stuck( t.kept );
				return;
			}
			paint();
			clock = setInterval( paint, 1000 );
			next();
		}
	}

	// A checkout kept from before a reload of this tab, if it can still be paid or claimed.
	function pending( item ) {
		var entry = item ? saved( item ) : null;
		if ( ! entry || ! entry.c || ! entry.c.id ) {
			return null;
		}
		var now = Date.now();
		var over = ( entry.c.expiresAt + ( Number( cfg.late ) || 3600 ) ) * 1000 < now + ( entry.c.skew || 0 );
		var stale = entry.reloaded && now - entry.reloaded > RELOAD_GRACE_MS;
		if ( over || stale ) {
			forget( item );
			return null;
		}
		return entry;
	}

	function setup() {
		var boxes = document.querySelectorAll( '.nano-unlock--locked[data-nano-unlock-offer]' );
		Array.prototype.forEach.call( boxes, function ( box ) {
			var button = box.querySelector( '.nano-unlock__button' );
			if ( ! button ) {
				return;
			}
			button.setAttribute( 'data-label', button.textContent );
			button.addEventListener( 'click', function () {
				start( box, null );
			} );
			var entry = pending( box.getAttribute( 'data-nano-unlock-item' ) );
			if ( entry ) {
				start( box, entry );
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', setup );
	} else {
		setup();
	}
} )();
