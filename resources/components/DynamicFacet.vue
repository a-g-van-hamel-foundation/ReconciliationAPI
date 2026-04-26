<template>
	<template v-if="componentType === 'lookup'">
		<div class="form-group form-group-v">
			<div><label>{{ label }}</label></div>
			<div>
				<cdx-lookup
					:name="name"
					v-model:selected="query[name]"
					v-model:input-value="lookupInput"
					:menu-items="selectList"
					@update:input-value="runRequest"
					:placeholder="label"
					:clearable="true"
				></cdx-lookup>
			</div>
		</div>
	</template>
	<template v-else-if="componentType === 'select'">
		<div class="form-group form-group-v">
			<div><label>{{ label }}</label></div>
			<div>
				<cdx-select
					v-model:selected="query[name]"
					:menu-items="selectList || []"
					default-label="Select"
					:placeholder="label"
					:multiple="multiple || false"
					:clearable="true"
					@update:selected="onUpdateSelected"
				></cdx-select>
			</div>
		</div>
	</template>
	<template v-else-if="componentType === 'multiselect'">
		<div class="form-group form-group-v">
			<div><label>{{ label }}</label></div>
			<div>
				<cdx-multiselect-lookup
					v-model:input-chips="chips"
					v-model:selected="query[name]"
					:menu-items="selectList || []"
					:menu-config="multiselectConfig"
					aria-label="..."
					@update:selected="onUpdateSelected"
					@input="onMultiselectInput"
				>
				<!--
				query[name]
				  -->
				</cdx-multiselect-lookup>
			</div>
		</div>
	</template>
	<template v-else-if="componentType === 'text'">
		<div class="form-group form-group-v">
			<div><label>{{ label }}</label></div>
			<div>
				<cdx-text-input
					:name="name"
					v-model="query[name]"
					:placeholder="label"
				></cdx-text-input>
			</div>
		</div>
	</template>

</template>

<script>
const { defineComponent, computed, ref, reactive, watch } = require("vue");
const { CdxTextInput, CdxSelect, CdxLookup, CdxMultiselectLookup } = require( "@wikimedia/codex" );

module.exports = defineComponent( {
	name: "DynamicFacet",
	components: {
		//CdxButton, CdxIcon,
		CdxTextInput, CdxSelect, CdxLookup, CdxMultiselectLookup
		// CdxField, CdxRadio, CdxSearchInput
	},
	props: {
		name: { type: "String", default: "" },
		label: { type: "String", default: "" },
		inputType: { type: "String", default: "text" },
		apiUrl: { type: "String", default: "" },
		query: { type: "Object", default: {} },
		configData: { type: "Object", default: {} }
	},
	setup(props, context) {
	
		// Source type
		// api types profileId and valuesFromProperty
		const profileId = ref( props.configData.profileid ?? null );
		const valuesFromProperty = ref( props.configData.valuesFromProperty ?? null );
		const dataSourceType = ref( null );
		if (props.configData.options !== undefined || props.configData.mapOptions !== undefined) {
			dataSourceType.value = "options";
		} else if(profileId !== null || valuesFromProperty !== null) {
			dataSourceType.value = "api";
		}

		// 'componentType' represents component type on an implementation level
		// whereas 'inputType' is user-oriented and more abstract
		const componentType = ref( "" );
		switch(props.inputType) {
			case "select":
				componentType.value = dataSourceType.value == "api" ? "lookup" : "select";
			break;
			default:
				componentType.value = props.inputType;
		}

		const lookupInput = ref( "" );
		// const lookupInputs = ref( [] );

		// Menu list
		const selectList = ref( [] );
		initSelectList();
		function initSelectList() {
			if ( componentType.value == "select" ) {
				// 'select' (but not 'multiselect'): start with dummy value
				selectList.value.push({
					value: "",
					label: "---"
				});
			}
			// Fixed options or mapped options
			if ( props.configData.options !== undefined ) {
				props.configData.options.forEach( (opt) => {
					selectList.value.push( { value: opt['value'], label: opt['label'] } );
				} );
			} else if ( props.configData.mapOptions !== undefined ) {
				props.configData.mapOptions.forEach( (opt) => {
					selectList.value.push( { value: opt['option'], label: opt['option'] } );
				} );
			}
		}

		// Either init requestEntity or requestPropertyValue, with minor delay
		let delayTimer = 0;
		function runRequest(term) {
			if ( dataSourceType.value !== "api" ) {
				return;
			} else if ( !term ) {
				// reset
				selectList.value = [];
				return;
			}
			clearTimeout(delayTimer);
			delayTimer = setTimeout(function() {
				if (profileId.value !== null) {
					requestEntity(term);
				} else if(valuesFromProperty.value !== null) {
					requestPropertyValue(term);
				}
			}, 350);
		}

		function requestEntity(term) {
			const actionApi = new mw.ForeignApi( props.apiUrl, { anonymous: false } );
			const apiUrlParams = {
				action: "recon-suggest-entity",
				format: "json",
				formatversion: "2",
				source: "smw",
				prefix: term
			};
			if ( profileId.value !== null ) {
				apiUrlParams["profile"] = profileId.value;
			}
			actionApi.get(apiUrlParams)
			.done( function ( data ) {
				if ( data.result == undefined ) {
					return;
				}
				selectList.value = data.result.map( (res) => ( {
					value: res.id,
					label: res.name,
					description: res.description ?? ""
				} ) );
			} );
		}

		function requestPropertyValue(term) {
			console.log("requestPropertyValue",term);
			const actionApi = new mw.ForeignApi( props.apiUrl, { anonymous: false } );
			const apiUrlParams = {
				action: "recon-suggest-propvalue",
				format: "json",
				formatversion: "2",
				source: "smw",
				property: valuesFromProperty.value,
				prefix: term
			};
			actionApi.get(apiUrlParams)
			.done( function ( data ) {
				if ( data.result == undefined ) {
					return;
				}
				selectList.value = data.result.map( (res) => ( {
					value: res.id,
					label: res.name,
					description: res.description ?? ""
				} ) );
			} );
		}

		function onUpdateSelected( v ) {
			//console.log( "onUpdateSelected", v );
			if ( componentType.value == "multiselect" ) {
				//console.log( "name", props.name );
			}
		}

		// multiselect
		const chips = ref( [] );
		const multiselectConfig = {
			boldLabel: false,
			visibleItemLimit: 10
		};
		function onMultiselectInput( value ) {
			//console.log( 'onMultiselectInput', value );
			if(dataSourceType.value == "api") {
				runRequest( value );
				// 
			} else if(props.configData?.options !== undefined) {
				// selectList.value = props.configData.options.filter( ( opt ) => opt.value == value );
			} else {
				// console.log( "no value" );
				selectList.value = [];
			}
		}

		return {
			componentType,
			selectList, // Used for select + multiselect

			lookupInput,
			delayTimer,
			runRequest,

			// Used for both select and multiselect
			onUpdateSelected,

			chips,
			onMultiselectInput,
			multiselectConfig
		}
	}
} );
</script>

<style>
</style>
