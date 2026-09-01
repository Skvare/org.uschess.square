# Development guide

Run the following from the extension root:

```bash
vendor/bin/phpcs --standard=phpcs.xml.dist CRM square.php settings managed tests
vendor/bin/phpunit --configuration tests/phpunit/phpunit.xml.dist
```

The extension uses CiviCRM's two-space, Drupal-derived style. `.editorconfig`
defines editor whitespace rules, and `phpcs.xml.dist` is the authoritative lint
configuration. It intentionally permits CiviCRM's `CRM_*` class names,
snake-case hook functions, the `ExtensionUtil as E` convention, and
underscore-prefixed core-compatible payment properties.

Do not lint or automatically format `vendor/`. Keep credentials, signature
keys, card tokens, and raw webhook bodies out of source, fixtures, and logs.
Update the relevant guide in this directory when changing setup, persistence,
webhook behavior, or development workflow.
