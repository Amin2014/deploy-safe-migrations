# Validation record

Executed on 2026-10-27 using PHP 8.3.6, Composer 2.10.3,
nikic/php-parser v5.9.0, PHPUnit 11.5.57, and
illuminate/database v13.38.0.

- `composer validate --strict`: passed.
- `composer lint`: all package, fixture and example PHP syntax checks passed.
- `composer test`: **35 tests, 99 assertions**, passed.
- `php examples/demo.php`: immediate rename broke the old SQLite writer;
  expand allowed both writers; contract allowed the new-only writer.
- A separate Composer consumer installed the package through a path repository
  with only the package and PHP-Parser as dependencies. Its generated
  `vendor/bin/deploy-safe-migrations` command ran successfully.
- CLI unsafe fixture returned 1; dynamic fixture returned 3 even with
  `--fail-on=none`; nullable expansion passed `--fail-on=warning`.
- Both unsafe and incomplete-analysis SARIF output validated against the
  SARIF 2.1.0 JSON schema from json.schemastore.org.

The included GitHub Actions workflow has not been executed remotely. PHP 8.4
and 8.5 checks are configured there; they were not run locally. Database
integration validation used SQLite only. No production locks, workload,
backfill concurrency, or fleet rollout were tested. The package is not
published on Packagist and no remote GitHub repository was created.
