"use strict";

( function() {
	const Vue = require("vue");
	const Vuex = require("vuex");
	const isAnon = mw.user.isAnon();

	function initApp( App, item, configData ) {
		if ( configData.profileId == undefined ) {
			return;
		}
		const actionApiBaseUrl = mw.config.get("wgServer") + (mw.config.get("wgScriptPath") || "") + "/api.php";
		const actionApi = new mw.ForeignApi( actionApiBaseUrl, { anonymous: isAnon } );
		let profileData = ( configData.profileId !== null )
			? actionApi.post({
				action: "iiif-wiki",
				formatversion: "2",
				id: configData.profileId ?? 0
		}).fail( function(xhr, status, error) {
				return {};
		})
		: {};

		const promises = [ profileData ];
		Promise.all(promises).then( (results) => {
			const profile = results[0];
			const createdApp = Vue.createMwApp( App, {
				configData,
				profile
			});
			createdApp.use(Vuex);
			createdApp.mount(item);
		});
	}

	const widgets = document.querySelectorAll(".recon-faceted-search-widget");
	widgets.forEach( function(item) {
		const configData = item.dataset;
		var App = require( "ext.recon.facetedsearch.components" ).FacetedSearch;
		initApp( App, item, configData );
	});

}() );
