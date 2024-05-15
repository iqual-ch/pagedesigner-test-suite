# Pagedesigner Test Suite

Package containing tests for the Pagedesigner Suite of modules. These test are intended for testing the core Pagedesigner product on multiple website projects.

These tests integrated seemlessly into [iqual's Drupal Platform](https://github.com/iqual-ch/drupal-platform).

## Tests

### Existing Site

Testing on the existing Drupal installation with the live database.

#### Node Creation Tests

* Tests if nodes can be created.

### Existing Site Javascript

Testing on the existing Drupal installation with the live database and a full browser.

#### Pagedesigner Loading Test

* Tests if the pagedesigner can be loaded on the homepage.

### Kernel

Testing an empty Drupal installation with a clean database.

#### Drupal Core Function Test

* Tests the `ExtensionPathResolver` for the `node` module