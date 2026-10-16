/**
 * Teatatu Events Theme — progressive enhancement only. Every feature here
 * works without JavaScript (forms submit, links navigate); this just makes
 * them nicer.
 */
( function () {
	var data = window.ttevTheme || {};

	// Front page tabs (Next 7 days / This weekend).
	document.querySelectorAll( '[data-ttev-tabs]' ).forEach( function ( list ) {
		var tabs = Array.prototype.slice.call( list.querySelectorAll( '[role="tab"]' ) );
		function select( tab ) {
			tabs.forEach( function ( t ) {
				var on = t === tab;
				t.setAttribute( 'aria-selected', on ? 'true' : 'false' );
				t.tabIndex = on ? 0 : -1;
				document.getElementById( t.getAttribute( 'aria-controls' ) ).hidden = ! on;
			} );
		}
		tabs.forEach( function ( tab, i ) {
			tab.addEventListener( 'click', function () {
				select( tab );
			} );
			tab.addEventListener( 'keydown', function ( e ) {
				if ( 'ArrowRight' !== e.key && 'ArrowLeft' !== e.key ) {
					return;
				}
				var next = tabs[ ( i + ( 'ArrowRight' === e.key ? 1 : tabs.length - 1 ) ) % tabs.length ];
				next.focus();
				select( next );
			} );
		} );
		list.hidden = false;
		select( tabs[ 0 ] );
	} );

	// Share: native share sheet, else copy the link.
	document.querySelectorAll( '[data-ttev-share]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var url = btn.getAttribute( 'data-ttev-share' );
			if ( navigator.share ) {
				navigator.share( { title: document.title, url: url } ).catch( function () {} );
			} else if ( navigator.clipboard ) {
				navigator.clipboard.writeText( url ).then( function () {
					btn.setAttribute( 'aria-label', data.copied || 'Link copied' );
					btn.title = data.copied || 'Link copied';
				} );
			}
		} );
	} );
} )();
