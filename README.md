# CDASH Theme
The Cambridge Digital Architecture Survey and History project extends Omeka-S with a schema of Place Items, Document Items and Folders that model the physical filing scheme of the physical archtectural survey at the Cambridge Historical Commission.  You can explore the production CDASH Omeka-S site with several introductory pages about the project at [cdash.cambridgema.gov(http://cdash.cambridgema.gov)].


This theme implements the map interface and various modifications to the standard Omeka-S Show and Browse pages. More information on the CDASH schema can be found in the [CDASH Documentation Repo](https://github.com/C-Dash/Documentation) which can also be [Viewed as HTML](https://c-dash.github.io/Documentation).  There is no CDASH Module, but the CDASH Omeka instance and routine administration function does rely on several other modules, as follows:

* Mapping (version 1.1)
* Custom Vocabulary
* Metadata Browse
* Value Suggest
* PDF Embed
* Numeric Data Types

To play around with this theme you may want to load a sample dataset which can be found along with documentation of the ]CDASH Accession Workflow (https://c-dash.github.io/Documentation/accession/index.htm)]

## Installation
Install in the normal way [Omeka S User Manual instructions for installing themes](https://omeka.org/s/docs/user-manual/sites/site_theme/#installing-themes).


## Disclaimer
The CDASH theme is way more complicated than a standard Omeka-S theme.  It breaks a few rules in order to maintain and audit logical consistency between Place and Document items.  Normally these functions would be implemented in an Omeka-S module that modifies Omeka's doctrine schema.  This module uses SQL, instead.

Please try it out and evaluate this project for yourself.  The developers make no warranty regarding the outcome, but we are interested in hearing about your success or difficulty using the normal github Issue tracker.

This theme was recently re-factored and enhanced for mobile screens using Claude Code Opus 5.5.



