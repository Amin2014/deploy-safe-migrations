<?php

declare(strict_types=1);

namespace DeploySafe\Tests;

use DeploySafe\{Analyzer, Formatter, Report};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AnalyzerTest extends TestCase
{
    private function migration(string $body): string
    {
        return '<?php use Illuminate\\Database\\Migrations\\Migration; use Illuminate\\Support\\Facades\\Schema as S; use Illuminate\\Database\\Schema\\Blueprint as B; return new class extends Migration {public function up(): void {'.$body.'} public function down(): void { S::drop("payments"); }};';
    }

    public function testUnsafeFixtureHasPreciseLocations(): void
    {
        $r = (new Analyzer())->analyze(file_get_contents(__DIR__.'/fixtures/unsafe.php'), 'unsafe.php');
        self::assertTrue($r->complete);
        self::assertSame(['destructive-column', 'destructive-column', 'column-change', 'add-column'], array_column($r->findings, 'rule'));
        self::assertSame([13, 14, 15, 16], array_column($r->findings, 'line'));
        self::assertSame(['critical'], array_values(array_unique(array_column($r->findings, 'severity'))));
        self::assertSame('unsafe.php', $r->findings[0]->file);
        foreach ($r->findings as $f) {
            self::assertNotEmpty($f->recommendation);
        }
    }

    public function testSafeAndCreateFixtures(): void
    {
        foreach (['safe', 'create'] as $fixture) {
            $r = (new Analyzer())->analyze(file_get_contents(__DIR__."/fixtures/$fixture.php"));
            self::assertTrue($r->complete);
            self::assertFalse($r->fails('warning'));
            self::assertTrue($r->fails('informational'));
        }
    }

    public static function operations(): iterable
    {
        yield 'nullable' => ['$t->string("x")->nullable();', 'informational'];
        yield 'false nullable' => ['$t->string("x")->nullable(false);', 'critical'];
        yield 'last nullable wins' => ['$t->string("x")->nullable()->nullable(false);', 'critical'];
        yield 'null default' => ['$t->string("x")->default(null);', 'critical'];
        yield 'default zero' => ['$t->integer("x")->default(0);', 'warning'];
        yield 'nullable change' => ['$t->string("x")->nullable()->change();', 'critical'];
        yield 'drop array' => ['$t->dropColumn(["x", "y"]);', 'critical'];
        yield 'drop special' => ['$t->dropTimestamps();', 'critical'];
        yield 'identity' => ['$t->id();', 'critical'];
        yield 'unique' => ['$t->unique("x");', 'warning'];
    }

    #[DataProvider('operations')]
    public function testOperationClassification(string $operation, string $severity): void
    {
        $r = (new Analyzer())->analyze($this->migration('S::table("payments", function (B $t) {'.$operation.'});'));
        self::assertTrue($r->complete);
        self::assertSame($severity, $r->findings[0]->severity);
    }

    public static function unsupported(): iterable
    {
        yield ['if (true) { S::drop("payments"); }'];
        yield ['DB::statement("DROP TABLE payments");'];
        yield ['helper();'];
        yield ['S::table($name, function(B $t) {$t->dropColumn("x");});'];
        yield ['S::table("p", fn(B $t) => $t->dropColumn("x"));'];
        yield ['S::table("p", function(B $t) use ($x) {$t->dropColumn($x);});'];
        yield ['S::table("p", function(B $t) {$t->$method("x");});'];
        yield ['S::table("p", function(B $t) {$t->string($name);});'];
        yield ['S::table("p", function(B $t) {$t->string("x")->custom();});'];
        yield ['S::table("p", function(B $t) {$t->string(name: "x");});'];
        yield ['S::table("p", function(B $t) {$t->string(...$args);});'];
        yield ['S::table("p", function(B $t) {$column = $t->string("x"); $column->change();});'];
        yield ['S::table("p", function(B $t) {$t->string("x")->default(now());});'];
        yield ['S::connection("tenant")->drop("p");'];
    }

    #[DataProvider('unsupported')]
    public function testUnsupportedIsExplicit(string $body): void
    {
        $r = (new Analyzer())->analyze($this->migration($body));
        self::assertFalse($r->complete);
        self::assertContains('unsupported', array_column($r->findings, 'rule'));
    }

    public function testNoExecutionAndDownIgnored(): void
    {
        $r = (new Analyzer())->analyze($this->migration(''));
        self::assertTrue($r->complete);
        self::assertSame([], $r->findings);
        $r = (new Analyzer())->analyze('<?php throw new Exception("must never execute");');
        self::assertFalse($r->complete);
    }

    public function testSchemaAliasAndFullyQualifiedNames(): void
    {
        foreach (['S::drop("p");', '\\Illuminate\\Support\\Facades\\Schema::rename("p", "q");'] as $body) {
            $r = (new Analyzer())->analyze($this->migration($body));
            self::assertTrue($r->complete);
            self::assertSame('destructive-schema', $r->findings[0]->rule);
        }
    }

    public function testParseErrorAndMissingMigration(): void
    {
        foreach (['<?php broken {', '<?php class Example {}'] as $source) {
            self::assertFalse((new Analyzer())->analyze($source)->complete);
        }
    }

    public function testTraitsConstructorsAndCustomBasesAreIncomplete(): void
    {
        $sources = [
            '<?php use Illuminate\\Database\\Migrations\\Migration; return new class extends Migration { use CustomBehavior; public function up(): void {} };',
            '<?php use Illuminate\\Database\\Migrations\\Migration; return new class extends Migration { public function __construct() {} public function up(): void {} };',
            '<?php return new class extends CustomMigration { public function up(): void {} };',
        ];
        foreach ($sources as $source) {
            self::assertFalse((new Analyzer())->analyze($source)->complete);
        }
    }

    public function testThresholdsAndFormats(): void
    {
        $r = (new Analyzer())->analyze($this->migration('S::drop("p");'), 'migrations/a b.php');
        self::assertTrue($r->fails('critical'));
        self::assertFalse($r->fails('none'));
        self::assertTrue(json_decode(Formatter::render($r, 'json'), true, flags: JSON_THROW_ON_ERROR)['complete']);
        $sarif = json_decode(Formatter::render($r, 'sarif'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('2.1.0', $sarif['version']);
        self::assertSame('error', $sarif['runs'][0]['results'][0]['level']);
        self::assertSame('migrations/a%20b.php', $sarif['runs'][0]['results'][0]['locations'][0]['physicalLocation']['artifactLocation']['uri']);
        self::assertStringContainsString('migrations/a b.php:1', Formatter::render($r, 'console'));
    }
}
