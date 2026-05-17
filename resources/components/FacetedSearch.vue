<template>
	<div class="recon-faceted-search" ref="wrapper" :style="`scroll-margin-top:` + scrollMarginTop + `; scroll-snap-margin-top:` + scrollMarginTop + `;`">
		<div class="recon-facets">
			<template v-for="facet in facets">
				<facet
					:ref="facet.name"
					:name="facet.name ?? facet.smwproperty"
					:label="facet.label ?? facet.name"
					v-model="query[facet.name]"
					:input-type="facet.inputType ?? 'text'"
					v-model:query="query"
					:api-url="apiUrl"
					:config-data="facet"
					@run-query=submitQuery(0)
				></facet>
			</template>

			<button @click="submitQuery(0)" class="btn-submit">Show results</button>

		</div>
		<div class="recon-results" ref="resultsWrapper">

			<section v-if="debug">
				<div style="margin-bottom:.5rem;">
				<i>Profile: {{ configData.profile }}</i></div>
				<details>
					<summary>Query details</summary>
					<pre>{{ query }}</pre>
					<pre style="color:red; font-size: .7rem;">{{ smwQueryObj }}</pre>
				</details>
				<details v-if="smwQueryResults && smwQueryResults !== null">
					<summary>Query result details</summary>
					<pre style="color:olive">{{ smwQueryResults }}</pre>
				</details>
			</section>

			<!-- Top section with result count -->
			<div class="recon-results-top">
				<div class="recon-result-count">
					<span>{{ resultCount }} <span v-if="resultCount==1">result</span><span v-else>results</span>
					</span>
				</div>
				<!-- Reserved for sorter -->
				<sort-order 
					class="reson-sort"
					v-model:sort="sort"
					:sort-options="sortOptions"
					@update-sort="updateSort"
					v-model:order="order"
					@update-order="updateOrder"
				></sort-order>
			</div>

			<!-- Using Vue to format query results -->
			<span class="loader" v-if="showLoader"></span>
			<template v-if="smwQueryResults && smwQueryResults !== null && !templateResult">
				<faceted-search-result
					v-if="`false`"
					:key="`result-` + smwQueryResultKey"
					:smw-result="smwQueryResults"
					:template="configData.template ?? null"
					:value-sep="valueSep"
				></faceted-search-result>
			</template>
			
			<!-- Query parsed -->
			<div v-if="templateResult"
				v-html="templateResult"
				class="faceted-query-list"
			></div>

			<nav class="recon-pagination-wrapper">
				<pagination
					:key="`pag-` + smwQueryResultKey"
					:total="resultCount"
					:max-pages="maxPages"
					:limit="configData.limit"
					v-model:offset="offset"
					@update-offset="updateOffset"
					@scroll-into-view="scrollIntoView"				
				></pagination>
			</nav>
		</div>
	</div>
</template>

<script>
const { defineComponent, computed, ref, reactive, defineExpose, watch, onMounted, nextTick } = require("vue");
const Facet = require("./Facet.vue");
const FacetedSearchResult = require("./FacetedSearchResult.vue");
const SortOrder = require("./SortOrder.vue");
const Pagination = require("./Pagination.vue");
// const { CdxButton, CdxButtonGroup, CdxToggleButtonGroup, CdxIcon, CdxTabs, CdxTab, CdxTextInput, CdxLookup, CdxField, CdxRadio, CdxCheckbox, CdxSearchInput } = require( "@wikimedia/codex" );

