# Pagedesigner Test Suite

Package containing tests for the Pagedesigner Suite of modules. These tests are intended for catching regressions introduced by routine Composer updates (Drupal core or contrib module changes) across all client projects built on the Pagedesigner product.

These tests integrate seamlessly into [iqual's Drupal Platform](https://github.com/iqual-ch/drupal-platform).

## Tests

### Kernel

Testing an empty Drupal installation with a clean database.

#### Pagedesigner Container Kernel Test

* Bootstraps the Pagedesigner module with core dependencies only (no project-specific modules).
* Creates a node type with a `pagedesigner_item` field, creates a node, and asserts that a container element is auto-created and correctly references the node.
* Exercises `PagedesignerService::addContainer` — the most fundamental Pagedesigner contract.

### Existing Site

Testing on the existing Drupal installation with the live database.

All ExistingSite and ExistingSiteJavascript tests run in the site's default language: the URLs they generate carry its prefix and the non-JS client sends it as `Accept-Language`. On multilingual sites with browser-based language detection this keeps the test nodes (created in the default language) viewable, instead of the negotiated browser language producing 403s or translation errors.

#### Admin Pages Test

* Tests that Pagedesigner admin routes are accessible to administrators:
  `/admin/config/pagedesigner`, `/admin/config/pagedesigner/settings`,
  `/admin/content/pagedesigner_elements`, `/admin/structure/pagedesigner_type/list`.
* Asserts that anonymous users are redirected away from the Pagedesigner editor route.

#### Pagedesigner Access Test

* Verifies that the Pagedesigner editor (`/node/{nid}/pagedesigner`) enforces permissions correctly.
* Asserts that anonymous users and users without `edit pagedesigner element entities` are denied.
* Asserts that users with the permission can access the editor.

#### Pagedesigner Container Test

* Dynamically discovers all content types with `pagedesigner_item` fields (portable across all client projects — no hardcoded type names).
* For each discovered type: creates a node and asserts that a container element exists, references the node, and matches its language.

#### Pagedesigner Render Test

* Creates a test node with a programmatically added `text` element (including actual text content).
* Verifies that the public render produces valid Pagedesigner HTML (`section.pd-content.pd-live`).
* Verifies that the Pagedesigner editor view renders without errors, including the GrapesJS container marker.

#### Pagedesigner REST API Test

* Guards against regressions in the REST resource layer caused by REST module or serialization updates.
* Verifies that the pattern endpoint (`/pagedesigner/pattern?_format=hal_json`) returns a valid, non-empty response for authenticated users.
* Verifies that unauthenticated access to the pattern endpoint is denied.
* Verifies that the element REST endpoint is accessible with the correct credentials.

### Existing Site Javascript

Testing on the existing Drupal installation with the live database and a full browser.

#### Pagedesigner Edit Save Test

* Full browser round-trip test: creates a test node, opens the Pagedesigner editor, adds a `text` element with content, saves, navigates to the canonical URL, and asserts the element appears in the rendered page.
* Captures screenshots at each milestone for debuggability.

#### Pagetree Test

* Tests the companion `pagetree` module's core workflow.
* Creates an unpublished test node, opens the pagetree widget, finds the node entry, opens its context menu, and triggers the publish action.
* Asserts that the node is published after the action completes.