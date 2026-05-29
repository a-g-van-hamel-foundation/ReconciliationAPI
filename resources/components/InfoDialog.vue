<template>
	<div class="recon-dialog-wrapper">
		<a @click="isOpen = true" class="cdx-docs-link" :aria-label="$i18n('recon-faceted-info-dialog-icon-aria-label')">
			<cdx-icon :icon="cdxIconInfo"></cdx-icon>
		</a>

		<cdx-dialog
			v-model:open="isOpen"
			:title="title"
			:use-close-button="true"
			@default="isOpen = false"
		>
			<p v-html="parsedComment"></p>
		</cdx-dialog>
	</div>
</template>

<script>
const { defineComponent, ref, reactive } = require("vue");
const { CdxDialog, CdxButton, CdxIcon } = require( "@wikimedia/codex" );
const { cdxIconInfo } = require( "./icons.json" );

module.exports = defineComponent( {
	name: "InfoDialog",
	components: {
		CdxDialog, CdxButton, CdxIcon
	},
	props: {
		comment: { type: "String", default: "" },
		title: { type: "String", default: "Info" }
	},
	setup(props, { emit } ) {
		const isOpen = ref( false );

		const parsedComment = ref("");
		parseWikitext();
		function parseWikitext() {
			new mw.Api().parse(props.comment)
			.done( (rawData) => {
				parsedComment.value = rawData;
			});
		}

		return {
			isOpen,
			parsedComment,
			cdxIconInfo
		}
	}
});
</script>
