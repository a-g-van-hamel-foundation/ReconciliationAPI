<template>

<div class="row recon-row">

	<div class="col-md-6">
		<section style="margin-bottom:1.5rem;">
		<h2>Query</h2>

		<div class="form-group">
			<label>Entity:</label>
			<div>
				<cdx-lookup 
					name="suggest-entity"
					v-model:selected="extendQuery.ids[0]"
					v-model:input-value="entityInput"
					:menu-items="entityList"
					@update:input-value="requestEntity"
					placeholder="Entity"
				></cdx-lookup>
				<template v-if="profileIdProxy !== ''">Profile ID: {{ profileIdProxy }}</template>
				<template v-if="extendQuery.ids[0]">
					<a class="recon-link" :href="createWikiLink(extendQuery.ids[0])">{{ extendQuery.ids[0] }}</a>
				</template>
			</div>

		</div>

		<div class="form-group">
			<label>Property:</label>
			<div>
				<cdx-lookup 
					name="suggest-property"
					v-model:selected="extendQuery.properties[0].id"
					v-model:input-value="propertyInput"
					:menu-items="propertyList"
					@update:input-value="requestProperty"
					placeholder="Property"
				></cdx-lookup>
			</div>
		</div>

		<cdx-button @click="submitQuery()" action="progressive" weight="primary">Submit query</cdx-button>
		</section>

		<section>
			<h2>Query string</h2>
			<pre>{{ extendQuery }}</pre>
		</section>
	</div>
	<div class="col-md-6">
		<h2>Result</h2>
		<span class="loader" v-if="showLoader"></span>
		<a v-if="serviceUrl" :href="serviceUrl">View this query result in the API</a>
		<pre>{{ reconApiResult }}</pre>
	</div>

</div>
</template>

<script>
const { defineComponent, ref, reactive, computed, watch } = require("vue");
const { CdxButton, CdxIcon, CdxTextInput, CdxLookup, CdxField, CdxRadio, CdxSearchInput } = require( "@wikimedia/codex" );
//const { cdxIconAdd, cdxIconClose } = require( './icons.json' );

module.exports = defineComponent( {
	name: "TestBenchExtend",
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

		const extendQuery = reactive( {
			ids: [],
			properties: [ { id: null } ]
		} );

		const entityInput = ref( "" );
		const entityList = ref( [] );
		function requestEntity(term) {
			if ( !term ) {
				//typeEntity = [];
				return;
			}
			const actionApi = new mw.ForeignApi( props.apiUrl, { anonymous: false } );
			const apiUrlParams = {
				action: "recon-suggest-entity",
				format: "json",
				formatversion: "2",
				source: sourceProxy.value,
				prefix: term
			};
			if ( profileIdProxy.value !== "" && profileIdProxy.value !== null ) {
				apiUrlParams.profile = profileIdProxy.value;
			}
			actionApi.get(apiUrlParams)
			.done( function ( data ) {
				console.log("data",data);
				if ( data.result == undefined ) {
					return;
				}
				entityList.value = data.result.map( (res) => ( {
					value: res.id,
					label: res.name,
					description: res.description ?? ""
				} ) );
			} );
		}

		const propertyInput = reactive( "" );
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
				propertyList.value = data.result.map( (res) => ( {
					value: res.id,
					label: res.name,
					description: res.description ?? ""
				} ) );
			} );
		}

		const reconApiResult = ref( {} );
		const serviceUrl = ref( null );
		const showLoader = ref( false );
		function submitQuery() {
			showLoader.value = true;
			const actionApi = new mw.ForeignApi( props.apiUrl, { anonymous: false } );
			const apiUrlParams = {
				action: "recon",
				format: "json",
				formatversion: "2",
				source: sourceProxy.value,
				extend: JSON.stringify(extendQuery)
			};
			//reconApiResult.length = 0;

			actionApi.get(apiUrlParams)
			.done( function (data) {
				const searchParams = new URLSearchParams(apiUrlParams);
				serviceUrl.value = props.apiUrl + "?" + searchParams.toString();
				showLoader.value = false;
				reconApiResult.value = data.rows;
				//processReconApiResult( data.rows );
			});

		}

		function createWikiLink(pagename) {
			const t = new mw.Title(pagename)
			return t.getUrl();
		}

		return {
			sourceProxy,
			profileIdProxy,
			extendQuery,

			entityInput,
			entityList,
			requestEntity,

			propertyInput,
			propertyList,
			requestProperty,

			reconApiResult,
			serviceUrl,
			showLoader,
			submitQuery,

			createWikiLink
		}

	}
} );
</script>

<style lang="less">
</style>
