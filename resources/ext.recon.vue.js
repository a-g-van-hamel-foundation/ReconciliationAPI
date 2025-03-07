'use strict';

( function () {
	// Require Vue.js v3
	const Vue = require( "vue" );	
	Vue.configureCompat( {
		MODE: 3
	} );

	const reconWidgets = document.querySelectorAll(".recon-search-widget");
	reconWidgets.forEach( function(item) {

		var type = item.getAttribute( 'data-widget-type' );
		// All module names (Search, etc.) name provided in index.js
		switch ( type ) {
			case 'sitesearch':
				var App = require( 'ext.recon.components' ).Search;
				break;
		}

		// Now mount with properties
		const configProps = item.dataset;
		const createdApp = Vue.createMwApp( App, { configProps } );
		createdApp.mount( item );

	});

}() );
