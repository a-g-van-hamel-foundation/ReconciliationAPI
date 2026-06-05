<template>
	<span class="loader" v-if="showLoader"></span>

	<div v-if="template" v-html="formattedHtml" class="faceted-query-list"></div>
	<div v-else class="faceted-query-list">
		<div v-for="f in formattedResults" :key="f.key" class="faceted-query-result">
			<h3>
				<a v-if="f.exists" :href="f.fullurl" v-html="f.displaytitle || f.fulltext"></a>
				<span v-else v-html="f.displaytitle || f.fulltext"></span>
			</h3>
			<div v-if="f.description !== ''" class="description" v-html="f.description"></div>
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
		smwResult: { type: "Object", default: null },
		template: { type: "String", default: null },
		valueSep: { type: "String" },
		configData: { type: "Object", default: {} }
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
			if ( n == null ) {
				return;
			}
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

		// In use
		function formatResultsForDefaultOutput(res) {
			for (const [key, value] of Object.entries( res )) {
				// printout properties: label
				let label = "";
				let labelProp = props.configData.reconLabelProp ?? "Display title of";
				if (value.printouts[labelProp] !== undefined) {
					label = value.printouts[labelProp].join("; ");
				}
				// printout properties:description
				let description = "";
				let descriptionProp = props.configData.reconDescriptionProp ?? "Has description";
				if (value.printouts[descriptionProp] !== undefined) {
					description = value.printouts[descriptionProp].join("; ");
				}

				if (description == "") {
					// No need to parse empty description
					formattedResults.splice(key, 0, {
						key: key,
						fulltext: value.fulltext,
						fullurl: value.fullurl,
						displaytitle: label ?? value.displaytitle,
						description: "",
						exists: value.exists
					});
				} else {
					// Parse wikitext description first
					const api = new mw.Api();
					const apiParams = {
						action: "parse",
						text: description ?? "",
						contentmodel: "wikitext",
						format: "json",
						formatversion: 2,
						prop: "text"
					};
					api.post(apiParams)
					.done(function(data) {
						formattedResults.splice(key, 0, {
							key: key,
							fulltext: value.fulltext,
							fullurl: value.fullurl,
							displaytitle: label ?? value.displaytitle,
							description: data.parse.text,
							exists: value.exists
						})
					});
				}
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
