<template>
	<div>
		<cdx-typeahead-search
			:id="randomId"
			:form-action="defaultFormAction"
			:use-button="true"
			:search-results="searchResults"
			:search-footer-url="searchFooterUrl"
			:show-thumbnail="showThumbnail"
			:highlight-query="true"
			:auto-expand-width="false"
			:placeholder="placeholder"
			visible-item-limit=5
			debounce-interval=175
			@input="onInput"
			@search-result-click="onSearchResultClick"
			@submit="onSubmit"
			@load-more="onLoadMore"
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
			<template #search-no-results-text>
				{{ $i18n('recon-search-no-results-text').text() }}
			</template>
			<template #search-results-pending>
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
	components: { 
		CdxTypeaheadSearch
	},
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
	setup( data ) {
		// reactive
		const searchResults = ref( [] );
		const searchFooterUrl = ref( '' );
		const currentSearchTerm = ref( '' );
		const showThumbnail = ref( data.configProps.showThumbnail === "true" ? true : false );
		const placeholder = data.configProps["placeholder"] ?? mw.message("recon-search-placeholder-default-text").text();
		const searchSiteForPagesContaining = ref( data.configProps["footerText"] ?? mw.message("recon-search-footer-default-text").text() );

		// non-reactive
		var targetUrl = data.configProps["targetUrl"] ?? "";
		var footerUrl = data.configProps["footerUrl"] ?? "";
		var internal = data.configProps["internal"] === "true" ? true : false;
		var actionApiUrl = data.configProps["apiUrl"];
		// @note: if .get() is used, "&origin=*" should be added 
		// to the base URL. No such issue with .post()
		var actionApiBaseUrl = data.configProps["apiBaseUrl"];

		/**
		 * @param {String} value
		 * @param {Number} offset
		 * @param {String} action - "replace" (new results) or "add" (add to results, 'load more')
		 */
		function fetchResults(value, offset, action) {
			// Convert the JSON-encoded string in the HTML to an object
			var apiUrlParamsObj = JSON.parse(data.configProps.apiUrlParams);
			if (!internal) {
				// remote requests
				var actionApi = new mw.ForeignApi(actionApiBaseUrl, { anonymous: true });
			} else {
				// internal use
				var actionApi = new mw.ForeignApi(actionApiBaseUrl, { anonymous: false });
			}
			
			// Add user-provided value. Because we don't 
			// know in advance what the key is named (e.g.
			// 'substr', 'prefix'), we'll pull this trick
			var finalKeyName = Object.keys(apiUrlParamsObj)[ Object.keys(apiUrlParamsObj).length - 1 ];
			apiUrlParamsObj[finalKeyName] = value;
			// offset
			apiUrlParamsObj['offset'] = offset ?? 0;

			actionApi.get(apiUrlParamsObj)
			.done( function (data) {
				if (data.result && data.result.length > 0) {
					let res = adaptApiResponse(data.result);
					if (action == "replace") {
						searchResults.value = res;
					} else if(action == "add") {
						const deduplicatedResults = deduplicateResults(res);
						searchResults.value.push( ...deduplicatedResults);
					}
				} else {
					if (action == "replace") {
						searchResults.value = [];
					}
				}
				searchFooterUrl.value = footerUrl + `${ encodeURIComponent(value) }`;
			} );
		}

		function onId() {
			return "typeahead-search-site--reconapi";
		}

		function onInput(value) {
			// eslint-disable-next-line no-console
			// console.log( 'input event emitted with value:', value );

			// Internally track the current search term.
			currentSearchTerm.value = value;
			// Unset search results and the search footer URL if there is no value.
			if (!value || value === "") {
				searchResults.value = [];
				searchFooterUrl.value = "";
				return;
			}

			fetchResults(currentSearchTerm.value, 0, "replace");
		}

		/**
		 * Map response from the queried API
		 * @param {Array} result
		 */
		function adaptApiResponse(result) {
			// Sadly, Typeahead does not support tags (italics, etc.) 
			// in labels, and stripping tags should not be the job of 
			// the API. Tags in 'description' are stripped automatically
			return result.map( ( { id, name, description, thumbnail, other } ) => ( {
				value: id,
				label: stripHtml(name),
				description: description ?? getDefaultDescription(other),
				url: data.configProps.targetUrl + `${ encodeURIComponent( id ) }`,
				thumbnail: showThumbnail && thumbnail ? {
					url: thumbnail.url,
					width: thumbnail.width,
					height: thumbnail.height
				} : undefined
			} ) );
		}

		function onLoadMore() {
			if (!currentSearchTerm.value) {
				return;
			}
			fetchResults(currentSearchTerm.value, searchResults.value.length ?? 0, "add");
		}


		function stripHtml(str) {
			let tmp = document.createElement("div");
			tmp.innerHTML = str;
			return tmp.textContent || tmp.innerText || "";
		}

		// Not currently used
		function renderHtml(html) {
			var tmp = document.createElement("textarea");
		    tmp.innerHTML = html;
    		return tmp.value;
		}

		function getDefaultDescription(other) {
			if (other !== undefined && other.exists == 0) {
				// Text if page from result does not exist
				return mw.message('reconciliationapi-typeahead-norecord').text();
			} else {
				return "";
			}
		}

		function onSearchResultClick(value) {
			// eslint-disable-next-line no-console
			// console.log( 'search-result-click event emitted with value:', value );
		}

		function onSubmit(value) {
			// eslint-disable-next-line no-console
			// console.log( 'Submit event emitted with value:', value );
		}

		// Remove duplicates
		function deduplicateResults(results) {
			const seen = new Set( searchResults.value.map( (result) => result.value) );
			return results.filter( (result) => !seen.has(result.value) );
		}

		return {
			searchResults,
			searchFooterUrl,

			showThumbnail,
			placeholder,
			searchSiteForPagesContaining,

			onInput,
			onLoadMore,
			onSearchResultClick,
			onSubmit
		};
	}

} );
</script>
