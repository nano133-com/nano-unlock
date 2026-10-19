/**
 * Nano Unlock: the reader's checkout.
 *
 * A click on "Unlock" asks this site for a checkout (an amount of its own,
 * at most four decimals), shows it as a QR code, one line ("Pay $0.05 ·
 * Ӿ0.1512") and an "Open in wallet" key, and asks the site every two seconds
 * whether the payment arrived. One status line says where it is. The address,
 * the amount with Copy, the expiry and one sentence of help are under
 * "Details", closed at first. When the payment has arrived, the site sets a
 * receipt cookie and the page reloads with the paid part in it. The paid part
 * is never in this page before that.
 *
 * The open checkout is kept in sessionStorage (per item), so a reload of
 * this tab picks it up again instead of losing a payment in flight. When the
 * site answers that the payment is recorded but the receipt cookie could not
 * be set, the checkout stays, with a "Try again" button. Cancel asks the site
 * to end the checkout (its amount is then free for the next reader); when the
 * payment is already there, the site unlocks instead.
 *
 * On a site with a test node, the card is stamped "TEST MODE: do not pay":
 * the QR code holds only those words, and the wallet and copy keys are off.
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

	function fill( template, a, b ) {
		return template.replace( '%1$s', a ).replace( '%2$s', b );
	}

	// Dollars always show cents: "$0.10", never "$0.1" (an older checkout kept in this tab may say "0.1").
	function dollars( usd ) {
		var s = String( usd === undefined || usd === null ? '' : usd ).replace( /^\$/, '' );
		var m = /^(\d+)(?:\.(\d*))?$/.exec( s );
		if ( ! m ) {
			return '$' + s;
		}
		var cents = ( m[ 2 ] || '' ).replace( /0+$/, '' );
		while ( cents.length < 2 ) {
			cents += '0';
		}
		return '$' + m[ 1 ] + '.' + cents;
	}

	function writeClipboard( value ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( value );
		}
		// A site without https has no clipboard API: copy through a selected, unseen text field.
		return new Promise( function ( resolve, reject ) {
			var field = node( 'textarea' );
			field.value = value;
			field.setAttribute( 'readonly', '' );
			field.style.position = 'fixed';
			field.style.opacity = '0';
			document.body.appendChild( field );
			field.select();
			var ok = false;
			try {
				ok = document.execCommand( 'copy' );
			} catch ( e ) {}
			document.body.removeChild( field );
			if ( ok ) {
				resolve();
			} else {
				reject();
			}
		} );
	}

	function copyButton( value, what, off ) {
		var b = node( 'button', 'nano-unlock__copy', t.copy );
		b.type = 'button';
		b.setAttribute( 'aria-label', t.copy + ': ' + what );
		if ( off ) {
			b.disabled = true;
			return b;
		}
		b.addEventListener( 'click', function () {
			writeClipboard( value ).then(
				function () {
					b.textContent = t.copied;
					b.setAttribute( 'aria-label', t.copied + ': ' + what );
					setTimeout( function () {
						b.textContent = t.copy;
						b.setAttribute( 'aria-label', t.copy + ': ' + what );
					}, 1500 );
				},
				function () {}
			);
		} );
		return b;
	}

	function mmss( s ) {
		s = Math.max( 0, Math.floor( s ) );
		var m = Math.floor( s / 60 );
		var r = s % 60;
		return m + ':' + ( r < 10 ? '0' : '' ) + r;
	}

	// The QR code as an image. In test mode it holds only the stamp's words, never a payment.
	function qrImage( text ) {
		if ( ! window.qrcode ) {
			return null;
		}
		var qr = window.qrcode( 0, 'M' );
		qr.addData( text );
		qr.make();
		var img = node( 'img' );
		img.src = qr.createDataURL( 6, 2 );
		img.alt = '';
		img.width = img.height = 200;
		return img;
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

		function close() {
			stop();
			forget( item );
			box.classList.remove( 'nano-unlock--open' );
			panel.hidden = true;
			panel.textContent = '';
			button.hidden = false;
			button.disabled = false;
		}

		// The checkout is over: show why, and offer a new one. keep: a reload may still pick it up.
		function fail( message, keep ) {
			stop();
			if ( ! keep ) {
				forget( item );
			}
			box.classList.remove( 'nano-unlock--open' );
			panel.hidden = false;
			panel.textContent = '';
			var p = node( 'p', 'nano-unlock__error', message );
			p.setAttribute( 'role', 'alert' );
			panel.appendChild( p );
			button.disabled = false;
			button.hidden = false;
		}

		if ( resume ) {
			show( resume.c, !! resume.reloaded, false );
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
				show( res.data, false, true );
			},
			function () {
				button.textContent = button.getAttribute( 'data-label' );
				fail( t.busy );
			}
		);

		// reloaded: the page already reloaded for this paid checkout, and the box is still locked.
		// focus: move the keyboard focus to the card (it replaces the button that had it).
		function show( c, reloaded, focus ) {
			var skew = c.skew || 0;
			var test = !! c.test;
			var price = dollars( c.usd );
			var xno = 'Ӿ' + c.xno;
			button.hidden = true;
			box.classList.add( 'nano-unlock--open' );
			panel.hidden = false;
			panel.textContent = '';

			// In test mode the box above already says "TEST MODE" (from the server); the QR code gets a stamp.
			var card = node( 'div', 'nano-unlock__card' + ( test ? ' nano-unlock__card--test' : '' ) );

			// The QR code: the main thing on a computer. A tap on it opens the wallet too (the key below is
			// the same link for keyboards and screen readers, so this one is hidden from them).
			var qr = node( test ? 'div' : 'a', 'nano-unlock__qr' );
			qr.setAttribute( 'aria-hidden', 'true' );
			var img = qrImage( test ? t.stamp : c.uri );
			if ( img ) {
				qr.appendChild( img );
			}
			if ( test ) {
				qr.appendChild( node( 'span', 'nano-unlock__stamp', t.stamp ) );
			} else {
				qr.href = c.uri;
				qr.tabIndex = -1;
			}
			card.appendChild( qr );

			var side = node( 'div', 'nano-unlock__side' );
			// One line: what to pay, in dollars and in XNO. Read out as "Pay $0.05: 0.1512 XNO".
			var line = node( 'p', 'nano-unlock__line' );
			line.tabIndex = -1;
			var seen = node( 'span', '', fill( t.pay, price, xno ) );
			seen.setAttribute( 'aria-hidden', 'true' );
			line.appendChild( seen );
			line.appendChild( node( 'span', 'nano-unlock__sr', fill( t.paySr, price, c.xno ) ) );
			side.appendChild( line );

			// The wallet key: the main key on a phone. Off in test mode.
			var open;
			if ( test ) {
				open = node( 'button', 'nano-unlock__open', t.open );
				open.type = 'button';
				open.disabled = true;
			} else {
				open = node( 'a', 'nano-unlock__open', t.open );
				open.href = c.uri;
			}
			side.appendChild( open );

			// The one status line. Only its words are a live region, so a screen reader hears each change once.
			var status = node( 'p', 'nano-unlock__status' );
			status.appendChild( node( 'span', 'nano-unlock__dot' ) ).setAttribute( 'aria-hidden', 'true' );
			var said = node( 'span', 'nano-unlock__said', t.waiting );
			said.setAttribute( 'role', 'status' );
			said.setAttribute( 'aria-live', 'polite' );
			status.appendChild( said );
			side.appendChild( status );

			var retry = node( 'button', 'nano-unlock__retry', t.retry );
			retry.type = 'button';
			retry.hidden = true;
			side.appendChild( retry );

			// Details (closed at first) and Cancel.
			var bar = node( 'div', 'nano-unlock__bar' );
			var more = node( 'button', 'nano-unlock__more', t.details );
			more.type = 'button';
			var detailsId = ( box.id || 'nano-unlock' ) + '-details';
			more.setAttribute( 'aria-expanded', 'false' );
			more.setAttribute( 'aria-controls', detailsId );
			var cancel = node( 'button', 'nano-unlock__cancel', t.cancel );
			cancel.type = 'button';
			bar.appendChild( more );
			bar.appendChild( cancel );
			side.appendChild( bar );

			var details = node( 'div', 'nano-unlock__details' );
			details.id = detailsId;
			details.hidden = true;
			var list = node( 'dl', 'nano-unlock__fields' );
			function field( label, value, copy ) {
				var row = node( 'div', 'nano-unlock__field' );
				row.appendChild( node( 'dt', '', label ) );
				var dd = node( 'dd' );
				dd.appendChild( value );
				if ( copy ) {
					dd.appendChild( copy );
				}
				row.appendChild( dd );
				list.appendChild( row );
				return dd;
			}
			field( t.address, node( 'code', 'nano-unlock__address', c.address ), copyButton( c.address, t.address, test ) );
			field( t.amount, node( 'code', 'nano-unlock__amount', xno ), copyButton( c.xno, t.amount, test ) );
			var leftText = node( 'span', 'nano-unlock__left' );
			field( t.expires, leftText, null );
			details.appendChild( list );
			details.appendChild( node( 'p', 'nano-unlock__hint', t.explain ) );
			side.appendChild( details );

			more.addEventListener( 'click', function () {
				var opening = details.hidden;
				details.hidden = ! opening;
				more.setAttribute( 'aria-expanded', opening ? 'true' : 'false' );
			} );

			card.appendChild( side );
			panel.appendChild( card );
			if ( focus ) {
				line.focus( { preventScroll: true } );
			}

			var message = t.waiting;
			var state = 'waiting';
			function left() {
				return c.expiresAt - ( Date.now() + skew ) / 1000;
			}
			function say( text, kind ) {
				message = text;
				state = kind;
				paint();
			}
			function paint() {
				var s = left();
				var text = 'waiting' === state && s <= 0 ? t.expired : message;
				// Only a real change reaches the live region.
				if ( said.textContent !== text ) {
					said.textContent = text;
				}
				status.setAttribute( 'data-state', 'waiting' === state && s <= 0 ? 'expired' : state );
				leftText.textContent = s > 0 ? mmss( s ) : t.over;
			}

			retry.addEventListener( 'click', function () {
				retry.hidden = true;
				stopped = false;
				say( t.waiting, 'waiting' );
				poll();
			} );

			cancel.addEventListener( 'click', function () {
				cancel.disabled = true;
				stop();
				api( 'cancel', { id: c.id } ).then(
					function ( res ) {
						var d = res.data || {};
						if ( res.status === 200 && d.paid ) {
							unlocked();
							return;
						}
						if ( res.status === 200 && d.pending ) {
							// The payment is already on its way: keep the checkout.
							cancel.disabled = false;
							stopped = false;
							say( t.pending, 'pending' );
							clock = setInterval( paint, 1000 );
							next();
							return;
						}
						close();
						button.focus();
					},
					function () {
						close();
						button.focus();
					}
				);
			} );

			// The payment is recorded but this browser has no receipt yet: keep the checkout, ask again on a click.
			function stuck( text ) {
				stop();
				say( text, 'stuck' );
				retry.hidden = false;
			}

			function unlocked() {
				stop();
				say( t.paid, 'paid' );
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
						if ( res.status >= 500 ) {
							say( t.busy, 'busy' );
						} else if ( d.pending ) {
							say( t.pending, 'pending' );
						} else {
							say( t.waiting, 'waiting' );
						}
						next();
					},
					function () {
						say( t.busy, 'busy' );
						next();
					}
				);
			}
			function next() {
				clearTimeout( timer );
				timer = setTimeout( poll, left() > 0 ? POLL_MS : LATE_POLL_MS );
			}

			paint();
			clock = setInterval( paint, 1000 );
			if ( reloaded ) {
				stuck( t.kept );
				return;
			}
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
