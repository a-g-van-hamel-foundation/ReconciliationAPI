<template>
	<div>
		<cdx-typeahead-search
			:id="randomId"
			:form-action="defaultFormAction"
			:use-button="true"
			:search-results="searchResults"
			:search-footer-url="searchFooterUrl"
			:show-thumbnail="true"
			:highlight-query="true"
			:auto-expand-width="false"
			:placeholder="placeholder"
			@input="onInput"
			@search-result-click="onSearchResultClick"
			@submit="onSubmit"
		>
			<template #default>
				<input
					type="hidden"
					name="language"
					value="en"
				>
				<input
					type="hidden"
					name="title"
					:value="defaultPage"
				>
			</template>
			<template #search-footer-text="{ searchQuery }">
				{{ searchSiteForPagesContaining }}
				<strong class="cdx-typeahead-search__search-footer__query">
					{{ searchQuery }}
				</strong>
			</template>
		</cdx-typeahead-search>
	</div>
</template>

<script>
const { defineComponent, ref } = require( 'vue' );
const { CdxTypeaheadSearch } = require( '@wikimedia/codex' );

module.exports = defineComponent( {
	name: 'Search',
	props: {
		// configProps is data from HTML attributes, accessed by data() below
		configProps: {
			type: Array,
			default: []
		},
		randomId: {
			type: String,
			default: "typeahead-sitesearch-" + Math.floor( Math.random() * 10000 )
		},
		// Used when pressing enter
		defaultFormAction: {
			type: String,
			default: "/index.php"
		},
		defaultPage: {
			type: String,
			default: "Special:Search"
		}
	},
	// ES6. Same as ES5 data: function()
	data() {
		return {
			//configProps: this.configProps,
			apiBaseUrl: this.configProps['apiBaseUrl'] ?? "",
			apiUrl: this.configProps['apiUrl'] ?? "",
			apiUrlParams: this.configProps['apiUrlParams'] ?? "",			
			targetUrl: this.configProps['targetUrl'] ?? "",
			footerUrl: this.configProps['footerUrl'] ?? "",
			searchSiteForPagesContaining: this.configProps['footerText'] ?? "Search the site for pages containing",
			placeholder: this.configProps['placeholder'] ?? "Search the website",
			//description: this.configProps['description'] ?? "",
			//supportingtext: this.configProps['supportingtext'] ?? ""
		}
	},
	created() {
		//console.log( "API URL: " + this.apiUrl )
	},
	computed: {
		//visibleItemLimit() {
			//return 10;
		//}
	},
	components: { 
		CdxTypeaheadSearch
	},
	setup( data ) {
		const searchResults = ref( [] );
		const searchFooterUrl = ref( '' );
		const currentSearchTerm = ref( '' );

		function onId() {
			return "typeahead-search-site--reconapi";
		}

		function onInput( value ) {
			// eslint-disable-next-line no-console
			console.log( 'input event emitted with value:', value );

			// Internally track the current search term.
			currentSearchTerm.value = value;

			// Unset search results and the search footer URL if there is no value.
			if ( !value || value === '' ) {
				searchResults.value = [];
				searchFooterUrl.value = '';
				return;
			}

			var actionApiUrl = data.configProps.apiUrl;
			// @note: if .get() is used, "&origin=*" should be added 
			// to the base URL. No such issue with .post()
			var actionApiBaseUrl = data.configProps.apiBaseUrl;
			// Convert the JSON-encoded string in the HTML to an object
			var apiUrlParamsObj = JSON.parse( data.configProps.apiUrlParams );
			console.log( actionApiUrl + `${ encodeURIComponent( value ) }` );

			// Add user-provided value. Because we don't know in advance
			// what the key (e.g. 'substr') is named, we'll pull this trick
			var finalKeyName = Object.keys(apiUrlParamsObj)[ Object.keys(apiUrlParamsObj).length - 1 ];
			apiUrlParamsObj[finalKeyName] = value;
			// apiUrlParamsObj.substr = value;

			var targetUrl = data.configProps.targetUrl;
			var footerUrl = data.configProps.footerUrl;
			// console.log( apiUrlParamsObj);

			var actionApi = new mw.ForeignApi( actionApiBaseUrl, { anonymous: true } );
			actionApi.post( apiUrlParamsObj )
			.done( function ( data ) {
				searchResults.value = data.result && data.result.length > 0
						? adaptApiResponse( data.result )
						: [];
				searchFooterUrl.value = footerUrl + `${ encodeURIComponent( value ) }`;
			} );

			// Not currently used
			function renderHtml( html ) {
				var tmp = document.createElement("textarea");
			    tmp.innerHTML = html;
    			return tmp.value;
			}

			// Map response from the queried API
			function adaptApiResponse( result ) {
				// Sadly, Typeahead does not support tags (italics, etc.) 
				// in labels, and stripping tags should not be the job of 
				// the API. Tags in 'description' are stripped automatically
				return result.map( ( { id, name, description, thumbnail, other } ) => ( {
					value: id,
					label: stripHtml( name ),
					description: description ?? getDefaultDescription( other ),
					url: data.configProps.targetUrl + `${ encodeURIComponent( id ) }`,
					thumbnail: thumbnail ? {
						url: thumbnail.url,
						width: thumbnail.width,
						height: thumbnail.height
					} : undefined
				} ) );
			}

			function stripHtml( str ) {
				let tmp = document.createElement("div");
				tmp.innerHTML = str;
				return tmp.textContent || tmp.innerText || "";
			}

			function getDefaultDescription( other ) {
				if ( other == undefined ) {
					return "";
				}
				if ( other.exists == 0 ) {
					// Text if page from result does not exist
					var defaultDescription = mw.message( 'reconciliationapi-typeahead-norecord' ).text();
				} else {
					var defaultDescription = "";
				}
				return defaultDescription;
			}
			//
		}

		function onSearchResultClick( value ) {
			// eslint-disable-next-line no-console
			console.log( 'search-result-click event emitted with value:', value );
		}

		function onSubmit( value ) {
			// eslint-disable-next-line no-console
			console.log( 'Submit event emitted with value:', value );
		}

		// remove duplicates
		function deduplicateResults( results ) {
			const seen = new Set( searchResults.value.map( ( result ) => result.value ) );
			return results.filter( ( result ) => !seen.has( result.value ) );
		}

		// Unused: onLoadMore not implemented until later 
		// versions of Codex
		function onLoadMore() {
			if ( !currentSearchTerm.value ) {
				return;
			}
			fetchResults( currentSearchTerm.value, searchResults.value.length ).then( ( data ) => {
				const results = data.search && data.search.length > 0 ?
					adaptApiResponse( data.search ) :
					[];
				const deduplicatedResults = deduplicateResults( results );
				searchResults.value.push( ...deduplicatedResults );
			} );
		}

		return {
			searchResults,
			searchFooterUrl,
			onInput,
			onSearchResultClick,
			onSubmit
		};
	}

} );
</script>