module.exports = defineComponent( {
	name: "FacetedSearch",
	components: {
		Facet,
		FacetedSearchResult,
		SortOrder,
		Pagination
	},
	props: {
		configData: { type: Object, default: {} },
		profile: { type: Object, default: {} }
	},
	setup(props, context) {
		const query = reactive( {} );
		// Not yet used but essential if we want to support dynamic facets:
		const smwQueryObj = reactive( {} );
		// Parameters for #ask 
		const askParams = ref( JSON.parse(props.configData.askParams) );
		const valueSep = ref(";");
		if (askParams.value.valuesep !== undefined) {
			valueSep.value = askParams.value.valuesep;
		}
		const facets = reactive( props.profile?.facets ?? [] );

		const apiUrl = ref( mw.config.get("wgServer") + (mw.config.get("wgScriptPath") || "") + "/api.php" );
		const usesTokens = props.configData?.smwFts || props.configData?.smwElastic;
		const minTokenSize = props.configData?.smwFtsMintokensize ?? 3;
		props.profile?.facets.forEach( (facet) => {
			// Set empty defaults
			// k MUST be unique; use 'name' if same smwproperty is used more than once
			var k = facet.name ?? facet.smwproperty;
			query[k] = facet.inputType == "multiselect" ? [] : "";
		} );

		// sort and order
		const sort = ref("");
		if (typeof props.configData.sort !== "undefined" ) {
			sort.value = props.configData.sort;
		}
		const sortOptions = ref( [] );
		if ( props.profile?.sort !== undefined ) {
			sortOptions.value = props.profile?.sort;
		}
		function updateSort(n) {
			// console.log("updateSort", n);
			submitQuery(0, n, order.value );
		}
		const order = ref("asc");
		if (typeof props.configData.order !== "undefined" ) {
			order.value = props.configData.order;
		};
		function updateOrder(n) {
			submitQuery(offset.value, sort.value, n);
		}

		// offset
		const offset = ref(0);
		function updateOffset(n) {
			// submitQuery will adjust the offset
			submitQuery(n);
		}
		const maxPages = Number( props.configData.maxpages ?? 5 );

		const smwPrintoutProps = reactive( props.profile?.printout?.properties ?? [] );
		const smwQueryResults = ref( {} );
		const smwQueryResultKey = ref( "" );

		const templateResult = ref( null );
		const showLoader = ref( false );
		function submitQuery(newOffset, newSort, newOrder) {
			// offset, sort and order
			offset.value = newOffset ?? 0;
			smwQueryResults.value = null;
			showLoader.value = true;
			// query, apiUrl
			if (newSort !== undefined) {
				sort.value = newSort;
			}
			if (newOrder !== undefined) {
				order.value = newOrder;
			}

			// Build the query, to be completed later
			var smwQuery = buildQuery();

			// Do result count separately
			setResultCount( smwQuery );

			// Transfer any additional #ask parameters from the config
			//var askParams = JSON.parse( props.configData.askParams );
			if ( typeof askParams.value !== "undefined" ) {
				for (const [k,v] of Object.entries(askParams.value)) {
					smwQuery += `|${k}=${v} `;
				}
			}

			/* Three 'output' types:
			 * "ask" - an #ask query is parsed
			 * "template" - a template that receives the query is parsed
			 * "basic" - uses child component for basic presentation
			*/
			var output = props.configData.output;

			// Run the query
			if ( output == "ask" ) {
				var format = props.configData.resultFormat
				?? ( props.configData.template ? "plainlist" : null );

				var askPF = createAskPF(format, smwQuery);
				console.log( "#ask", askPF );

				showLoader.value = true;

				new mw.Api().parse( askPF )
				.done(function(rawData) {
					templateResult.value = rawData;
					showLoader.value = false;
					handleModulesForApiResponse(format);
				})
				.fail(function() {
					showLoader.value = false;
					console.error("Parsing failed...");
				});
			} else if(output == "template" && props.configData.template ) {
				var tpl = `{{${props.configData.template} |query=${smwQuery} }}`;
				console.log( "template", tpl);
				new mw.Api().parse( tpl )
				.done( (rawData) => {
					templateResult.value = rawData;
					showLoader.value = false;
					if ( props.configData.resultFormats !== "" ) {
						props.configData.resultFormats.split(",").forEach( (f) => {
							handleModulesForApiResponse(f);
						});
					}
				})
				.fail(function() {
					showLoader.value = false;
					console.error("Parsing of the template failed...");
				});
			} else {
				// Basic. Get results from SMW's ask API
				// and do whatever
				const smwAskApi = new mw.ForeignApi( apiUrl.value, { anonymous: false } );
				const smwAskParams = {
					action: "ask",
					format: "json",
					formatversion: "2",
					query: smwQuery
				};
				smwAskApi.post(smwAskParams)
				.done( function(data) {
					showLoader.value = false;
					if ( data.query == undefined ) {
						return;
					}
					if ( typeof data.query?.results == "object" ) {
						smwQueryResults.value = data.query?.results;
					}
					// Messes up pagination because the key is used to trigger the child component's reactivity, which is needed when the same query is run again with a different offset - but it also means that the results are reset when the query changes, which is not ideal.
					// smwQueryResultKey.value = getTimestamp();
					// ...
				} )
				.fail(function() {
					showLoader.value = false;
					console.error("Query failed...");
					smwQueryResults.value = {};
					smwQueryResultKey.value = getTimestamp();
				});
			}
		}

		function createAskPF(format, smwQuery) {
			if ( format == "plainlist" && props.configData.template ) {
				var askPF = `{{#ask: ${smwQuery} |format=${format} |template=${props.configData.template ?? ""} |link=none |?=Page |namedargs=true |searchlabel= |valuesep=${valueSep.value} }}`;
			} else if(format == "table" || format == "broadtable") {
				// Just because searchlabel= 
				var askPF = `{{#ask: ${smwQuery} |format=${format} |valuesep=${valueSep.value} |searchlabel= }}`;
			} else {
				var askPF = `{{#ask: ${smwQuery} 
				|format=${format} 
				|valuesep=${valueSep.value} 
				|template=${props.configData.template ?? ""} }}`;
			}
			return askPF;
		}

		/**
		 * Handles ResourceLoader modules for result formats that rely on those modules to function properly (e.g. datatables, gallery).
		 * Uses nextTick() to ensure that the DOM is updated first.
		 * Uses mw.hook 'wikipage.content' to enforce enhancement of the new content after modules are loaded.
		 * A bit hacky but seems to be the only way to ensure that the relevant module is loaded and the content rendered after receiving the API response.
		 * Not yet working for 'datatables'; may be in part for 'gallery'
		 */
		async function handleModulesForApiResponse(format) {
			await nextTick();

			// Define modules required for each format
			var modules = null;
			switch(format) {
				case "datatables":
					var modules = ["ext.srf.datatables.v2.format"];
					/*
					// datatables:
					var datatablesContainer = resultsWrapper.value.querySelector(".datatables-container");
					// ...
					*/
				break;
				case "gallery":
					// (1) Gallery format
					var modules = ["mediawiki.page.gallery.styles"];
					var widget = askParams.value.widget ?? "overlay";
					switch(widget) {
						case "overlay":
							modules.push( "ext.srf.gallery.overlay" );
						break;
						case "carousel":
							// Relies on MMV
							modules.push( "ext.srf.gallery.carousel" );
						break;
						case "slideshow":
							modules.push( "ext.srf.gallery.slideshow" );
						break;
					}
					// (2) MultimediaViewer not working reliably. Takes multiple clicks (or escapes) to close the modal.
					//modules.push( "mmv", "mmv.bootstrap", "mmv.ui.reuse");
				break;
				case "iiif-annotation-gallery":
					// Works
					var modules = ["ext.iiif.styles", "ext.iiif.resultformat.annotationgallery" ];
				break;
				case "iiif-canvas-viewer":
					// Issue with Ace editor?
					//"ext.iiif.lib.ace","ext.iiif.lib.ace.utils"
					var modules = [ "ext.iiif.resultformat.canvasviewer", "ext.iiif.styles" ];
					//"ext.iiif.lib.ace", "ext.iiif.lib.ace.utils" 
					//modules.push([ "ext.iiif.lib.ace", "ext.iiif.lib.ace.utils" ]);
				break;
				case "leaflet":
					var modules = [ "ext.maps.leaflet.loader" ];
				break;
			}

			if ( modules !== null ) {
				modules.forEach( (mod) => {
					// Check module state before loading
					var state = mw.loader.getState(mod);

					// Abort if unregistered
					if ( mw.loader.getState(mod) === null ) {
						console.warn( `Module ${mod} is not registered in ResourceLoader.` );
						return;
					}

					// Works only for modules that allow for dynamically added content
					mw.hook("recon-faceted-fire-module").fire( mod, $(resultsWrapper.value) );
					/* OLD:
					mw.loader.using( mod ).then( function () {
						mw.hook( 'wikipage.content' ).fire( $(resultsWrapper.value) );
					} );
					*/
				} );
			}
		}

		// Transform query elements to syntax required for API
		// smwQuery, smwQueryObj, COUNT?
		function buildQuery() {
			// set/reset smwQuery and smwQueryObject to initial
			var smwQuery = props.profile?.baseQuery ?? "";
			for (var k in smwQueryObj) delete smwQueryObj[k];
			smwQueryObj["baseQuery"] = smwQuery;

			props.profile?.facets.forEach( (facet) => {
				var k = facet.name ?? facet.smwproperty;
				if ( query[k] == undefined || query[k] == null || query[k] == "" || query[k] == [] ) {
					return;
				}

				switch(facet.inputType) {
					case "select":
					case "lookup":
						// = codex select or lookup
						smwQueryObj[k] = "";
						/*if ( facet.subquery !== undefined ) {
							// work in progress; API only
							var newQ = `[[${facet.smwproperty}::` + `<q>` + facet.subquery.replaceAll( "@@@", query[k] ) + `</q>` + `]]`;
							console.log( "test Q", newQ);
							smwQuery += newQ + ` `;
							smwQueryObj[k] = newQ;
						}*/
						if ( facet.options !== undefined ) {
							var option = facet.options.find( (opt) => opt['value'] == query[k] );
							var newQ = assignToProperty( facet.smwproperty, option.value, facet.subquery );
							smwQuery += newQ;
							smwQueryObj[k] = newQ;
						} else if ( facet.mapOptions !== undefined ) {	
							var mapOption = facet.mapOptions.find( (opt) => opt['option'] == query[k] );
							//console.log( "mapOption", mapOption );
							smwQuery += mapOption.where;
							smwQueryObj[k] = mapOption.where;
						} else {
							// API
							var newQ = assignToProperty( facet.smwproperty, query[k], facet.subquery, facet );
							smwQuery += newQ + ` `;
							smwQueryObj[k] = newQ;
						}
					break;
					case "multiselect":
						smwQueryObj[k] = [];
						if ( facet.options !== undefined ) {
							// No need to check I think
						}
						query[k].forEach( (v) => {
							var newQ = assignToProperty( facet.smwproperty, v, facet.subquery );
							smwQuery += newQ;
							smwQueryObj[k].push(newQ);
						});
					break;
					case "text":
						var substr = sanitiseString(query[k]);
						if ( typeof facet.smwproperty !== "undefined" ) {
							// @todo - no comprehensive checks yet
							var q = ``;
							switch( facet.smwpropertyMatch ?? "tokenprefix" ) {
								// 'contains'?
								case "tokenprefix":
									var q = getReplacementString(substr, facet.smwproperty, usesTokens, minTokenSize);
								break;
								case "exact":
									var q = `[[${facet.smwproperty}::${substr}]]`;
								break;
							}
							smwQuery += q + ` `;
							smwQueryObj[k] = q;
						} else {
							// Assuming single-page restriction
							var q = ``;
							switch( facet.smwpropertyMatch ?? "tokenprefix" ) {
								// 'contains'?
								case "tokenprefix":
									var q = `[[~${substr}*]]`;
								break;
								case "exact":
									var q = `[[${substr}]]`;
								break;
							}
							smwQuery += q + ` `;
							smwQueryObj[k] = q;
						}
					break;
				}
			} );

			smwPrintoutProps.forEach( (prop) => {
				smwQuery += `|?${prop} `;
			} );

			// options (limit, offset, sort, order)
			var options = {
				limit: props.configData.limit,
				offset: offset.value,
				sort: sort.value,
				order: order.value
			};
			for (const [k,v] of Object.entries(options)) {
				if ( v !== null ) {
					smwQuery += `|${k}=${v}`;
				}
			}

			return smwQuery;
		}

		// Helper function for buildQuery
		function assignToProperty( propertyName, selectedValue, subQuery ) {
			if ( typeof subQuery == "undefined" ) {
				var newQ = `[[${propertyName}::${selectedValue}]]`;
			} else {
				var newQ = `[[${propertyName}::` + `<q>` + subQuery.replaceAll( "@@@", selectedValue ) + `</q>` + `]]`;
			}
			return newQ;
		}

		function sanitiseString( substr ) {
			return substr
				.replaceAll(/[^a-z0-9áéíóúñü \.,_-]/gim, "")
				.replaceAll( /\*|\+|-/g, "" )
				.trim();
		}

		function getReplacementString( substr, property, usesTokens, minTokenSize ) {
			var newStr = "";
			var strings = substr.split( " " );

			if( usesTokens ) {
				// Token-based (FTS or Elasticsearch)
				strings.forEach( (str) => {
					// https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/String/length
					// @dev note: use Intl.Segmenter when widely available
					var count = [...str].length;
					if ( count >= minTokenSize ) {
						newStr += `+${str}* `;
					} else {
						// @todo: ${str} vs ${str}* ?
						console.log("small token size");
						newStr += `${str} `;
					}
				} );
				return `[[${property}::~` + newStr.trim() + `]]`;
			} else {
				strings.forEach( (str) => {
					newStr += `[[${property}::~${str}*]] `;
				} );
				return newStr.trim();
			}
		}

		const resultCount = ref( 0 );
		function setResultCount(smwQuery) {
			// Parsing a query to get the total result count is
			// a bit of a hack, sadly, but the API (still) does 
			// not return this info.
			var countQuery = `{{#ask: ${smwQuery} |format=count }}`;
			//console.log( "countQuery", countQuery);
			new mw.Api().parse( countQuery )
			.done(function(rawData) {
				// strip html from the result
				var tmp = document.createElement("DIV");
   				tmp.innerHTML = rawData;
				resultCount.value = Number(tmp.textContent||tmp.innerText);
				// enforce..
				smwQueryResultKey.value = getTimestamp();
				//console.log( "count", resultCount.value );
			});
		}

		// Get timestamp to enforce child component's reactivity
		function getTimestamp() {
			var today = new Date();
			return today.getDay() + today.getHours() + ":" + today.getMinutes() + ":" + today.getSeconds();
		}

		const wrapper = ref(null);
		const resultsWrapper = ref(null);
		defineExpose({ wrapper, resultsWrapper });
		const scrollMarginTop = ref("0px");
		if ( typeof props.configData?.scrollmargintop !== "undefined" ) {
			scrollMarginTop.value = props.configData.scrollmargintop;
		}
		function scrollIntoView() {
			wrapper.value.scrollIntoView({ behavior: "smooth" });
		}

		const debug = ref( false );
		if( typeof props.configData.debug !== "undefined" && props.configData.debug == "true" ) {
			debug.value = true;
		}

		return {
			templateResult,

			askParams,
			valueSep,

			facets,
			apiUrl,

			query,
			smwQueryObj,
			smwQueryResults,
			smwQueryResultKey,
			showLoader,
			submitQuery,
			offset,
			updateOffset,
			resultCount,
			maxPages,

			sort,
			sortOptions,
			updateSort,
			order,
			updateOrder,

			resultsWrapper,

			getTimestamp,
			wrapper,
			scrollMarginTop,
			scrollIntoView,
			debug
		}
	}
} );
</script>

