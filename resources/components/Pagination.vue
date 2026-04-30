<template>
<!-- Do not show if there are no other pages -->
<ul class="recon-pagination" v-if="pageCount > 1">
	<li v-for="page in paginationList"
		:class="page.class">
		<button class="page-link" 
			:data-target-offset="page.targetOffset"
			:data-indicator="page.indicator"
			@click="updateOffset(page.targetOffset)"
		>{{ page.text }}</button>
	</li>
</ul>

</template>

<script>
const { defineComponent, computed, ref, reactive, watch } = require("vue");
// const { CdxToggleButtonGroup } = require( '@wikimedia/codex' );

module.exports = defineComponent( {
	name: "Pagination",
	components: {
	},
	props: {
		offset: { type: "Number", default: 10 },
		limit: { type: "Number", default: 10 },
		total: { type: "Number", default: 125 },
		queryContinueOffset: { type: "Number" },
		maxPages: { type: "Number", default: 5 }
	},
	emits: [ 'update-offset', 'scroll-into-view' ],
	setup(props, { emit } ) {
		const items = reactive( [] );
		const pageCount = ref(0);
		const paginationList = reactive( [] );
		buildPaginationList( props.total, props.limit, props.offset );

		function buildPaginationList( total, limit, offset ) {
			pageCount.value = Math.ceil( total / limit );

			var maxPages = props.maxPages;
			[ pageStart, pageEnd, currPage, middleNumber ] = getPageLimitInfo( offset, limit, pageCount.value, maxPages );

			// Previous indicator
			paginationList.push({
				class: ( offset !== 0 ) ? "page-item" : "page-item disabled",
				targetOffset: ( offset !== 0 ) ? (offset - Number(limit)) : null,
				text: "❮",
				indicator: "sibling"
			});

			// Possibly, add first page and ellipsis
			if ( currPage > middleNumber && pageStart !== 0 ) {
				paginationList.push(
					{
						class: "page-item",
						targetOffset: 0,
						text: "1",
						indicator: "number"
					},
					{
						class: "page-item disabled",
						targetOffset: null,
						text: "…",
						indicator: "ellipsis"
					}
				);
			}

			// Pages
			for (var i = pageStart; i < pageEnd; i++) {
				var targetOffset = i * limit;
				var visibleNumber = (i + 1);
				var htmlClass = targetOffset == offset ? "page-item active" : "page-item";

				paginationList.push({
					class: htmlClass,
					targetOffset: targetOffset,
					text: visibleNumber,
					indicator: "number"
				});
			}

			// Possibly, add ellipsis and last page
			var cutoff = pageCount.value - ( maxPages - middleNumber );
			if ( pageEnd == pageCount.value  ) {
				// Do nothing
			} else if ( currPage < cutoff ) {
				var lastOffset = ( pageCount.value - 1 ) * limit;
				//var lastOffset = ( pageCount.value ) * limit;
				if ( currPage < ( cutoff - 1 ) ) {
					// Ellipsis. No need for it in penultimate position.
					paginationList.push(
						{ class: "page-item disabled", targetOffset: null, indicator: "ellipsis", text: "…" }
					);
				}
				// If not already shown (pageEnd), show last page
				paginationList.push(
					{ class: "page-item", indicator: "number", targetOffset: lastOffset, text: pageCount.value }
				);
			}

			// Next indicator
			var projectedNextOffset = offset + Number(limit);
			// ? var lastOffset = ( pageCount.value - 1 ) * limit;
			var lastPossibleOffset = pageCount.value * limit;
			paginationList.push({
				class: ( projectedNextOffset !== lastPossibleOffset ) ? "page-item" : "page-item disabled",
				targetOffset: ( projectedNextOffset !== lastPossibleOffset ) ? projectedNextOffset : null,
				text: "❯",
				indicator: "sibling"
			});
		}

		function getPageLimitInfo( offset, limit, totalPageCount, maxPages ) {
			// Restrict number of page tabs
			var currPage = Math.ceil(offset / limit);
			//var maxPages = 5;
			var middleNumber = Math.floor(maxPages / 2);
			if ( maxPages == null || maxPages == "" ) {
				// No restrictions
				var pageStart = 0;
				var pageEnd = totalPageCount;
			} else if (totalPageCount > maxPages) {
				if (currPage < middleNumber) {
					// starting out, eg if 4 < 5
					var pageStart = 0;
					var pageEnd = maxPages;	
				} else {
					// start sliding to new start
					var pageStart = currPage - middleNumber;
					var pageEnd = currPage + ( maxPages - middleNumber );
					// towards the end
					if ( pageEnd > totalPageCount ) {
						var pageEnd = totalPageCount;
					}
				}
			} else {
				// Fewer results. No need for special handling.
				var pageStart = 0;
				var pageEnd = totalPageCount;
			}
			return [ pageStart, pageEnd, currPage, middleNumber ];
		}

		function updateOffset( targetOffset ) {
			if ( targetOffset == props.offset || targetOffset == null ) {
				// If no change in offset, no update necessary
				return;
			}
			// Let the parent component work this out:
			emit('update-offset', targetOffset);
			emit('scroll-into-view');
		}

		return {
			items,
			pageCount,
			paginationList,
			updateOffset
		}
	}
} );
</script>

<style lang="less">
.recon-pagination {
	display: flex;
	flex-wrap: wrap;
	margin: 0 !important;
	padding-left: 0;
	list-style: none;
	border-radius: 0.25rem;

	.page-item .page-link {
		position: relative;
		display: block;
		cursor: pointer;
		padding: 0.5rem 0.75rem;
		margin-left: 0;
		line-height: 1.25;
		text-decoration: none;
		color: #5A7179;
		background-color: #fff;
		border: 1px solid #dee2e6;
		border-radius: 0;
		/*border-top-left-radius: 0.25rem;
		border-bottom-left-radius: 0.25rem;*/
		&:focus {
			outline: 0;
  			box-shadow: 0 0 0 0.2rem rgba(0,123,255,0.25);
		}
		&:hover {
			z-index: 2;
			color: #10345a;
			text-decoration: none;
			background-color: #e9ecef;
			/*border-color: #dee2e6;*/
		}
	}
	.page-item.active .page-link {
		z-index: 3;
		color: #fff;
		background-color: #5A7179;
		border-color: #5A7179;
	}
	.page-item.disabled .page-link {
		color:grey;
	}
}

</style>
