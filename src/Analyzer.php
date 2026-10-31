<?php

declare(strict_types=1);

namespace DeploySafe;

use PhpParser\{Node, NodeTraverser, ParserFactory};
use PhpParser\Node\{Expr, Stmt, Scalar, Name, Identifier};

/** Deliberately interprets a small grammar; never includes or executes migration PHP. */
final class Analyzer
{
    private const SCHEMA = 'Illuminate\\Support\\Facades\\Schema';
    private const MIGRATION = 'Illuminate\\Database\\Migrations\\Migration';
    private const BLUEPRINT = 'Illuminate\\Database\\Schema\\Blueprint';
    private const COLUMNS = ['string', 'char', 'text', 'tinyText', 'mediumText', 'longText', 'integer', 'tinyInteger', 'smallInteger', 'mediumInteger', 'bigInteger', 'unsignedInteger', 'unsignedBigInteger', 'boolean', 'decimal', 'float', 'double', 'date', 'dateTime', 'dateTimeTz', 'time', 'timeTz', 'timestamp', 'timestampTz', 'json', 'jsonb', 'uuid', 'ulid', 'binary', 'enum', 'foreignId', 'foreignUuid', 'foreignUlid'];
    private const MODIFIERS = ['nullable', 'default', 'change', 'unsigned', 'comment', 'after', 'first', 'index', 'unique', 'primary', 'charset', 'collation', 'useCurrent', 'useCurrentOnUpdate'];
    private const DROP = ['dropColumn', 'dropTimestamps', 'dropTimestampsTz', 'dropSoftDeletes', 'dropSoftDeletesTz', 'dropRememberToken', 'dropMorphs', 'dropConstrainedForeignId'];
    private const SPECIAL = ['id', 'increments', 'bigIncrements', 'timestamps', 'timestampsTz', 'nullableTimestamps', 'softDeletes', 'softDeletesTz', 'rememberToken'];

    public function analyze(string $source, string $file = '<memory>'): Report
    {
        $report = new Report();
        try {
            $nodes = (new ParserFactory())->createForNewestSupportedVersion()->parse($source) ?? [];
            $nodes = (new NodeTraverser(new \PhpParser\NodeVisitor\NameResolver()))->traverse($nodes);
        } catch (\PhpParser\Error $e) {
            $report->add('parse-error', 'warning', $file, $e->getStartLine(), $e->getRawMessage(), 'Fix PHP syntax before analysis.');
            return $report;
        }
        $classes = [];
        $this->scaffold($nodes, $classes, $report, $file);
        if ($classes === []) {
            $this->unsupported($report, $file, 1, 'No supported Migration subclass found.');
        }
        foreach ($classes as $class) {
            $up = $class->getMethod('up');
            if (!$up || $up->stmts === null || $up->params !== []) {
                $this->unsupported($report, $file, $class->getStartLine(), 'Expected concrete up() with no arguments.');
                continue;
            }
            foreach ($class->stmts as $member) {
                if ($member instanceof Stmt\TraitUse || ($member instanceof Stmt\ClassMethod && !in_array(strtolower($member->name->toString()), ['up', 'down'], true))) {
                    $this->unsupported($report, $file, $member->getStartLine(), 'Traits, constructors and helper methods are outside the supported migration grammar.');
                }
            }
            foreach ($up->stmts as $statement) {
                $this->schemaStatement($statement, $report, $file);
            }
        }
        return $report;
    }

