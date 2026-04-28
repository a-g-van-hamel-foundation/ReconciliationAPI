<template>
	<div class="recon-faceted-search">
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
				></facet>
			</template>

			<button @click="submitQuery(0)" class="btn-submit">Show results</button>

		</div>
		<div class="recon-results">
			<details v-if="debug">
				<div style="margin-bottom:.5rem;"><i>Profile: {{ configData.profile }}</i></div>
				<summary>Query details</summary>
				<pre>{{ query }}</pre>
				<pre style="color:red; font-size: .7rem;">{{ smwQueryObj }}</pre>
			</details>

			<span class="loader" v-if="showLoader"></span>
			<template v-if="smwQueryResults && smwQueryResults !== null">
				<div class="recon-result-count">
					<span>{{ resultCount }} <span v-if="resultCount==1">result</span><span v-else>results</span>
					</span>
				</div>
				<faceted-search-result
					:key="smwQueryResultKey"
					:smw-result="smwQueryResults"
					:template="configData.template ?? null"
					:value-sep="configData.valueSep"
					:debug="debug"
				></faceted-search-result>
			</template>

			<pagination
				:key="`pag-` + smwQueryResultKey"
				:total="resultCount"
				:limit="configData.limit"
				v-model:offset="offset"
				@update-offset="updateOffset"
				:max-pages="maxPages"
			></pagination>

		</div>
	</div>
</template>

<script>
const { defineComponent, computed, ref, reactive, watch } = require("vue");
const Facet = require("./Facet.vue");
const FacetedSearchResult = require("./FacetedSearchResult.vue");
const Pagination = require("./Pagination.vue");
// const { CdxButton, CdxButtonGroup, CdxToggleButtonGroup, CdxIcon, CdxTabs, CdxTab, CdxTextInput, CdxLookup, CdxField, CdxRadio, CdxCheckbox, CdxSearchInput } = require( "@wikimedia/codex" );

module.exports = defineComponent( {
	name: "FacetedSearch",
	components: {
		Facet,
		FacetedSearchResult,
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
		const offset = ref( 0 );
		function updateOffset(n) {
			// submitQuery will adjust the offset
			submitQuery(n);
		}
		const maxPages = Number( props.configData.maxpages ?? 5 );

		const smwPrintoutProps = reactive( props.profile?.printout?.properties ?? [] );
		const smwQueryResults = ref( {} );
		const smwQueryResultKey = ref( "" );

		const showLoader = ref( false );
		function submitQuery(newOffset) {
			offset.value = newOffset;
			smwQueryResults.value = null;
			showLoader.value = true;
			// query, apiUrl

			// Build the query
			var smwQuery = buildQuery();
			//console.log( "smw query", smwQuery );

			setResultCount( smwQuery );

			// Run the query
			const smwAskApi = new mw.ForeignApi( apiUrl.value, { anonymous: false } );
			const smwAskParams = {
				action: "ask",
				format: "json",
				formatversion: "2",
				query: smwQuery
			};
			smwAskApi.get(smwAskParams)
			.done( function(data) {
				showLoader.value = false;
				if ( data.query == undefined ) {
					return;
				}
				// console.log( "data.query?.results", data.query?.results );
				smwQueryResultKey.value = getTimestamp();
				if ( typeof data.query?.results == "object" ) {
					smwQueryResults.value = data.query?.results;
				}
				// ...
			} );
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
							var newQ = assignToProperty( facet.smwproperty, query[k], facet.subquery );
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
							smwQuery += getReplacementString(substr, facet.smwproperty, usesTokens, minTokenSize) + ` `;
							smwQueryObj[k] = getReplacementString(substr, facet.smwproperty, usesTokens, minTokenSize);
						} else {
							// Assuming single-page restriction
							smwQuery += `[[~${substr}*]] `;
							smwQueryObj[k] = `[[~${substr}*]]`;
						}
					break;
				}
			} );

			smwPrintoutProps.forEach( (prop) => {
				smwQuery += `|?${prop}\n`;
			} );

			// options (limit, offset, sort, order)
			var options = {
				limit: props.configData.limit,
				offset: offset.value,
				sort: ( typeof props.configData.sort !== "undefined" ) ? props.configData.sort : null,
				order: ( typeof props.configData.order !== "undefined" ) ? props.configData.order : null,
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
						// ?
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

		const debug = ref( false );
		if( typeof props.configData.debug !== "undefined" && props.configData.debug == "true" ) {
			debug.value = true;
		}

		return {
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

			getTimestamp,
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

.recon-result-count {
  display: flex;
  justify-content: end;
  font-size: .8rem;
  margin-bottom: .5rem;
}

</style>
