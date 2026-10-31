<?php

declare(strict_types=1);

namespace DeploySafe\Tests;

use PHPUnit\Framework\TestCase;

final class LaravelCompatibilityTest extends TestCase
{
    public function testActualLaravelMigrationDemo(): void
    {
        ob_start();
        try {
            require dirname(__DIR__).'/examples/demo.php';
            $out = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        self::assertStringContainsString('immediate rename breaks old writer', $out);
        self::assertStringContainsString('old and new writers both succeed', $out);
        self::assertStringContainsString('new-only writer succeeds', $out);
    }
}
