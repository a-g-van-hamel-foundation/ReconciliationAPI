<template>

	<section class="recon-settings">
		<h2>Settings</h2>
		<div class="form-group">
			<label>Endpoint:</label>
			<div>{{ reconApiUrl }}</div>
		</div>
		<div class="form-group">
			<label>Profile ID</label>
			<cdx-text-input
				v-model="profileId"
				name="profileId"
			></cdx-text-input>
		</div>
		<div class="form-group">
			<label>or Source:</label>
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
			<div class="recon-radios-horizontal">
				<cdx-radio
					v-for="radio in substrPatternList"
					:key="'radio-' + radio.id"
					v-model="substrPattern"
					name="source"
					:input-value="radio.id"
				>{{ radio.name }}</cdx-radio>
			</div>

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
					:profile-id="profileId"
					:substr-pattern="substrPattern"
				></test-bench-reconcile>
			</template>
			<template v-if="tab.value == 'suggest'">
				<test-bench-suggest
					:api-url="reconApiUrl"
					:source="source"
					:profile-id="profileId"
					:substr-pattern="substrPattern"
				></test-bench-suggest>
			</template>
			<template v-if="tab.value == 'extend'">
				<test-bench-extend
					:api-url="reconApiUrl"
					:source="source"
					:profile-id="profileId"
					:substr-pattern="substrPattern"
				></test-bench-extend>
			</template>
	</section>

</template>

<script>
const { defineComponent, computed, ref, reactive, watch } = require("vue");
const TestBenchReconcile = require("./TestBenchReconcile.vue");
const TestBenchSuggest = require("./TestBenchSuggest.vue");
const TestBenchExtend = require("./TestBenchExtend.vue");
const { CdxButton, CdxButtonGroup, CdxToggleButtonGroup, CdxIcon, CdxTabs, CdxTab, CdxTextInput, CdxLookup, CdxField, CdxRadio, CdxSearchInput } = require( "@wikimedia/codex" );
//const { cdxIconAdd, cdxIconClose } = require( './icons.json' );

module.exports = defineComponent( {
	name: "TestBench",
	components: {
		TestBenchReconcile, TestBenchSuggest, TestBenchExtend,
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
		const reconApiUrl = ref( mw.config.get("wgServer") + (mw.config.get("wgScriptPath") || "") + "/api.php" );

		const useSMW = ref( props.configData.smw == "1" );
		const useFTS = ref( props.configData.fts == "1" );
		const source = ref( "mw" );
		const sourceList = reactive( [] );
		if ( useSMW ) {
			source.value = "smw";
			sourceList.push( { id: "smw", name: "Semantic MediaWiki (smw)" } );
			sourceList.push( { id: "mw", name: "MediaWiki core (mw)" } );
		} else {
			sourceList.push( { id: "mw", name: "MediaWiki core (mw)" } );
		}

		const profileId = ref( "" );

		const substrPattern = ref( useFTS ? "tokenprefix" : "stringprefix" );
		const substrPatternList = reactive( [
			{ id: "stringprefix", name: "stringprefix" },
			{ id: "tokenprefix", name: "tokenprefix" },
			{ id: "allchars", name: "allchars" }
		] );

		return {
			tabsData,
			selectedTab,
			onChangeTabs,
			getTabStyle,

			reconApiUrl,
			source,
			sourceList,
			profileId,

			substrPattern,
			substrPatternList
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
	.cdx-text-input {
		min-width: 75px;
	}
}

.recon-radios-horizontal {
  display: flex;
  gap: 1rem;
  .cdx-radio {
	margin-bottom: 0;
  }
}

.recon-row {
	h2, h3 {
		padding-top: 0;
	}
}

.loader {
	width: 48px;
	height: 48px;
	border: 5px solid #E6D8D8;
	border-bottom-color: rgb(255, 255, 255);
	border-bottom-color: transparent;
	border-radius: 50%;
	display: inline-block;
	box-sizing: border-box;
	animation: rotation 1s linear infinite;
}
@keyframes rotation {
	from {
		transform: rotate(0deg);
	}
	to {
		transform: rotate(360deg);
	}
}

</style>
