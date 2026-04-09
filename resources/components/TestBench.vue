<template>

	<section class="recon-settings">
		<div class="form-group">
			<label>Endpoint:</label>
			<div>{{ reconApiUrl }}</div>
		</div>
		<div class="form-group">
			<label>Source:</label>
			<div class="recon-radios-horizontal">
				<cdx-radio
					v-for="radio in sourceList"
					:key="'radio-' + radio.id"
					v-model="source"
					name="source"
					:input-value="radio.id"
				>{{ radio.name }}</cdx-radio>
			</div>
		</div>
		<div class="form-group">
			<label>Substring pattern</label>
			<div class="">tokenprefix</div>
		</div>
	</section>

	<section class="recon-tabs">
		<cdx-toggle-button-group
			v-model="selectedTab"
			:buttons="tabsData"
			@update:model-value="onChangeTabs"
		></cdx-toggle-button-group>
	</section>

	<section
		v-for="(tab,index) in tabsData"
		:key="index"
		:name="tab.value"
		:label="tab.label"
		:style="getTabStyle(tab.value)"
	>
			<template v-if="tab.value == 'reconcile'">
				<test-bench-reconcile
					:api-url="reconApiUrl"
					:source="source"
				></test-bench-reconcile>
			</template>
			<template v-if="tab.value == 'suggest'">
				<test-bench-suggest
					:api-url="reconApiUrl"
					:source="source"
				></test-bench-suggest>
			</template>
			<template v-if="tab.value == 'extend'">
				Extend
			</template>
	</section>

</template>

<script>
const { defineComponent, computed, ref, reactive, watch } = require("vue");
const TestBenchReconcile = require("./TestBenchReconcile.vue");
const TestBenchSuggest = require("./TestBenchSuggest.vue");
const { CdxButton, CdxButtonGroup, CdxToggleButtonGroup, CdxIcon, CdxTabs, CdxTab, CdxTextInput, CdxLookup, CdxField, CdxRadio, CdxSearchInput } = require( "@wikimedia/codex" );
//const { cdxIconAdd, cdxIconClose } = require( './icons.json' );

module.exports = defineComponent( {
	name: "TestBench",
	components: {
		TestBenchReconcile, TestBenchSuggest,
		CdxButton, CdxButtonGroup, CdxToggleButtonGroup, 
		CdxIcon, CdxTabs, CdxTab,
		CdxTextInput, CdxLookup, CdxField, CdxRadio, CdxSearchInput
	},
	props: {
		configData: { type: Object, default: {} }
	},
	setup(props, context) {

		const tabsData = [
			{ value: 'reconcile', label: 'Reconcile' },
			{ value: 'suggest', label: 'Suggest' },
			{ value: 'extend', label: 'Extend' }
		];
		const selectedTab = ref( "reconcile" );
		function onChangeTabs(v) {
			selectedTab.value = v;
		}
		function getTabStyle(v) {
			return v === selectedTab.value ? "display:block;" : "display:none;";
		}

		// General settings
		const source = ref( "smw" );
		const sourceList = reactive( [ { id: "smw", name: "Semantic MediaWiki (smw)" }, { id: "mw", name: "MediaWiki core (mw)" } ] );

		const reconApiUrl = ref( mw.config.get("wgServer") + (mw.config.get("wgScriptPath") || "") + "/api.php" );

		return {
			tabsData,
			selectedTab,
			onChangeTabs,
			getTabStyle,
			source,
			sourceList,
			reconApiUrl
		}
	}
} );
</script>

<style lang="less">
.recon-tabs {
	margin-bottom: 1rem;
}

.recon-settings {
	width: 100%;
	padding: .7rem 1rem;
	margin-bottom: 1rem;
	background-color: #d9e3e1;
}

.form-group {
	display: flex;
	flex-wrap: wrap;
	width:100%;
	& > label:first-child {
		width:7rem;
		font-variant: all-small-caps;
	}
	& > *:last-child {
		width: calc(100% - 7rem);
	}
	margin-bottom: .5rem;
}

.recon-radios-horizontal {
  display: flex;
  gap: 1rem;
  .cdx-radio {
	margin-bottom:0;
  }
}

</style>
