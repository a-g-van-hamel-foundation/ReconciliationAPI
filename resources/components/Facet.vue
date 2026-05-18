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
					@focus="runRequest('')"
					:placeholder="label"
					:clearable="true"
					@keyup.enter="onEnter()"
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
					@focus="onMultiselectInput('')"
					@keyup.enter="onEnter()"
				>
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
					@keyup.enter="onEnter()"
				></cdx-text-input>
			</div>
		</div>
	</template>
	<template v-else-if="componentType === 'radio'">
		<div class="form-group form-group-v">
			<div><label>{{ label }}</label></div>
			<div class="recon-radio-group" role="radiogroup">
				<cdx-radio
					v-for="(radio, index) in selectList"
					:key="'radio-' + radio.name + radio.value + index"
					v-model="query[name]"
					:name="name"
					:input-value="radio.value"
					@update:model-value="onUpdateSelected"
				>
					<span v-html="radio.label"></span>
				</cdx-radio>
				<!-- CdxButton -->
				<a v-if="hasFurtherResults"
					@click="requestAdditionalRadioOptions"
				>
					<cdx-icon :icon="cdxIconDownTriangle" size="x-small"></cdx-icon> More&hellip;
				</a>
			</div>
		</div>
	</template>

</template>

<script>
const { defineComponent, computed, ref, reactive, watch } = require("vue");
const { CdxTextInput, CdxSelect, CdxLookup, CdxMultiselectLookup, CdxRadio, CdxIcon } = require( "@wikimedia/codex" );
const { cdxIconDownTriangle } = require( './icons.json' );

module.exports = defineComponent( {
	name: "Facet",
	components: {
		//CdxButton
		CdxTextInput, CdxSelect, CdxLookup, CdxMultiselectLookup, CdxRadio, CdxIcon,
		// CdxField, CdxSearchInput
	},
	props: {
		name: { type: "String", default: "" },
		label: { type: "String", default: "" },
		inputType: { type: "String", default: "text" },
		apiUrl: { type: "String", default: "" },
		query: { type: "Object", default: {} },
		configData: { type: "Object", default: {} }
	},
	emits: [ 'run-query' ],
	setup(props, { emit } ) {
	
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

		const hasFurtherResults = ref(false);
		const nextOffset = ref(0);

		// Menu list
		const selectList = ref( [] );
		initSelectList();
		function initSelectList() {
			if ( componentType.value == "select" || componentType.value == "radio" ) {
				// Start with dummy value
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
				//selectList.value = [];
				//return;
			}
			clearTimeout(delayTimer);
			delayTimer = setTimeout(function() {
				if (profileId.value !== null) {
					requestEntity(term);
				} else if(valuesFromProperty.value !== null) {
					requestPropertyValue(term);
				}
			}, 200);
		}


		function requestEntity(term, offset, action) {
			const actionApi = new mw.ForeignApi( props.apiUrl, { anonymous: false } );
			const apiUrlParams = {
				action: "recon-suggest-entity",
				format: "json",
				formatversion: "2",
				source: "smw",
				limit: props.configData.resultLimit ?? "25",
				offset: offset ?? 0,
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
				var newSelectList = data.result.map( (res) => ( {
					value: res.id,
					label: res.name,
					description: res.description ?? ""
				} ) );
				if ( action && action === "append" ) {
					// Used for radio options
					// console.log( "Append options to list" );
					selectList.value.push( ...newSelectList );
				} else {
					// replace
					selectList.value = newSelectList;
					// add dummy in again
					if ( componentType.value == "radio" ) {
						selectList.value.unshift({
							value: "",
							label: "---"
						});
					}
				}
				// Suggest more options for radio group
				signalFurtherResults(data.meta?.nextOffset);
			} );
		}

		// Currently radio only
		function requestAdditionalRadioOptions() {
			if (profileId.value !== null) {
				requestEntity("", nextOffset.value, "append" );
			} else if(valuesFromProperty.value !== null) {
				requestPropertyValue("", nextOffset.value, "append" );
			}
		}

		// Used for radio buttons
		function signalFurtherResults(nextOffsetFromAPI) {
			if ( componentType.value == "radio" ) {
				if ( nextOffsetFromAPI > 0 ) {
					// add 
					//console.log( "More results..." );
					hasFurtherResults.value = true;
					nextOffset.value = nextOffsetFromAPI;
				} else {
					hasFurtherResults.value = false;
					nextOffset.value = 0;
				}
			}
		}

		function requestPropertyValue(term, offset, action) {
			// console.log("requestPropertyValue",term);
			const actionApi = new mw.ForeignApi( props.apiUrl, { anonymous: false } );
			const apiUrlParams = {
				action: "recon-suggest-propvalue",
				format: "json",
				formatversion: "2",
				source: "smw",
				property: valuesFromProperty.value,
				prefix: term,
				offset: offset ?? 0
			};
			actionApi.get(apiUrlParams)
			.done( function ( data ) {
				if ( data.result == undefined ) {
					return;
				}
				var newSelectList = data.result.map( (res) => ( {
					value: res.id,
					label: res.name,
					description: res.description ?? ""
				} ) );
				if ( action && action === "append" ) {
					// Used for radio options
					//console.log( "Append options to list" );
					selectList.value.push( ...newSelectList );
				} else {
					selectList.value = newSelectList;
					if ( componentType.value == "radio" ) {
						selectList.value.unshift({
							value: "",
							label: "---"
						});
					}
				}
				// Suggest more options for radio group
				signalFurtherResults(data.meta?.nextOffset);
			} );
		}

		function onUpdateSelected(v) {
			// Primarily for dev
			//console.log( "onUpdateSelected", v );
			switch( componentType.value ) {
				case "multiselect":
				break;
				case "radio":
				break;
			}
		}

		// multiselect
		const chips = ref( [] );
		const multiselectConfig = {
			boldLabel: false,
			visibleItemLimit: 15
		};

		function onMultiselectInput(value) {
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

		function onEnter() {
			//console.log( "enter!" );
			// Let the parent initiate query on enter
			emit('run-query', 0 );
		}

		// Fire 'radio' - does not need to wait for a trigger
		if ( componentType.value === "radio" ) {
			initRadioGroup();
		}
		function initRadioGroup() {
			if (profileId.value !== null) {
				requestEntity("");
			} else if(valuesFromProperty.value !== null) {
				requestPropertyValue("");
			}
		}

		return {
			componentType,
			selectList, // Used for select + multiselect

			hasFurtherResults,
			nextOffset,

			lookupInput,
			delayTimer,
			runRequest,

			requestAdditionalRadioOptions,

			// Used for both select and multiselect
			onUpdateSelected,

			chips,
			onMultiselectInput,
			multiselectConfig,

			onEnter,

			cdxIconDownTriangle
		}
	}
} );
</script>

<style lang="less">
.recon-radio-group {
	max-height: 10rem;
	overflow-y: auto;
	line-height: 1.5rem;
	.cdx-radio {
		margin-bottom: 2px;
	}
}
</style>
