'use strict';

( function () {
	// Require Vue.js v3
	const Vue = require( "vue" );

	const reconWidgets = document.querySelectorAll(".recon-search-widget");
	reconWidgets.forEach( function(item) {

		var type = item.getAttribute("data-widget-type");
		// All module names (Search, etc.) name provided in index.js
		switch ( type ) {
			case 'sitesearch':
				var App = require("ext.recon.components").Search;
				break;
		}

		if (typeof App !== "undefined") {
			// Now mount with properties derived from data attributes
			const configProps = item.dataset;
			const createdApp = Vue.createMwApp( App, { configProps } );
			createdApp.mount( item );
		}

	});

}() );
