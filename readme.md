# GAR API Bundle

A Symfony bundle to ease the interaction with the GAR API.

## Installation

Add an extra Symfony endpoint to allow the execution of the recipe:

You can run the following command:

```shell
composer config --json extra.symfony.endpoint '["https://api.github.com/repos/edumedia-sciences/gar-api-bundle-recipe/contents/index.json", "flex://defaults"]' --merge
```

It should now look like this:

```json5
// composer.json
{
  // (...)
  "extra": {
    "symfony": {
      // (...)
      "endpoint": [
        "https://api.github.com/repos/edumedia-sciences/gar-api-bundle-recipe/contents/index.json",
        "flex://defaults"
      ]
    }
  }
}
```

Require the bundle using Composer:

```shell
composer require edumedia/gar-api-bundle
```