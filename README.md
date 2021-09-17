# arche-openrefine

Implementation of the [OpenRefine APIs](https://reconciliation-api.github.io/specs/latest/) for the [ARCHE Suite](https://github.com/acdh-oeaw/arche-core).

Allows to use the ARCHE Suite as a reconciliation backend for the [OpenRefine](https://openrefine.org/).

## Supported features

* Reconcile endpoint based on the full text search over the repository content
* Preview endpoint
  Implementing as a a redirection to the {repositoryApiBaseURL}/{resourceId}/metadata
* Suggest endpoint for entities, properties and types

### Scoring algorithm

* As match is made against a metadata property value first a property match score is computed.
    * Property weights are defined in the `propertyWeights` configuration property (see the `config-sample.yaml`).
      If property weight isn't provided in the config, it's assumed to be `1`.
    * If match is only partial (query phrase is only a part of the property value),
      the weight is multiplied by the `partialMatchCoefficient` configuration property.
* On a resource level the score is the sum of scores for all of its property matches.

## Installation

* Obtain [the composer](https://getcomposer.org/).
* ```bash
  composer require acdh-oeaw/arche-openrefine
  ln -s vendor/acdh-oeaw/arche-openrefine/index.php index.php
  cp vendor/acdh-oeaw/arche-openrefine/config-sample.yaml config.yaml
  cp vendor/acdh-oeaw/arche-openrefine/.htaccess .htaccess # apache-only
  ```
* Adjust settings in `config.yaml`

