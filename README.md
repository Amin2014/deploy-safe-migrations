# deploy-safe-migrations

A small, framework-independent Composer linter for explicit Laravel migration operations. It parses PHP with maintained [nikic/php-parser](https://github.com/nikic/PHP-Parser) 5.x, reports rolling-deployment compatibility risks, and never executes the files being analyzed. No database connection, Laravel bootstrap, or service provider is required for linting.

**Passing this tool does not guarantee zero-downtime deployment.** It cannot establish the live schema, old application behavior, database locks, data quality, traffic, replication lag, or whether a contract phase is operationally safe. “Complete” means the input fit this tool's supported grammar, not that every possible deployment risk was checked.

## Quickstart

PHP 8.3+ and Composer 2 are required. From this source checkout:

```sh
composer install
php bin/deploy-safe-migrations examples/unsafe.php
# exits 1; reports renameColumn with source filename, line and recommendation
php bin/deploy-safe-migrations examples/staged/01_expand.php --fail-on=warning
# exits 0; additive nullable column receives an informational finding
composer test
php examples/demo.php
```

Composer identity: `deploy-safe/deploy-safe-migrations`. This source is installable; it has not been registered on Packagist. To install locally into an application, add a path repository (adjust the path):

```sh
composer config repositories.deploy-safe path ../deploy-safe-migrations
composer require --dev deploy-safe/deploy-safe-migrations:@dev
vendor/bin/deploy-safe-migrations database/migrations
```

A VCS repository can replace the path repository after publication. Applications install only the parser at runtime; the Laravel components and SQLite extension are development/demo dependencies of this checkout.

## Why rolling deployments break

During a rollout, old and new application instances, queue workers and scheduled jobs may share the same database. Replacing `status` with `state` immediately leaves old code issuing queries against a column that no longer exists. Adding a required column can reject old inserts that do not supply it. Changing a type or constraint can invalidate values that old code still writes.

The analyzer recommends expand / migrate / contract:

1. **Expand:** add a new nullable column before new code needs it. Keep old columns available. Deploy code that can read with a fallback and write both representations.
2. **Migrate:** backfill in bounded batches, account for concurrent writes, reconcile, and validate. Switch reads to the new representation while keeping both compatible writers during overlap.
3. **Contract:** drain old instances, jobs and workers; close the rollback window; deploy new-only writers; then remove the old column in a separate, explicitly reviewed migration.

A contract migration is intentionally still critical. The linter has no knowledge of release sequencing or fleet state. There is no “safe contract” annotation that silently suppresses it.

## Rules

| Rule | Severity | Reason / recommended handling |
| --- | --- | --- |
| `destructive-schema` | critical | `Schema::drop`, `dropIfExists`, or `rename` removes a name old code may need. Introduce a replacement table, copy data, switch traffic, then contract. |
| `destructive-column` | critical | `renameColumn`, `dropColumn`, and supported drop helpers remove an old column name. Add a replacement, dual-write, backfill, then contract. |
| `column-change` | critical | Any supported column chain ending in/containing `change()` may change type, nullability, defaults or other attributes. Without the prior schema, even widening is conservatively flagged. Use a replacement column or manually justify the change. |
| `add-column` | critical | Existing-table column added without `nullable()` or a non-null literal default. Old omitted-column inserts and existing rows may violate NOT NULL. Expand as nullable first. |
| `add-column` | warning | A non-null literal default may preserve omitted-column inserts, but explicit NULL writes, default semantics and engine DDL behavior need review. |
| `add-column` | informational | Nullable addition is generally compatible with explicit-column readers/writers. Positional SQL, `SELECT *` mappings and DDL availability remain unchecked. |
| `identity-column` | critical | Adding an identity/primary column to an existing table can conflict with existing keys or writes. Review separately. |
| `index-constraint` | warning | Index and constraint creation/deletion may lock tables, reject old writes or remove integrity/performance guarantees. Use a verified database-specific plan. |
| `create-table` | informational | New-table creation is additive for existing application code; table collisions, permissions and availability are unchecked. |
| `unsupported` | warning + incomplete | Dynamic, unknown or out-of-scope code requires manual review; no compatibility verdict for that code. |
| `parse-error` | warning + incomplete | Fix PHP syntax before analyzing. |

Defaults are not inferred from the database. `nullable(false)` is required; omitted or `true` nullable arguments are nullable. Repeated `nullable`/`default` calls use the final literal value. A `default(null)` does not make a required column compatible. Helpers with implicit database-generated defaults are treated conservatively.

## Supported grammar and explicit boundaries

Only concrete `up()` methods with no arguments on named or anonymous classes **directly extending** `Illuminate\Database\Migrations\Migration` are analyzed. `down()` is excluded: rollback safety needs its own review. Imports, namespace-qualified names, aliases and fully qualified names are resolved by the parser, not string matching. Empty `up()` is a supported no-op.

Within `up()`, only direct, positional calls to the actual `Illuminate\Support\Facades\Schema` are supported:

```php
Schema::table('payments', function (Blueprint $table) {
    $table->string('state')->nullable();
});
```

`Schema::create` uses the same closure shape. The closure must have exactly one explicitly typed `Illuminate\Database\Schema\Blueprint` parameter and no captured variables. Every body statement must be a direct method chain rooted in that parameter. Arguments must be literal strings/numbers/booleans/null or literal arrays; named/unpacked arguments and computed expressions are unsupported.

Supported base column methods:

- `string`, `char`, `text`, `tinyText`, `mediumText`, `longText`
- `integer`, `tinyInteger`, `smallInteger`, `mediumInteger`, `bigInteger`, `unsignedInteger`, `unsignedBigInteger`
- `boolean`, `decimal`, `float`, `double`, `date`, `dateTime`, `dateTimeTz`, `time`, `timeTz`, `timestamp`, `timestampTz`
- `json`, `jsonb`, `uuid`, `ulid`, `binary`, `enum`, `foreignId`, `foreignUuid`, `foreignUlid`

Supported helpers: `id`, `increments`, `bigIncrements`, `timestamps`, `timestampsTz`, `nullableTimestamps`, `softDeletes`, `softDeletesTz`, `rememberToken`.

Supported destructive methods: `renameColumn`, `dropColumn` (including literal arrays), `dropTimestamps`, `dropTimestampsTz`, `dropSoftDeletes`, `dropSoftDeletesTz`, `dropRememberToken`, `dropMorphs`, `dropConstrainedForeignId`.

Supported index/constraint bases: `index`, `unique`, `primary`, `dropIndex`, `dropUnique`, `dropPrimary`, `foreign`, `dropForeign`. Supported chain modifiers: `nullable`, `default`, `change`, `unsigned`, `comment`, `after`, `first`, `index`, `unique`, `primary`, `charset`, `collation`, `useCurrent`, `useCurrentOnUpdate`. This is a compatibility linter, not Laravel's API argument validator.

Everything else is explicitly incomplete, including:

- Raw SQL, `DB::statement`, data updates and Eloquent queries.
- Variables in names/defaults, loops, conditionals, helpers, closure captures, arrow functions, assigned column definitions, dynamic dispatch, macros and custom types.
- Connection-specific schema builder chains (`Schema::connection(...)`), traits, constructors, helper methods, custom migration base classes and top-level execution.
- `constrained()` / fluent foreign-key action chains, morph helpers and unlisted Blueprint methods. These can be added as separate narrowly tested rules later.

Known operations in a supported callback are still reported if another statement is unsupported. Unsupported outer control flow is not evaluated or traversed for a guessed verdict. Completion is aggregated across all input files. This tool does not build a historical schema, infer renames, inspect application queries, execute migration code, connect to production, or check rollback compatibility.

## CLI and CI policy

```sh
vendor/bin/deploy-safe-migrations database/migrations --format=json --fail-on=warning
vendor/bin/deploy-safe-migrations database/migrations --format=sarif > migrations.sarif
vendor/bin/deploy-safe-migrations file1.php file2.php --fail-on=critical
```

Directories are scanned recursively for PHP files, sorted deterministically, and duplicate paths are removed. Prefer linting only new migration files for a deployment: old destructive migrations remain critical if you scan all history. Supply explicit filenames from a trusted CI step; the tool intentionally does not implement Git diff selection. An empty directory or missing input fails.

`--format=console|json|sarif` selects stdout format. JSON includes `complete` and a `findings` list with `rule`, `severity`, `file`, `line`, `message`, and `recommendation`. SARIF 2.1.0 maps critical to `error`, warning to `warning`, informational to `note`; completion is recorded in the run and invocation. Prefer repository-relative paths for code scanning. I/O and usage errors go to stderr.

`--fail-on=critical|warning|informational|none` fails at that severity or higher (default: critical). **Incomplete analysis fails independently of this threshold.** `--allow-unsupported` explicitly disables that independent failure; the warning still participates in severity policy and output still says incomplete. Use only after a documented manual review; this never makes unsupported code analyzed.

| Exit | Meaning |
| --- | --- |
| 0 | Chosen policy passed (inspect completion when using `--allow-unsupported`) |
| 1 | Finding meets configured severity threshold |
| 2 | Invalid options, missing/empty input, unreadable file or I/O failure |
| 3 | Analysis incomplete (takes precedence over severity failure) |

Example GitHub Actions job, after the package has been installed in your application's Composer dependencies:

```yaml
name: Migration compatibility
on: [pull_request]
permissions:
  contents: read
  security-events: write
jobs:
  lint:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          tools: composer:v2
      - run: composer install --prefer-dist --no-interaction
      - name: Analyze migrations
        run: vendor/bin/deploy-safe-migrations database/migrations --format=sarif --fail-on=warning > migrations.sarif
      - uses: github/codeql-action/upload-sarif@v3
        if: always()
        with:
          sarif_file: migrations.sarif
```

No `continue-on-error` is used: a failed lint step fails CI while findings are still uploaded. Code-scanning permissions/availability and uploads from fork PRs depend on GitHub repository policy. On I/O failure the SARIF artifact may be absent; the failing lint step remains authoritative.

## Runnable demonstration

```sh
php examples/demo.php
```

Expected output:

```text
BEFORE: immediate rename breaks old writer (status column missing).
AFTER expand: old and new writers both succeed; backfill validated.
AFTER contract: new-only writer succeeds after old instances are drained.
```

This runs actual Laravel migrations on separate ephemeral in-memory SQLite databases. `examples/unsafe.php` renames immediately. `examples/staged/01_expand.php` adds a nullable column; the demo performs dual writes and a tiny backfill; `03_contract.php` removes the old column only after an explicitly modeled operational gate. The demo is intentionally sequential and does not simulate a fleet, backfill races or production DDL locking. Do not run all staged migrations together during an overlapping rollout.

## Compatibility and database assumptions

Verified on **2026-10-27**: [Laravel 13](https://laravel.com/framework/docs/releases) is the current stable major (PHP 8.3–8.5). The test suite executes these examples with **illuminate/database v13.38.0** and **nikic/php-parser v5.9.0**, pinned in `composer.lock`. [Laravel's migration documentation](https://github.com/laravel/docs/blob/13.x/migrations.md) defines the Blueprint operations targeted here. Runtime linting has no Laravel dependency. Similar syntax from earlier majors may parse, but earlier Laravel versions are not a verified compatibility claim. CI tests PHP 8.3, 8.4 and 8.5; local validation used PHP 8.3.6.

Rules describe application/schema overlap and deliberately do **not** model database engine/version DDL behavior. There is no database option that pretends to certify online DDL.

| Database | Assumptions requiring external verification |
| --- | --- |
| PostgreSQL | ALTER/constraint operations may require strong locks or scans; lock duration, validated constraints and concurrent index restrictions require a database-specific plan. |
| MySQL / MariaDB | Instant/in-place/copy behavior and metadata locks depend on engine, exact version and operation. Defaults do not imply lock-free DDL. |
| SQLite | ALTER support, table rebuilds and writer locking differ from server engines. The demo proves application compatibility behavior only. |
| SQL Server / other | No engine-specific rule validation; syntax support and availability must be reviewed independently. |

The tool cannot distinguish a harmless widening from a narrowing without a schema, certify a NOT NULL change after backfill, determine whether old code still writes NULL, or know when all consumers have stopped referencing a removed name. It intentionally errs toward findings and manual review.

## Architecture and development

Source → PHP-Parser AST + name resolution → restricted migration grammar → rule findings + completion status → console/JSON/SARIF → CI policy. `Analyzer` has no filesystem/database side effects. `Cli` handles file discovery and policy; `Formatter` handles presentation.

```php
$report = (new DeploySafe\Analyzer())->analyze($source, 'database/migrations/example.php');
if (!$report->complete || $report->fails('warning')) {
    // Require review.
}
echo DeploySafe\Formatter::render($report, 'json');
```

```sh
composer install
composer validate --strict
composer lint
composer test
php examples/demo.php
```

Tests cover unsafe/safe fixtures, exact locations, nullability/default behavior, type changes, alias resolution, unsupported syntax, parse errors, no execution of input, ignored rollback methods, output serialization, CLI exits, empty input, and actual Laravel/SQLite migration behavior. Add fixtures and explicit incomplete cases when extending the grammar. No suppression mechanism or auto-fixer is provided.

MIT licensed. Small scope by design: a migration linter, not a deployment platform.
