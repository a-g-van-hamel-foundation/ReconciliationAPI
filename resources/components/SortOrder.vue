<template>
	<div class="recon-sort">

		<!-- sort -->
		<template v-if="sortOptions.length > 0">
			<label>{{ $i18n('recon-faceted-sort-by').text() }}</label>
			<cdx-select
				v-model:selected="sort"
				:menu-items="selectList || []"
				default-label="Select"
				:placeholder="label"
				@update:selected="onUpdateSelectedSort"
			></cdx-select>
		</template>

		<!-- order -->
		<div class="recon-order" v-if="sortOptions.length > 0">
			<cdx-button
				@click="onUpdateSelectedOrder(order)"
				v-model:selected="order"
				weight="quiet"
				size="x-small"
			>
				<template v-if="order=='asc'">
					<cdx-icon :icon="cdxIconDownTriangle" size="x-small"></cdx-icon>
				</template>
				<template v-else>
					<cdx-icon :icon="cdxIconUpTriangle" size="x-small"></cdx-icon>
				</template>
			</cdx-button>
		</div>
	</div>
</template>

<script>
const { defineComponent, computed, ref, reactive, watch } = require("vue");
const { CdxSelect, CdxButton, CdxIcon } = require( "@wikimedia/codex" );
const { cdxIconDownTriangle, cdxIconUpTriangle } = require( './icons.json' );

module.exports = defineComponent( {
	name: "SortOrder",
	components: {
		CdxSelect,
		CdxButton,
		CdxIcon
	},
	props: {
		sortOptions: { type: "Array", default: [] },
		sort: { type: "String", default: "" },
		order: { type: "String", default: "asc" }
	},
	emits: [ 'update-sort', 'update-order' ],
	setup(props, { emit } ) {

		// sort:
		const label = ref("");
		const selectList = ref([]);
		if ( props.sortOptions ) {
			selectList.value = props.sortOptions;
		}
		// todo
		/*
		watch( () => props.query, ( n ) => {
			console.log( "typeof newVal", typeof n );
			if ( n == null ) {
				//console.log("sort newVal",newVal);
				return;
			}
			// sort
			//selectList.value = n.map( option => {
				//return { label: option.label, value: option.value };
			//} );
			if ( n.length > 0 ) {
				label.value = n[0].label;
			}
		}, { immediate: true } );
		*/

		function onUpdateSelectedSort( n ) {
			// Let the parent handle this
			emit( "update-sort", n );
		}

		function onUpdateSelectedOrder( currVal ) {
			// Let the parent handle this
			var newVal = ( currVal === "desc" ) ? "asc" : "desc";
			console.log( "onUpdateSelectedOrder - current", currVal );
			console.log( "onUpdateSelectedOrder - new", newVal );
			emit( "update-order", newVal );
		}

		return {
			label,
			selectList,
			onUpdateSelectedSort,
			onUpdateSelectedOrder,

			cdxIconDownTriangle,
			cdxIconUpTriangle
		}
	}
} );
</script>

<style>

</style>
