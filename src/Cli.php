<?php

declare(strict_types=1);

namespace DeploySafe;

final class Cli
{
    /** @param list<string> $args */
    public function run(array $args): int
    {
        $format = 'console';
        $threshold = 'critical';
        $allowUnsupported = false;
        $paths = [];
        $options = true;
        foreach ($args as $arg) {
            if ($options && $arg === '--') {
                $options = false;
            } elseif ($options && $arg === '--help') {
                echo "Usage: deploy-safe-migrations [--format=console|json|sarif] [--fail-on=critical|warning|informational|none] [--allow-unsupported] [--] <file|directory>...\nExit: 0 pass, 1 severity threshold, 2 usage/I/O, 3 incomplete analysis. Only up() is analyzed.\n";
                return 0;
            } elseif ($options && str_starts_with($arg, '--format=')) {
                $format = substr($arg, 9);
            } elseif ($options && str_starts_with($arg, '--fail-on=')) {
                $threshold = substr($arg, 10);
            } elseif ($options && $arg === '--allow-unsupported') {
                $allowUnsupported = true;
            } elseif ($options && str_starts_with($arg, '-')) {
                fwrite(STDERR, "Unknown option: $arg\n");
                return 2;
            } else {
                $paths[] = $arg;
            }
        }
        if ($paths === [] || !in_array($format, ['console', 'json', 'sarif'], true) || !in_array($threshold, ['critical', 'warning', 'informational', 'none'], true)) {
            fwrite(STDERR, "Invalid arguments. Use --help.\n");
            return 2;
        }
        try {
            $files = [];
            foreach ($paths as $path) {
                if (is_dir($path)) {
                    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
                    foreach ($iterator as $entry) {
                        if ($entry->isFile() && strtolower($entry->getExtension()) === 'php') {
                            $files[realpath($entry->getPathname())] = $entry->getPathname();
                        }
                    }
                } elseif (is_file($path)) {
                    $files[realpath($path)] = $path;
                } else {
                    throw new \RuntimeException("Path does not exist: $path");
                }
            }
            if ($files === []) {
                throw new \RuntimeException('No PHP files found; no analysis performed.');
            }
            asort($files, SORT_STRING);
            $report = new Report();
            $analyzer = new Analyzer();
            foreach ($files as $file) {
                $source = file_get_contents($file);
                if ($source === false) {
                    throw new \RuntimeException("Cannot read: $file");
                }
                $report->merge($analyzer->analyze($source, $file));
            }
            echo Formatter::render($report, $format);
            if (!$report->complete && !$allowUnsupported) {
                return 3;
            }
            return $report->fails($threshold) ? 1 : 0;
        } catch (\Throwable $e) {
            fwrite(STDERR, $e->getMessage()."\n");
            return 2;
        }
    }
}
