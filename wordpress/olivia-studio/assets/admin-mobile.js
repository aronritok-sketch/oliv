/**
 * Studio admin on a phone (Áron, 16 November: „Az admint optimalizáld le teljesen telefonra”): every table gets its
 * column names on its cells, so the stylesheet can show each row as a card with labelled lines under 783 px.
 */
( function () {
	'use strict';
	document.querySelectorAll( '.oys-wrap table.widefat, .oys-wrap table.oys-table, .wrap table.widefat' ).forEach( function ( table ) {
		var heads = Array.prototype.map.call( table.querySelectorAll( 'thead th' ), function ( th ) {
			return ( th.textContent || '' ).trim();
		} );
		if ( ! heads.length ) {
			return;
		}
		table.classList.add( 'oys-cardtable' );
		table.querySelectorAll( 'tbody tr' ).forEach( function ( tr ) {
			var i = 0;
			Array.prototype.forEach.call( tr.children, function ( td ) {
				var span = parseInt( td.getAttribute( 'colspan' ) || '1', 10 );
				var label = heads[ i ] || '';
				if ( label && ! td.hasAttribute( 'data-label' ) ) {
					td.setAttribute( 'data-label', label );
				}
				if ( ! label || span > 1 ) {
					td.classList.add( 'oys-cell--wide' );
				}
				i += span;
			} );
		} );
	} );
} )();
