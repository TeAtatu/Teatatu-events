/**
 * Draws venue maps with Leaflet and OpenStreetMap tiles. Points come from
 * the plugin's venue coordinates; events at one venue share a pin.
 */
( function () {
	if ( ! window.L ) {
		return;
	}
	document.querySelectorAll( '.ttev-map[data-points]' ).forEach( function ( el ) {
		var points;
		try {
			points = JSON.parse( el.getAttribute( 'data-points' ) );
		} catch ( e ) {
			return;
		}
		if ( ! points.length ) {
			return;
		}
		var map = L.map( el, { scrollWheelZoom: false } );
		L.tileLayer( 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
			maxZoom: 19,
			attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
		} ).addTo( map );
		var bounds = [];
		points.forEach( function ( p ) {
			var label = document.createElement( 'a' );
			label.className = 'ttev-map-pin';
			label.href = p.url;
			label.textContent = p.label;
			label.title = p.title;
			L.marker( [ p.lat, p.lng ], {
				icon: L.divIcon( { className: '', html: label.outerHTML, iconSize: null } ),
				title: p.title,
				alt: p.title
			} ).addTo( map );
			bounds.push( [ p.lat, p.lng ] );
		} );
		if ( 1 === bounds.length ) {
			map.setView( bounds[ 0 ], 16 );
		} else {
			map.fitBounds( bounds, { padding: [ 40, 40 ], maxZoom: 16 } );
		}
	} );
} )();
