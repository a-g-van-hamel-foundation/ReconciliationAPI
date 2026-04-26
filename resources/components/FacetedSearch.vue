<template>
	<div class="recon-faceted-search">
		<div class="recon-facets">

			<template v-for="facet in facets">
				<dynamic-facet
					:ref="facet.name"
					:name="facet.name ?? facet.smwproperty"
					:label="facet.label ?? facet.name"
					v-model="query[facet.name]"
					:input-type="facet.inputType ?? 'text'"
					v-model:query="query"
					:api-url="apiUrl"
					:config-data="facet"
				></dynamic-facet>
			</template>

			<button @click="convertQuery" class="btn-submit">Show results</button>
		</div>
		<div class="recon-results">
			<i>Results here...</i>
			<pre>{{ query }}</pre>
			<pre style="color:red; font-size: .7rem;">{{ smwQueryObj }}</pre>

			<pre>{{ smwQueryResults }}</pre>
			
		</div>
	</div>
</template>

<script>
const { defineComponent, computed, ref, reactive, watch } = require("vue");
const DynamicFacet = require("./DynamicFacet.vue");
// const { CdxButton, CdxButtonGroup, CdxToggleButtonGroup, CdxIcon, CdxTabs, CdxTab, CdxTextInput, CdxLookup, CdxField, CdxRadio, CdxCheckbox, CdxSearchInput } = require( "@wikimedia/codex" );

module.exports = defineComponent( {
	name: "FacetedSearch",
	components: {
		DynamicFacet
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

		const smwPrintoutProps = reactive( props.profile?.printout?.properties ?? [] );
		const smwQueryResults = reactive( {} );

		function convertQuery() {
			// query, apiUrl
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
						if ( facet.options !== undefined ) {
							var option = facet.options.find( (opt) => opt['value'] == query[k] );
							smwQuery += `[[${facet.smwproperty}::${option.value}]]`;
							smwQueryObj[k] = `[[${facet.smwproperty}::${option.value}]]`;
						} else if ( facet.mapOptions !== undefined ) {	
							var mapOption = facet.mapOptions.find( (opt) => opt['option'] == query[k] );
							//console.log( "mapOption", mapOption );
							smwQuery += mapOption.where;
							smwQueryObj[k] = mapOption.where;
						} else {
							// API
							smwQuery += `[[${facet.smwproperty}::${query[k]}]] `;
							smwQueryObj[k] = `[[${facet.smwproperty}::${query[k]}]]`;
						}
					break;
					case "multiselect":
						smwQueryObj[k] = [];
						if ( facet.options !== undefined ) {
							// No need to check I think
						}
						query[k].forEach( (v) => {
							smwQuery += `[[${facet.smwproperty}::${v}]] `;
							smwQueryObj[k].push( `[[${facet.smwproperty}::${v}]]` );
						} );
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

			//apiUrl
			console.log( "smw query", smwQuery );
			const smwAskApi = new mw.ForeignApi( apiUrl.value, { anonymous: false } );
			const smwAskParams = {
				action: "ask",
				format: "json",
				formatversion: "2",
				query: smwQuery
			};
			smwAskApi.get(smwAskParams)
			.done( function ( data ) {
				if ( data.query == undefined ) {
					return;
				}
				smwQueryResults.value = data.query?.results;
				console.log( data.query?.results );
				// ...
			} );
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

		return {
			facets,
			apiUrl,

			query,
			smwQueryObj,
			smwQueryResults,
			convertQuery
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

</style>