    private function scaffold(array $nodes, array &$classes, Report $report, string $file): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof Stmt\Namespace_) {
                $this->scaffold($node->stmts, $classes, $report, $file);
            } elseif ($node instanceof Stmt\Use_ || $node instanceof Stmt\GroupUse || $node instanceof Stmt\Nop) {
                continue;
            } elseif ($node instanceof Stmt\Declare_ && $node->stmts === null) {
                continue;
            } else {
                $class = $node instanceof Stmt\Class_ ? $node : (($node instanceof Stmt\Return_ && $node->expr instanceof Expr\New_ && $node->expr->args === []) ? $node->expr->class : null);
                if ($class instanceof Stmt\Class_ && $class->extends?->toString() === self::MIGRATION) {
                    $classes[] = $class;
                } else {
                    $this->unsupported($report, $file, $node->getStartLine(), 'Unsupported top-level code or migration inheritance.');
                }
            }
        }
    }

    private function schemaStatement(Node $statement, Report $report, string $file): void
    {
        $call = $statement instanceof Stmt\Expression ? $statement->expr : null;
        if (!$call instanceof Expr\StaticCall || !$call->class instanceof Name || $call->class->toString() !== self::SCHEMA || !$call->name instanceof Identifier) {
            $this->unsupported($report, $file, $statement->getStartLine(), 'Only direct Schema calls in up() are supported (no SQL, helpers, branches or loops).');
            return;
        }
        $method = $call->name->toString();
        $args = $call->args;
        if (!isset($args[0]) || !$args[0] instanceof Node\Arg || $args[0]->unpack || $args[0]->name !== null || !$args[0]->value instanceof Scalar\String_) {
            $this->unsupported($report, $file, $call->getStartLine(), 'Schema table name must be a literal positional string.');
            return;
        }
        if (in_array($method, ['drop', 'dropIfExists', 'rename'], true) && $this->literalArgs($args) && count($args) === ($method === 'rename' ? 2 : 1)) {
            $report->add('destructive-schema', 'critical', $file, $call->getStartLine(), "Schema::$method removes an object name still used by old instances.", 'Expand with a new table; copy and dual-write data; switch readers; contract only after old workers and rollback windows end.');
            return;
        }
        $closure = $args[1]->value ?? null;
        if (!in_array($method, ['table', 'create'], true) || count($args) !== 2 || !$args[1] instanceof Node\Arg || $args[1]->name !== null || $args[1]->unpack || !$closure instanceof Expr\Closure || count($closure->params) !== 1 || $closure->uses !== [] || $closure->params[0]->byRef || $closure->params[0]->variadic || !$closure->params[0]->type instanceof Name || $closure->params[0]->type->toString() !== self::BLUEPRINT || !is_string($closure->params[0]->var->name)) {
            $this->unsupported($report, $file, $call->getStartLine(), 'Expected Schema::table/create with a typed Blueprint closure and no captures.');
            return;
        }
        if ($method === 'create') {
            $report->add('create-table', 'informational', $file, $call->getStartLine(), 'Creating a new table is additive for existing code; availability and name collisions are not checked.', 'Deploy schema before readers; review engine-specific DDL locks.');
        }
        foreach ($closure->stmts as $item) {
            $this->blueprintStatement($item, $closure->params[0]->var->name, $method === 'create', $report, $file);
        }
    }

    private function blueprintStatement(Node $statement, string $variable, bool $create, Report $report, string $file): void
    {
        $expr = $statement instanceof Stmt\Expression ? $statement->expr : null;
        $chain = [];
        while ($expr instanceof Expr\MethodCall && $expr->name instanceof Identifier) {
            array_unshift($chain, $expr);
            $expr = $expr->var;
        }
        if (!$expr instanceof Expr\Variable || $expr->name !== $variable || $chain === []) {
            $this->unsupported($report, $file, $statement->getStartLine(), 'Expected a direct Blueprint method chain; variables and control flow are unsupported.');
            return;
        }
        foreach ($chain as $call) {
            if (!$this->literalArgs($call->args)) {
                $this->unsupported($report, $file, $call->getStartLine(), 'Blueprint arguments must be positional literals (no expressions, SQL or variables).');
                return;
            }
        }
        $base = $chain[0]->name->toString();
        $line = $chain[0]->getStartLine();
        $names = array_map(fn ($c) => $c->name->toString(), $chain);
        $allowed = array_merge(self::COLUMNS, self::SPECIAL, self::DROP, ['renameColumn', 'index', 'unique', 'primary', 'dropIndex', 'dropUnique', 'dropPrimary', 'foreign', 'dropForeign']);
        if (!in_array($base, $allowed, true) || array_diff(array_slice($names, 1), self::MODIFIERS) !== []) {
            $this->unsupported($report, $file, $line, 'Unknown Blueprint method or modifier: '.implode(' -> ', $names));
            return;
        }
        if (in_array($base, array_merge(self::COLUMNS, ['renameColumn', 'dropColumn']), true)) {
            $required = $base === 'renameColumn' ? 2 : 1;
            for ($i = 0; $i < $required; $i++) {
                $value = $chain[0]->args[$i]->value ?? null;
                if (!$value instanceof Scalar\String_ && !($base === 'dropColumn' && $value instanceof Expr\Array_)) {
                    $this->unsupported($report, $file, $line, 'Expected literal column name(s).');
                    return;
                }
            }
        }
        if (in_array($base, self::DROP, true) || $base === 'renameColumn') {
            $report->add('destructive-column', 'critical', $file, $line, "$base removes a column name that old readers or writers may still reference.", 'Add a replacement nullable column; dual-write and backfill; switch readers; drop the old column in a later release after draining old instances.');
        } elseif (in_array('change', $names, true)) {
            $report->add('column-change', 'critical', $file, $line, 'Changing an existing column may narrow its type, remove a default or impose NOT NULL; old writers may become incompatible. Prior schema is unknown.', 'Use a new nullable column, backfill and validate, switch readers/writers, then contract. Review every change manually, including widening.');
        } elseif (in_array($base, self::COLUMNS, true)) {
            $nullable = false;
            $default = false;
            foreach ($chain as $call) {
                $name = $call->name->toString();
                if ($name === 'nullable') {
                    $value = $call->args[0]->value ?? null;
                    $nullable = $value === null || ($value instanceof Expr\ConstFetch && strtolower($value->name->toString()) === 'true');
                }
                if ($name === 'default') {
                    $value = $call->args[0]->value ?? null;
                    $default = $value !== null && !($value instanceof Expr\ConstFetch && strtolower($value->name->toString()) === 'null');
                }
            }
            if (!$create) {
                $severity = $nullable ? 'informational' : ($default ? 'warning' : 'critical');
                $report->add('add-column', $severity, $file, $line, $nullable ? 'Nullable column addition is additive for explicit-column readers/writers; DDL locks are not assessed.' : ($default ? 'A default may preserve omitted-column inserts, but engine-specific rewrites and old explicit NULL writes remain concerns.' : 'Required column without a default can reject inserts from old instances and fail on existing rows.'), 'Expand with a nullable column; deploy compatible writers; backfill separately; validate before a later constraint change.');
            }
        } elseif (!$create && in_array($base, ['id', 'increments', 'bigIncrements'], true)) {
            $report->add('identity-column', 'critical', $file, $line, 'Adding an identity/primary column to an existing table can conflict with old writes and existing keys.', 'Review existing keys and introduce schema in separately validated stages.');
        }
        if (array_intersect($names, ['unique', 'primary', 'foreign', 'dropIndex', 'dropUnique', 'dropPrimary', 'dropForeign', 'index']) !== []) {
            $report->add('index-constraint', 'warning', $file, $line, 'Index or constraint DDL can lock a table, reject old writes or remove an integrity/performance guarantee.', 'Validate data and old writers; use an engine-supported online index/constraint plan and review locks manually.');
        }
    }

    private function literalArgs(array $args): bool
    {
        foreach ($args as $arg) {
            if (!$arg instanceof Node\Arg || $arg->name !== null || $arg->unpack || $arg->byRef || !$this->literal($arg->value)) {
                return false;
            }
        }
        return true;
    }

    private function literal(Node $node): bool
    {
        if ($node instanceof Scalar\String_ || $node instanceof Scalar\Int_ || $node instanceof Scalar\Float_) {
            return true;
        }
        if ($node instanceof Expr\ConstFetch) {
            return in_array(strtolower($node->name->toString()), ['true', 'false', 'null'], true);
        }
        if ($node instanceof Expr\Array_) {
            foreach ($node->items as $item) {
                if ($item === null || $item->unpack || $item->byRef || ($item->key !== null && !$this->literal($item->key)) || !$this->literal($item->value)) {
                    return false;
                }
            }
            return true;
        }
        return false;
    }

    private function unsupported(Report $report, string $file, int $line, string $message): void
    {
        $report->add('unsupported', 'warning', $file, $line, $message, 'Analysis is incomplete. Rewrite as explicit supported operations or review manually; no compatibility verdict is available for this code.');
    }
}
