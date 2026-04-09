"use strict";

( function() {
	const Vue = require("vue");
	const Vuex = require("vuex");
	const isAnon = mw.user.isAnon();

	function initTestBench( App, item, configData ) {
		const createdApp = Vue.createMwApp( App, {
			configData
		});
		createdApp.use(Vuex);
		createdApp.mount(item);
	}

	const widgets = document.querySelectorAll(".recon-testbench");
	widgets.forEach( function(item) {
		const configData = item.dataset;
		var App = require( "ext.recon.testbench.components" ).TestBench;
		initTestBench( App, item, configData );
	});

}() );
