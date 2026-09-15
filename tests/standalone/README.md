# Standalone routing checks

These run without WordPress, MySQL, or the WP test suite:

```
php tests/standalone/host-test.php               # LanguageHostResolver: www handling
php tests/standalone/verify.php                  # the routing fixes, end to end per mode
php tests/standalone/background-language-test.php # which language an AJAX or REST call carries
```

Each loads the **real** plugin class under test and supplies fakes only for its
collaborators (`fakes.php`) and for the WordPress functions it calls
(`bootstrap.php`). They exist because the questions they answer — which host a
URL is built on, which caller gets a localized home URL, which languages one
sitemap lists — are decided in pure PHP, and waiting on a database to ask them
is what stops anyone from asking.

`background-language-test.php` runs the real `BackgroundLanguageResolver`
against the fake URL manager. Its subject is a set of request-shape rules —
which argument is trusted where, when a referrer settles the question, what a
dispatcher's override displaces — and none of them needs a database to answer.
Whether the language it resolves then reaches a query belongs in the WordPress
suite.

They are a fast first gate, not a replacement for `tests/test-routing.php`,
which runs the same code against a real WordPress with real queries, rewrite
rules, and permalinks. Anything involving the database, the template hierarchy,
or an actual HTTP response belongs there.

`/tests` is excluded from the distributed build by `.distignore`.
