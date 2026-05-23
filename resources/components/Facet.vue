<template>
	<template v-if="componentType === 'lookup'">
		<div class="form-group form-group-v">
			<div><label>{{ label }}</label></div>
			<div>
				<cdx-lookup
					:name="name"
					v-model:selected="query[name]"
					v-model:input-value="selectInput"
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
					:multiple="multiple || false"
					@update:selected="onUpdateSelected"
					default-label="Select"
					:placeholder="label"
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
					@update:selected="onUpdateSelected"
					@input="onMultiselectInput"
					@focus="onMultiselectInput('')"
					@keyup.enter="onEnter()"
					aria-label="Select one or multiple items"
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
					class="recon-further-results"
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

		const allowEmpty = ref(true);
		if( props.configData.allowEmpty !== undefined && props.configData.allowEmpty == false ) {
			allowEmpty.value = false;
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

		// Menu list
		const selectList = ref( [] );
		initSelectList();
		function initSelectList() {
			if ((componentType.value == "select" || componentType.value == "radio") && allowEmpty.value ) {
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
		/**
		 * Run API request for 'term'
		 * @param {String} [term]
		 * @param {String} [action] see requestEntity() and requestPropertyValue()
		 */
		function runRequest(term, action) {
			if ( dataSourceType.value !== "api" ) {
				return;
			} else if ( !term ) {
				// reset?
				// selectList.value = [];
				// return;
			}
			clearTimeout(delayTimer);
			delayTimer = setTimeout(function() {
				if (profileId.value !== null) {
					requestEntity(term, 0)
					.then( (data) => {
						handleEntityResponse(data, action);
					});
				} else if(valuesFromProperty.value !== null) {
					requestPropertyValue(term, 0)
					.then( (data) => {
						handlePropertyValueResponse(data, action);
					});
				}
			}, 200);
		}

		/**
		 * Request entities from API
		 * @param {String} [term]
		 * @param {Number} [offset]
		 * @param {String} [action] What to do with the retrieved data: replace (default; replace selectList), append (append to selectList)
		 * @return Promise
		 */
		function requestEntity(term, offset) {
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

			return new Promise((resolve, reject) => {
				actionApi.get(apiUrlParams)
				.done(response => resolve(response))
				.fail(error => reject(error));
			});
		}

		function handleEntityResponseForInitialValues(data) {
			if ( data.result == undefined || data.result.length == 0 ) {
				return null;
			}
			var firstItem = data.result[0];
			return {
				value: firstItem.id,
				label: firstItem.name,
				description: firstItem.description ?? ""
			}
		}

		function handleEntityResponse(data, action) {
			if ( data.result == undefined ) {
				return;
			}
			var newSelectList = data.result.map( (res) => ( {
				value: res.id,
				label: res.name,
				description: res.description ?? ""
			} ) );

			// What to with the data?
			if (action && action === "append") {
				// Used for radio options
				// console.log( "Append options to list" );
				selectList.value.push( ...newSelectList );
			} else {
				// replace
				selectList.value = newSelectList;
				// add dummy in again
				if ( componentType.value == "radio" && allowEmpty.value ) {
					selectList.value.unshift({
						value: "",
						label: "---"
					});
				}
			}
			// Suggest more options for radio group
			signalFurtherResults(data.meta?.nextOffset);
		}

		const hasFurtherResults = ref(false);
		const nextOffset = ref(0);

		// Currently type 'radio' only
		function requestAdditionalRadioOptions() {
			if (profileId.value !== null) {
				requestEntity("", nextOffset.value )
				.then( (data) => {
					handleEntityResponse(data, "append");
				});
			} else if(valuesFromProperty.value !== null) {
				requestPropertyValue("", nextOffset.value)
				.then( (data) => {
					handlePropertyValueResponse(data, "append");
				});
			}
		}

		// Currently used for radio buttons
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

		function requestPropertyValue(term, offset) {
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

			return new Promise((resolve, reject) => {
				actionApi.get(apiUrlParams)
				.done(response => resolve(response))
				.fail(error => reject(error));
			});
		}

		function handlePropertyValueResponseForInitialValues(data) {
			if ( data.result == undefined || data.result.length == 0 ) {
				return null;
			}
			var firstItem = data.result[0];
			return {
				value: firstItem.id,
				label: firstItem.name,
				description: firstItem.description ?? ""
			}
		}

		// Handles response from requestPropertyValue
		function handlePropertyValueResponse(data, action) {
			if ( data.result == undefined ) {
				return;
			}
			var newSelectList = data.result.map( (res) => ( {
				value: res.id,
				label: res.name,
				description: res.description ?? ""
			} ) );
			if ( action && action === "append" ) {
				// Append options to list. Used for radio options
				selectList.value.push( ...newSelectList );
			} else {
				selectList.value = newSelectList;
				// dummy
				if ( componentType.value == "radio" && allowEmpty.value ) {
					selectList.value.unshift({
						value: "",
						label: "---"
					});
				}
			}
			// Suggest more options for radio group
			signalFurtherResults(data.meta?.nextOffset);
		}

		function onUpdateSelected(v) {
			//
		}

		// 
		// type 'multiselect' - init chips
		// 
		const selectInput = ref( "" );
		const chips = ref( [] );
		if (props.query[props.name]) {
			handleInitialValues();
		}
		/**
		 * Handle initial values for both API-based input and fixed options
		 */
		function handleInitialValues() {
			var target = null;
			switch(componentType.value) {
				case "select":
				case "lookup":
					var target = "selectInput";
				break;
				case "multiselect":
					var target = "chips"
				break;
			}
			if ( target == null ) {
				return;
			}

			// For any initial value, get value/label pair
			const initialValues = ( typeof props.query[props.name] == "string" )
				? [ props.query[props.name] ]
				: props.query[props.name];
			
			switch( dataSourceType.value ) {
				case "api":
					initialValues.forEach( (v) => {
						if ( props.profileId !== null ) {
							// Run request against API
							requestEntity(v, 0).then( (data) => {
								let firstResult = handleEntityResponseForInitialValues(data);
								if( target == "chips" ) {	
									chips.value.push( firstResult ?? { value: v, label: v } );
								} else {
									selectInput.value = firstResult['label'] ?? v;
								}
							});
						} else if(valuesFromProperty.value !== null) {
							// @todo pages with display titles
							requestPropertyValue(v, 0).then( (data) => {
								let firstResult = handlePropertyValueResponseForInitialValues(data, v);
								if( target == "chips" ) {
									chips.value.push( firstResult ?? { value: v, label: v } );
								} else {
									selectInput.value = firstResult['label'] ?? v;
								}
							});
						}
					});
				break;
				case "options":
					// Fixed options or mapped options
					const initialOptions = props.configData.options ?? props.configData.mapOptions ?? [];
					initialValues.forEach( (v) => {
						initialOptions.find( (opt) => {
							if (opt['value'] == v ) {
								if( target == "chips" ) {
									chips.value.push(opt);
								} else {
									selectInput.value = opt['label'];
								}
							}
						});
					} );
				break;
			}
		}

		const multiselectConfig = {
			boldLabel: false,
			visibleItemLimit: 15
		};

		function onMultiselectInput(value) {
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

		// type 'radio' - init, no trigger needed
		if (componentType.value === "radio") {
			initRadioGroup();
		}
		function initRadioGroup() {
			if (profileId.value !== null) {
				requestEntity("")
				.then( (data) => {
					handleEntityResponse(data);
				});
			} else if(valuesFromProperty.value !== null) {
				requestPropertyValue("")
				.then( (data) => {
					handlePropertyValueResponse(data);
				});
			}
		}

		// type 'text', 'multiselect'
		function onEnter() {
			// Let the parent initiate query on enter
			emit('run-query', 0 );
		}

		return {
			componentType,
			selectList, // Used for select + multiselect

			// dataSourceType?,
			allowEmpty,

			runRequest,
			requestEntity,

			onUpdateSelected,

			selectInput,
			chips,
			onMultiselectInput,
			multiselectConfig,

			requestAdditionalRadioOptions,

			hasFurtherResults,
			nextOffset,

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

.recon-further-results {
	display:block;
	width: 100%;
	font-variant: all-small-caps;
}

.recon-faceted-search .cdx-menu-item__text__description {
	font-size: .8rem;
}

</style>
