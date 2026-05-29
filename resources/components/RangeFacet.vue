<template>

	<template v-if="componentType === 'numberrange' || componentType === 'daterange'">
			<div class="form-group form-group-v">
				<div><label>{{ label }}</label></div>
				<div class="text-input-wrapper">

					<cdx-text-input
						:name="name1"
						:input-type="inputType"
						v-model="query[name1]"
						:placeholder="placeholder1"
						@keyup.enter="onEnter()"
					></cdx-text-input>

					<div> – </div>

					<cdx-text-input
						:name="name2"
						:input-type="inputType"
						v-model="query[name2]"
						:placeholder="placeholder2"
						@keyup.enter="onEnter()"
					></cdx-text-input>
				</div>
			</div>
	</template>

</template>

<script>
/**
 * @todo other types - maybe add a dual range slider
 */
const { defineComponent, computed, ref, reactive, watch } = require("vue");
const { CdxTextInput, CdxSelect, CdxLookup, CdxMultiselectLookup, CdxRadio, CdxIcon } = require( "@wikimedia/codex" );
///const { cdxIconDownTriangle } = require( './icons.json' );

module.exports = defineComponent( {
	name: "Facet",
	components: {
		CdxTextInput
	},
	props: {
		componentType: { type: "String", default: "rangetext" },
		query: { type: "Object", default: {} },
		name1: { type: "String", default: null },
		name2: { type: "String", default: null },
		label: { type: "String" },
		placeholder1: { type: "String", default: null },
		placeholder2: { type: "String", default: null },
		inputType: { type: "String", default: "number" }
	},
	emits: ['on-enter'],
	setup(props, {emit} ) {

		function onEnter() {
			// Let the parent handle this
			emit('on-enter' );
		}

		return {
			onEnter
		}
	}
} );
</script>

<style lang="less">
.text-input-wrapper {
	display: flex;
	& > .cdx-text-input {
		width: 100%;
	}
}

</style>
