<template>
	<span class="loader" v-if="showLoader"></span>

	<div v-if="template" v-html="formattedHtml" class="faceted-query-list"></div>
	<div v-else class="faceted-query-list">
		<div v-for="f in formattedResults" :key="f.key" class="faceted-query-result">
			<h3><a :href="f.fullurl" v-html="f.displaytitle || f.fulltext"></a></h3>
		</div>
	</div>

</template>

<script>
const { defineComponent, computed, ref, reactive, watch } = require("vue");

module.exports = defineComponent( {
	name: "FacetedSearchResult",
	components: {
	},
	props: {
		smwResult: { type: "Object", default: {} },
		template: { type: "String", default: null },
		valueSep: { type: "String" }
	},
	setup(props, context) {
		/**
		 * In a previous version, the ask API result could 
		 * be translated to a template call = now abandoned in
		 * favour of parsing the #ask pf directly.
		 * The relevant code is deprecated but has not been removed yet.
		 */
		const formattedResults = reactive( [] );
		const formattedHtml = ref( "" );
		const showLoader = ref( false );

		watch( props.smwResult, (n) => {
			//console.log( "smwResult, new value", n );
			if ( props.template !== null ) {
				/* @deprecated, at least for now
				var wikitemplateSyntax = formatResultsForTemplate(n);
				parseWikitemplateSyntax( wikitemplateSyntax );
				*/
			} else {
				// default output without template
				formatResultsForDefaultOutput(n);
			}
		}, { deep: true, immediate: true } );

		function formatResultsForTemplate( res ) {
			//console.log( "object keys", Object.keys(res) );
			var wikitemplateSyntax = "";
			for (const [key, value] of Object.entries( res )) {
				wikitemplateSyntax += formatResultForTemplate( key, value );
			}
			return wikitemplateSyntax;
		}

		function formatResultForTemplate(key,value) {
			//console.log( "key",key );
			formattedResults.push( { "key": key } );

			var wikiTplParams = `|Page=${value.fulltext}` + `|displaytitle=${value.displaytitle}` + `|fullurl=${value.fullurl}` + `|exists=${value.exists}`;
			for (const [prop,propVal] of Object.entries( value.printouts ?? {} )) {
				//console.log(`${key}: ${value}`);
				var wikiTplParam = processPropertyPrintout(prop,propVal);
				//console.log(wikiTplParam);
				wikiTplParams += wikiTplParam + ` `;
			}
			if ( props.template == null ) {
				return "";
			}
			return `{{${props.template} ${wikiTplParams} }}`;
		}

		// Returns param syntax for wiki templates
		function processPropertyPrintout(propName,propVals) {
			var plainValues = [];
			propVals.forEach( (v) => {
				if ( typeof v == "object" && v.fulltext !== undefined ) {
					// Page
					plainValues.push(v.fulltext);
				} else if( typeof v == "object" && v.timestamp !== undefined ) {
					// Date
					plainValues.push(v.timestamp);
				} else if( typeof v == "string" ) {
					// Text
					plainValues.push(v);
				}
			});
			return plainValues.length !== 0
				? `|${propName}=` + plainValues.join(props.valueSep)
				: "";
		}

		function parseWikitemplateSyntax( syntax ) {
			showLoader.value = true;
			// console.log( "syntax", syntax );
			const api = new mw.Api();
			const apiParams = {
				action: "parse",
				text: syntax,
				contentmodel: "wikitext",
				format: "json",
				formatversion: 2,
				prop: "text"
			};
			api.post(apiParams)
			.done(function(data) {
				showLoader.value = false;
				formattedHtml.value = data.parse.text;
			})
			/* shorthand version:
			new mw.Api().parse(syntax)
			.done(function(data) {
				showLoader.value = false;
				formattedHtml.value = data;
			}) */
			.fail(function() {
				showLoader.value = false;
				console.log( "Parsing failed..." );
			});
		}

		// 
		function formatResultsForDefaultOutput(res) {
			//formattedResults
			for (const [key, value] of Object.entries( res )) {
				formattedResults.push( { 
					key: key,
					fulltext: value.fulltext,
					fullurl: value.fullurl,
					displaytitle: value.displaytitle
				} );
			}
		}

		return {
			formattedHtml,
			formattedResults,
			showLoader
		}
	}
} );
</script>

<style lang="less">
.faceted-query-list {
	margin-bottom: 1rem;
}

.faceted-query-result {
	padding: .4rem .8rem;
	border: 1px solid gainsboro;
	h2, h3 {
		font-size: 1.4rem;
		margin: .2rem;
		padding: 0;
	}
}

</style>
