The **Reconciliation API** extension (_beta_) is an extension for MediaWiki and Semantic MediaWiki that has two allied purposes. First, it lets you enable and manage API services for entity reconciliation following the W3C specifications of the [Reconciliation API v0.2](https://reconciliation-api.github.io/specs/0.2/). It aims to help you open up your wiki's data for linking, discovery and collaboration, for instance by facilitating connections to tools like [OpenRefine](https://openrefine.org/). Second, using this framework as a basis, it also allows you to set up and finetune API endpoints for autocompletion, whether for external or wiki internal uses.

## Features in a nutshell
* Current focus is on two ways of setting up your data: MediaWiki core, using native methods only, and Semantic MediaWiki (tested with v4.1.3 only)
* Special handling for matching with Full-Text Search
* About five Action API modules, incl. metadata
* Many configuration options available
* Dedicated API profiles managed through JSON schemas in the wiki (namespace: `Recon`). Comes with simple JSON validation
* A parser function (`#recon-search`) for creating typeahead widgets
* Redirect service through a special page in the wiki. Intended for handling situations in which pages cannot be accessed directly or require an additional check.

## Installation
- Download and add the `ReconciliationAPI` folder to the `extensions` folder
- Add the following to your local settings file:
```
wfLoadExtension( 'ReconciliationAPI' );
```
- Check `Special:Version` to verify that the extension has been installed.
- Done

## Configuration and further documentation
After installing the software, head over to `Special:ReconciliationAPI` for the usage guide.

## Credits
At an early stage of development, this extension borrowed heavily from the autocompletion features of the [Page Forms](https://www.mediawiki.org/wiki/Extension:Page_Forms) extension. 
