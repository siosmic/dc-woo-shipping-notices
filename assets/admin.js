( function () {
	'use strict';

	var countrySel = document.getElementById( 'dcsn-countries' );
	var statesRow = document.getElementById( 'dcsn-states-row' );
	var modeSel = document.getElementById( 'dcsn-mode' );
	var noticeTypeRow = document.getElementById( 'dcsn-notice-type-row' );

	if ( countrySel && statesRow ) {
		countrySel.addEventListener( 'change', function () {
			var selectedCountries = Array.from( this.selectedOptions ).map( function ( option ) {
				return option.value;
			} );

			statesRow.style.display = selectedCountries.indexOf( 'US' ) !== -1 ? '' : 'none';
		} );
	}

	if ( modeSel && noticeTypeRow ) {
		modeSel.addEventListener( 'change', function () {
			noticeTypeRow.style.display = this.value === 'BLOCK_WITH_MESSAGE' ? 'none' : '';
		} );
	}
}() );
