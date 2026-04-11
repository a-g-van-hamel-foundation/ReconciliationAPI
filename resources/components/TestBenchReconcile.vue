<template>
	<section>

		<div class="row">
		<div class="col-md-12">recon API module</div>
		<div class="col-md-7">
			<h2>Query</h2>

			<div class="form-group">
				<label>Substring:</label>
				<div>
					<cdx-text-input
						v-model="reconQuery['q0']['query']"
						@update:model-value="onUpdateSubstring"
						name="query"
						placeholder="Substring for entity to reconcile"
						aria-label="Entity to reconcile"
					></cdx-text-input>
				</div>
			</div>
			<div class="form-group">
				<label class="control-label">Type:</label>
				<div>
					<cdx-lookup 
						name="type"
						v-model:selected="reconQuery.q0.type"
						v-model:input-value="typeInput"
						:menu-items="typeList"
						@update:input-value="requestType"
						placeholder="Type"
					></cdx-lookup>
				</div>
			</div>
			<div class="form-group">
				<label>Properties:</label>
				<div>
					<div
						v-for="(prop,index) in reconQuery.q0.properties"
						class="recon-props"
						:ref="`props-` + index"
					>
						<cdx-lookup 
							:ref="`property-` + index"
							v-model:selected="prop['pid']"
							v-model:input-value="propertyInputs[index]"
							:menu-items="propertyList"
							@update:input-value="requestProperty"
							placeholder="Property"
						></cdx-lookup>
						<cdx-text-input 
							name="value"
							v-model="prop['v']"
							placeholder="Value"
						></cdx-text-input>
						<cdx-button @click="removeProperty(index)"><cdx-icon :icon="cdxIconClose"></cdx-icon></cdx-button>
					</div>
					<cdx-button @click="addProperty()">Add property</cdx-button>
				</div>
			</div>
			<div class="form-group">
				<label>Limit</label>
				<div>100</div>
			</div>

			<cdx-button @click="submitQuery()" action="progressive" weight="primary">Submit query</cdx-button>

			<h2>Query string</h2>
			<pre>{{ reconQuery }}</pre>
		</div>
		<div class="col-md-5 text-small">
			<h2>Result</h2>
			<span class="loader" v-if="showLoader"></span>
			<a v-if="serviceUrl" :href="serviceUrl">View this query result in the API</a>
			<pre>{{ reconApiResult }}</pre>
		</div>
		</div>

	</section>
</template>

<script>
const { defineComponent, ref, reactive, computed } = require("vue");
const { CdxButton, CdxIcon, CdxTextInput, CdxLookup, CdxField, CdxRadio, CdxSearchInput } = require( "@wikimedia/codex" );
const { cdxIconClose } = require( './icons.json' );

module.exports = defineComponent( {
	name: "TestBenchReconcile",
	components: {
		CdxButton, CdxIcon,
		CdxTextInput, CdxLookup, CdxField, CdxRadio, CdxSearchInput
	},
	props: {
		apiUrl: { type: String, default: null },
		source: { type: String, default: "mw" },
		profileId: { type: String, default: "" }
	},
	setup(props, context) {
		const sourceProxy = computed( () => {
			return props.source;
		} );
		const profileIdProxy = computed( () => {
			return props.profileId;
		} );
		// Full url with query string
		const serviceUrl = ref( null );

		// Query sent to recon module
		const reconQuery = reactive( {
			q0: {
				query: "",
				// pid:"...", v:"..."
				properties: [],
				type: null
			}
		} );

		function onUpdateSubstring() {
			// console.log( "substr" );
		}

		// 'Type' field
		const typeInput = ref( "" );
		const typeList = ref( [] );
		function requestType(term) {
			if ( !term ) {
				//typeList = [];
				return;
			}
			const actionApi = new mw.ForeignApi( props.apiUrl, { anonymous: false } );
			const apiUrlParams = {
				action: "recon-suggest-type",
				format: "json",
				formatversion: "2",
				source: sourceProxy.value,
				prefix: term
			};
			actionApi.get(apiUrlParams)
			.done( function ( data ) {
				if ( data.result == undefined ) {
					return;
				}
				typeList.value = data.result.map( ( res ) => ( {
					value: res.id,
					label: res.name,
					description: res.description ?? ""
				} ) );
			} );
		}

		// 'Properties' field
		function addProperty() {
			propertyInputs.push( "" );
			reconQuery.q0.properties.push( { pid: null, v: null } );
		}
		function removeProperty(index) {
			propertyInputs.splice( index, 1 );
			reconQuery.q0.properties.splice( index, 1 );
		}
		const propertyInputs = reactive( [] );
		const propertyList = ref( [] );
		function requestProperty(term) {
			if ( !term ) {
				//propertyList = [];
				return;
			}
			const actionApi = new mw.ForeignApi( props.apiUrl, { anonymous: false } );
			const apiUrlParams = {
				action: "recon-suggest-property",
				format: "json",
				formatversion: "2",
				source: sourceProxy.value,
				prefix: term
			};
			actionApi.get(apiUrlParams)
			.done( function ( data ) {
				if ( data.result == undefined ) {
					return;
				} 
				propertyList.value = data.result.map( ( res ) => ( {
					value: res.id,
					label: res.name,
					description: res.description ?? ""
				} ) );
			} );
		}
		
		// query result
		const reconApiResult = reactive( [] );
		const showLoader = ref( false );
		function submitQuery() {
			showLoader.value = true;
			const actionApi = new mw.ForeignApi( props.apiUrl, { anonymous: false } );
			const apiUrlParams = {
				action: "recon",
				format: "json",
				formatversion: "2",
				source: sourceProxy.value,
				queries: JSON.stringify(reconQuery)
			};
			if ( profileIdProxy.value !== "" && profileIdProxy.value !== null ) {
				apiUrlParams.profile = profileIdProxy.value;
			}
	
			reconApiResult.length = 0;
			actionApi.get(apiUrlParams)
			.done( function ( data ) {
				const searchParams = new URLSearchParams(apiUrlParams);
				serviceUrl.value = props.apiUrl + "?" + searchParams.toString();
				showLoader.value = false;
				reconApiResult.push(...data.q0?.result);
				// show data.q0.meta ?
				//processReconApiResult( data.result );
			});
		}

		function processReconApiResult(result) {
			//reconApiResult.value = result;
		}

		return {
			sourceProxy,
			profileIdProxy,
			serviceUrl,

			reconQuery,
			reconApiResult,

			onUpdateSubstring,

			typeInput,
			typeList,
			requestType,

			propertyInputs,
			propertyList,
			requestProperty,
			addProperty,
			removeProperty,
			submitQuery,
			showLoader,

			cdxIconClose
		}
	}
} );
</script>

<style lang="less">
.recon-props {
	display: flex;
	gap: .5rem;
	width:100%;
	margin-bottom: .5rem;
	flex-wrap: wrap;
	.cdx-text-input {
		min-width: 175px;
	}
}

</style>
