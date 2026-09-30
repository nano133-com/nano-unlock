/**
 * Nano Unlock: the reader's checkout.
 *
 * A click on "Unlock" asks this site for a checkout (a unique amount), shows
 * it as a QR code and a nano: link, and asks the site every two seconds
 * whether the payment arrived. When it has, the site sets a receipt cookie
 * and the page reloads with the paid part in it. The paid part is never in
 * this page before that.
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

	function start( box ) {
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

		function fail( message ) {
			stop();
			panel.hidden = false;
			panel.textContent = '';
			panel.appendChild( node( 'p', 'nano-unlock__error', message ) );
			button.disabled = false;
			button.hidden = false;
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
				show( res.data );
			},
			function () {
				button.textContent = button.getAttribute( 'data-label' );
				fail( t.busy );
			}
		);

		function show( c ) {
			var skew = c.now * 1000 - Date.now();
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
				img.alt = t.pay + ' Ӿ' + c.xno + ' ' + t.to + ' ' + c.address;
				img.width = img.height = 200;
				qrLink.appendChild( img );
			}
			wrap.appendChild( qrLink );

			var info = node( 'div', 'nano-unlock__info' );
			info.appendChild( node( 'p', 'nano-unlock__label', t.pay ) );
			var price = node( 'p', 'nano-unlock__price', 'Ӿ' + c.xnoShort );
			price.appendChild( node( 'small', '', ' ≈ $' + c.usd ) );
			info.appendChild( price );
			var open = node( 'a', 'nano-unlock__open', t.open );
			open.href = c.uri;
			info.appendChild( open );

			var list = node( 'dl', 'nano-unlock__fields' );
			[
				[ t.amount, c.xno ],
				[ t.address, c.address ],
			].forEach( function ( row ) {
				list.appendChild( node( 'dt', '', row[ 0 ] ) );
				var dd = node( 'dd' );
				dd.appendChild( node( 'code', '', row[ 1 ] ) );
				dd.appendChild( copyButton( row[ 1 ] ) );
				list.appendChild( dd );
			} );
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
			paint();
			clock = setInterval( paint, 1000 );

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
							stop();
							status.textContent = t.paid;
							var url = new URL( window.location.href );
							url.searchParams.set( 'nano_unlocked', String( Date.now() ) );
							url.hash = box.id;
							window.location.replace( url.toString() );
							return;
						}
						if ( res.status === 200 && d.gone ) {
							fail( t.gone );
							return;
						}
						if ( res.status === 403 ) {
							fail( t.reload );
							return;
						}
						if ( res.status === 404 || res.status === 409 ) {
							fail( d.message || t.gone );
							return;
						}
						message = res.status === 503 ? t.busy : d.pending ? t.pending : t.waiting;
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
			next();
		}
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
				start( box );
			} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', setup );
	} else {
		setup();
	}
} )();
