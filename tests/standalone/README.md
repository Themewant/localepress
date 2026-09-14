# Standalone routing checks

These run without WordPress, MySQL, or the WP test suite:

```
php tests/standalone/host-test.php   # LanguageHostResolver: www handling
php tests/standalone/verify.php      # the routing fixes, end to end per mode
```

Each loads the **real** plugin class under test and supplies fakes only for its
collaborators (`fakes.php`) and for the WordPress functions it calls
(`bootstrap.php`). They exist because the questions they answer — which host a
URL is built on, which caller gets a localized home URL, which languages one
sitemap lists — are decided in pure PHP, and waiting on a database to ask them
is what stops anyone from asking.

They are a fast first gate, not a replacement for `tests/test-routing.php`,
which runs the same code against a real WordPress with real queries, rewrite
rules, and permalinks. Anything involving the database, the template hierarchy,
or an actual HTTP response belongs there.

`/tests` is excluded from the distributed build by `.distignore`.
