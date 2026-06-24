<template>
	<div>
		<cdx-typeahead-search
			:id="randomId"
			:form-action="formAction"
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
				<template v-for="input in hiddenInputs">
					<input
						type="hidden"
						:name="input.name"
						:value="input.value"
					>
				</template>
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
		defaultTargetPage: { type: String, default: "Special:Search" }
	},
	setup( data ) {
		const currentSearchTerm = ref( "" );
		const searchResults = ref( [] );

		// Relating to API requests
		var internal = data.configProps["internal"] === "true" ? true : false;
		var actionApiUrl = data.configProps["apiUrl"];
		// @note: if .get() is used, "&origin=*" should be added 
		// to the base URL. No such issue with .post()
		var actionApiBaseUrl = data.configProps["apiBaseUrl"];
		const profileId = ref("");
		var apiUrlParams = JSON.parse(data.configProps.apiUrlParams);
		if ( apiUrlParams.profile ) {
			profileId.value = apiUrlParams.profile;
		}

		// Appearance
		const showThumbnail = ref( data.configProps.showThumbnail === "true" ? true : false );
		const placeholder = data.configProps["placeholder"] ?? mw.message("recon-search-placeholder-default-text").text();
		const searchSiteForPagesContaining = ref( data.configProps["footerText"] ?? mw.message("recon-search-footer-default-text").text() );

		// Relating to URL targets
		const formAction = ref( data.configProps.searchAction ?? mw.config.get("wgServer") + mw.config.get("wgScript") );
 		const targetUrlBase = ref("");
		const searchFooterUrl = ref( "" );
		const footerUrlBase = ref("");
		setUrlBaseForTargetAndFooter();
		function setUrlBaseForTargetAndFooter() {
			// Set targetUrlBase
			targetUrlBase.value = data.configProps["targetUrl"];
			// Set footerUrlBase
			if ( data.configProps["footerUrl"] && data.configProps["footerUrl"] !== "" ) {
				footerUrlBase.value = data.configProps["footerUrl"];
			} else {
				footerUrlBase.value = `${formAction.value}?title=${data.configProps.searchPage}&${data.configProps.searchPageParams}`;
			}
		}
		const hiddenInputs = ref( [] );
		setHiddenInputs();
		function setHiddenInputs() {
			let termParamName = "";
			let searchPageParamsObj = {};
			if ( data.configProps.searchPageParams ) {
				// Get name of term parameter, which should come at the end
				termParamName = data.configProps.searchPageParams.split("&").at(-1).replace("=", "");
				// Other:
				searchPageParamsObj = Object.fromEntries(new URLSearchParams(data.configProps.searchPageParams));
			}

			let inputs = [ { name: "title", value: data.configProps.searchPage } ];
			if (data.configProps.searchPage == "Special:Search") {
				if ( data.configProps.searchPageParams !== "" ) {
					for (const [k,v] of Object.entries(searchPageParamsObj)) {
						if ( k !== termParamName ) {
							inputs.push( { name: k, value: v} );
						}
					}
					inputs.push( { name: termParamName, value: currentSearchTerm.value } );
				} else {
					inputs.push( { name: "fulltext", value: "1" } );
					inputs.push( { name: "search", value: currentSearchTerm.value } );
				}
			} else if( data.configProps.searchPage.startsWith("Special:ReconRedirect") ) {
				// ...
				inputs.push({ name: "skipcheck", value: "true" });
				inputs.push({ name: "q", value: currentSearchTerm.value });
			} else {
				// Full customisation
				for (const [k,v] of Object.entries(searchPageParamsObj)) {
					if ( k !== termParamName ) {
						inputs.push( { name: k, value: v} );
					}
				}
				inputs.push( { name: termParamName, value: currentSearchTerm.value } );
			}

			// Set hidden inputs
			hiddenInputs.value = inputs;
		}

		const latestRequestTime = ref(null);

		/**
		 * @param {String} value
		 * @param {Number} offset
		 * @param {String} action - "replace" (new results) or "add" (add to results, 'load more')
		 */
		function fetchResults(value, offset, action) {
			let localRequestTime = null;
			latestRequestTime.value = localRequestTime = Date.now();

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

			setHiddenInputs();

			actionApi.get(apiUrlParamsObj)
			.done( function (data) {
				// Cancel handling of current API request
				// if a new one was made in the interim
				if( latestRequestTime.value > localRequestTime ) {
					return;
				}
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
				searchFooterUrl.value = footerUrlBase.value + `${ encodeURIComponent(value) }`;
			} )
			.fail( (error) => {
				console.log(error);
			} );
		}

		function onId() {
			return "typeahead-search-site--reconapi";
		}

		function onInput(value) {
			// eslint-disable-next-line no-console

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
				url: targetUrlBase.value + `${ encodeURIComponent( id ) }`,
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
		}

		// Remove duplicates
		function deduplicateResults(results) {
			const seen = new Set( searchResults.value.map( (result) => result.value) );
			return results.filter( (result) => !seen.has(result.value) );
		}

		function onSubmit(n) {
			// eslint-disable-next-line no-console
		}

		return {
			searchResults,

			profileId,
			formAction,
			hiddenInputs,
			setHiddenInputs,
			latestRequestTime,
			targetUrlBase,
			footerUrlBase,
			searchFooterUrl,

			currentSearchTerm,

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

<style lang="less">
:root {
	//--recon-border--focus: #9b9b9b;
	--recon-border--focus: #607672;
}

.recon-search-widget {
	.cdx-menu-item__text__description {
		font-size: .8rem;
	}
	.cdx-text-input__input:enabled:focus {
		border-color: var(--recon-border--focus);
		box-shadow: inset 0 0 0 1px var(--recon-border--focus);
	}
	.cdx-search-result-title__match {
		color: #5c7a51;
	}
	@media screen and (max-width: 576px) {
		.cdx-text-input {
			min-width:200px;
		}
	}
}

</style>
