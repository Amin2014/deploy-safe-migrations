<?php

declare(strict_types=1);

namespace DeploySafe\Tests;

use PHPUnit\Framework\TestCase;

final class CliTest extends TestCase
{
    private function cli(string ...$args): array
    {
        $process = proc_open([PHP_BINARY, dirname(__DIR__).'/bin/deploy-safe-migrations', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $out, $err];
    }

    public function testExitCodes(): void
    {
        self::assertSame(0, $this->cli(__DIR__.'/fixtures/safe.php')[0]);
        self::assertSame(1, $this->cli(__DIR__.'/fixtures/unsafe.php')[0]);
        self::assertSame(3, $this->cli('--fail-on=none', __DIR__.'/fixtures/dynamic.php')[0]);
        self::assertSame(0, $this->cli('--fail-on=none', '--allow-unsupported', __DIR__.'/fixtures/dynamic.php')[0]);
        self::assertSame(1, $this->cli('--fail-on=warning', '--allow-unsupported', __DIR__.'/fixtures/dynamic.php')[0]);
        self::assertSame(2, $this->cli('nonexistent-path')[0]);
        self::assertSame(2, $this->cli('--fail-on=nope', __DIR__.'/fixtures/safe.php')[0]);
        self::assertSame(2, $this->cli('--wat')[0]);
        self::assertSame(2, $this->cli()[0]);
        self::assertSame(0, $this->cli('--help')[0]);
    }

    public function testDirectoryAndJson(): void
    {
        [$status, $out, $err] = $this->cli('--format=json', __DIR__.'/fixtures');
        self::assertSame(3, $status);
        self::assertSame('', $err);
        self::assertFalse(json_decode($out, true, flags: JSON_THROW_ON_ERROR)['complete']);
    }

    public function testEmptyDirectoryCannotPass(): void
    {
        $dir = sys_get_temp_dir().'/deploy-safe-'.bin2hex(random_bytes(8));
        mkdir($dir);
        try {
            self::assertSame(2, $this->cli($dir)[0]);
        } finally {
            rmdir($dir);
        }
    }
}
