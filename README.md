The **Reconciliation API** extension is an extension for MediaWiki and Semantic MediaWiki that has two allied purposes. First, it lets you enable and manage API services for entity reconciliation following the W3C specifications of the [Reconciliation API v0.2](https://reconciliation-api.github.io/specs/0.2/). It aims to help you open up your wiki's data for linking, discovery and collaboration, for instance by facilitating connections to tools like [OpenRefine](https://openrefine.org/). Second, using this framework as a basis, it also allows you to set up and finetune API endpoints for autocompletion, whether for external or wiki-internal usage.

## Features in a nutshell
* Current focus is on two ways of setting up your data: MediaWiki core, using native methods only, and Semantic MediaWiki
* Special handling for matching with Full-Text Search
* Six Action API modules, incl. metadata (for better transparency)
* Many configuration options available
* Dedicated API profiles managed through JSON schemas in the wiki (namespace: `Recon`). Comes with simple JSON validation
* A parser function (`#recon-search`) for creating typeahead widgets
* Redirect service through a special page in the wiki. Intended for handling situations in which pages cannot be accessed directly or require an additional check.
* A testbench (`Special:ReconTestbench`) modelled after https://reconciliation-api.github.io/testbench/0.2/#/client to help site admins set up, test and debug their reconciliation service.

## Installation
- Download and add the `ReconciliationAPI` folder to the `extensions` folder
- Add the following to your local settings file:
```
wfLoadExtension( 'ReconciliationAPI' );
```
- Check `Special:Version` to verify that the extension has been installed.
- Done

## Configuration and usage
After installing the software, head over to `Special:ReconciliationAPI` for a detailed usage guide. To read a version of the guide online, which may not necessarily reflect the present version of the software, go to [https://codecs.vanhamel.nl/Special:ReconciliationAPI](https://codecs.vanhamel.nl/Special:ReconciliationAPI).

## Credits
At an early stage of development, this extension borrowed heavily from the autocompletion features of the [Page Forms](https://www.mediawiki.org/wiki/Extension:Page_Forms) extension.

## Changes
* 0.4 (June 2026) - Introduces parser function `#recon-faceted-search`, which enables faceted search for Semantic MediaWiki based on a JSON config in the wiki that lets you define its structure and behaviour, including the base query (or queries) that should always apply and the facets you want to include. Reconciliation profiles can be (re)used for facets that rely on API calls to fetch data in response to queries. If URL navigation is enabled, a parser function `#recon-faceted-search-link` is available to help you build page links with preset filters. Full documentation about setup and usage can be found on `Special:ReconciliationAPI`. Minor changes: reconciliation profiles now support sort/order; updated MW namespacing and one obsolete method name; reorganised parser functions.
* 0.3 (April 2026) - Bumped minimum MW version to 1.43. Added testbench (Special:ReconTestbench). Added `query` param to recon-suggest-entity, which accepts a base64-encoded semantic query; added `#recon-smwquery-url` parser function to help with the encoding. Updated documentation. Improved handling of substring in 'single page restriction'. Added `$wgReconAPIQueryTrigger` (d9a5a40) to optionally allow for suggestions if the prefix is empty (SMW only). Minor changes.
* 0.2 (September 2025) - Revised SMW implementation of behaviour expected in section 6.4 (see below), using a separate query to ensure any identifier match is the first to be returned. Fixes.
* 0.1 (May 2025) - Added additional parameter to the TypeaheadSearch widget, `internal` (boolean), to allow for internal requests to the API. Added support for "supplying an entity identifier as prefix should return this entity in the suggest response" (section 6.4). Revised Full-Text Search helper to better guard queries against a bug in SMW that can cause RuntimeException errors. Fixed Page Forms support because of typo 'dispaytitle' for 'displaytitle' in `ReconUtils`.
* 0.1 beta - first release, still beta.

## Other links
- [The extension on mediawiki.org](https://www.mediawiki.org/wiki/Extension:ReconciliationAPI)
