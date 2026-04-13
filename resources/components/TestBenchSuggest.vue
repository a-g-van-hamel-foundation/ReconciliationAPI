<template>
	<p>Test the API modules <code>recon-suggest-entity</code>, <code>recon-suggest-type</code> and <code>recon-suggest-property</code>.</p>
	<div class="form-group">
		<label>Entity:</label>
		<div>
			<cdx-lookup 
				name="entity"
				v-model:selected="suggestQuery.entity"
				v-model:input-value="entityInput"
				:menu-items="entityList"
				@update:input-value="requestEntity"
				placeholder="Entity"
			></cdx-lookup>
			<div class="recon-about">
				<template v-if="profileIdProxy !== ''">Profile ID: {{ profileIdProxy }}</template>
				<template v-else>{{ substrPatternProxy }}. </template>
			</div>
			<template v-if="suggestQuery.entity">
				<a class="recon-link" :href="createWikiLink(suggestQuery.entity)">{{ suggestQuery.entity }}</a>
			</template>
		</div>
	</div>
	<div class="form-group">
		<label>Type:</label>
		<div>
			<cdx-lookup 
				name="type"
				v-model:selected="suggestQuery.type"
				v-model:input-value="typeInput"
				:menu-items="typeList"
				@update:input-value="requestType"
				placeholder="Type"
			></cdx-lookup>
			<template v-if="suggestQuery.type">
				<a class="recon-link" :href="createWikiLink(suggestQuery.type)">{{ suggestQuery.type }}</a>
			</template>
		</div>
	</div>
	<div class="form-group">
		<label>Property:</label>
		<div>
			<cdx-lookup 
				name="property"
				v-model:selected="suggestQuery.property"
				v-model:input-value="propertyInput"
				:menu-items="propertyList"
				@update:input-value="requestProperty"
				placeholder="Property"
			></cdx-lookup>
		</div>
	</div>

	<pre>{{ suggestQuery }}</pre>
</template>

<script>
const { defineComponent, ref, reactive, computed, watch } = require("vue");
const { CdxButton, CdxIcon, CdxTextInput, CdxLookup, CdxField, CdxRadio, CdxSearchInput } = require( "@wikimedia/codex" );
const { cdxIconAdd, cdxIconClose } = require( './icons.json' );

module.exports = defineComponent( {
	name: "TestBenchSuggest",
	components: {
		CdxButton, CdxIcon,
		CdxTextInput, CdxLookup, CdxField, CdxRadio, CdxSearchInput
	},
	props: {
		apiUrl: { type: String, default: null },
		source: { type: String, default: "mw" },
		profileId: { type: String, default: "" },
		substrPattern: { type: String, default: "tokenstring" }
	},
	setup(props, context) {
		const sourceProxy = computed( () => {
			return props.source;
		} );
		const profileIdProxy = computed( () => {
			return props.profileId;
		} );
		const substrPatternProxy = computed( () => {
			return props.substrPattern;
		} );

		const suggestQuery = reactive( {} );

		// 'Entity' field
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
			} else {
				apiUrlParams.substrpattern = substrPatternProxy.value;
			}
			actionApi.get(apiUrlParams)
			.done( function ( data ) {
				//console.log("data",data);
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
			if ( profileIdProxy.value !== "" && profileIdProxy.value !== null ) {
				apiUrlParams.profile = profileIdProxy.value;
			} else {
				apiUrlParams.substrpattern = substrPatternProxy.value;
			}
			actionApi.get(apiUrlParams)
			.done( function ( data ) {
				console.log("data",data);
				if ( data.result == undefined ) {
					return;
				}
				typeList.value = data.result.map( (res) => ( {
					value: res.id,
					label: res.name,
					description: res.description ?? ""
				} ) );
			} );
		}

		// 'Property' field
		const propertyInput = ref( "" );
		const propertyList = ref( [] );
		function requestProperty(term) {
			if ( !term ) {
				propertyList = [];
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
				console.log("data",data);
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

		function createWikiLink(pagename) {
			const t = new mw.Title(pagename)
			return t.getUrl();
		}

		return {
			sourceProxy,
			profileIdProxy,
			substrPatternProxy,
			suggestQuery,
			entityInput,
			entityList,
			requestEntity,
			typeInput,
			typeList,
			requestType,
			propertyInput,
			propertyList,
			requestProperty,
			createWikiLink
		}

	}
} );
</script>

<style lang="less">
.recon-link {
  padding: .6rem;
  display: block;
}
</style>