<style lang="less">
.recon-faceted-search {
	display: grid;
	grid-template-columns: 20rem auto;
	min-height: 15rem;
	.recon-facets {
		padding: 1rem;
		background-color: #e0e2df;
		/* ... */
	}
	.recon-results {
		padding: 1rem;
		/* ... */
	}
}
@media (max-width: 768px) {
	.recon-faceted-search {
		grid-template-columns: auto;
	}
}

.btn-submit {
	background: #5f7470 linear-gradient(180deg,#778985,#5f7470) repeat-x;
	border: 1px solid #5f7470;
	border-radius: 2px;
	box-shadow: inset 0 1px 0 rgba(255,255,255,0.15),0 1px 1px rgba(0,0,0,0.075);
	color: #fff;
	padding: 0.4rem 12px;
	width: 100%;
	transition-property: background-color,color,border-color,box-shadow;
	transition-duration: 100ms;
	line-height: 1;
	cursor: pointer;
	text-decoration: none;

	&:hover {
		background: #4e5f5c linear-gradient(180deg,#687774,#4e5f5c) repeat-x;
		border-color: #485855;
	}
}

.recon-results-top {
	display: flex;
	justify-content: space-between;
	margin-bottom: .5rem;
	border-left: 1px solid #5A7179;
	background-color: #f8f8f8;
	label {
		padding: 4px 0;
	}
	.recon-result-count {
		display: flex;
		justify-content: start;
		font-size: .8rem;
		& > span {
			padding: 4px 8px;
		}
	}
	.recon-sort {
		display: flex;
		justify-content: end;
		gap: .5rem;
		align-items: normal;
		font-size: .8rem;
		.cdx-select-vue__handle {
			min-width: 125px;
			min-height: 30px;
			line-height: inherit;
		}
		.recon-order {
			width:2rem;
			max-width:2rem;
		}
		.cdx-button {
			font-size: .8rem;
			min-height: 30px;
		}
	}
}

.cdx-input-chip {
	border-radius: .5rem;
}
/* May not fit otherwise */
.cdx-input-chip__text {
  white-space: wrap;
}

/* Fix for min-width/sudden flicker in codex select */
.cdx-select-vue {
	width:100%;
}
.cdx-select-vue__handle {
	min-width:200px;
}
.cdx-chip-input__chips {
	min-width:200px;
}

</style>
