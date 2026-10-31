<?php

declare(strict_types=1);

namespace DeploySafe;

final class Formatter
{
    public static function render(Report $report, string $format): string
    {
        if ($format === 'json') {
            return json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        }
        if ($format === 'sarif') {
            $levels = ['critical' => 'error', 'warning' => 'warning', 'informational' => 'note'];
            $results = [];
            foreach ($report->findings as $f) {
                $path = str_replace('\\', '/', $f->file);
                $absolute = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path);
                $uri = implode('/', array_map('rawurlencode', explode('/', $path)));
                $location = ['uri' => $absolute ? 'file://'.(str_starts_with($path, '/') ? '' : '/').$uri : $uri];
                $results[] = ['ruleId' => $f->rule, 'level' => $levels[$f->severity], 'message' => ['text' => $f->message.' Recommendation: '.$f->recommendation], 'locations' => [['physicalLocation' => ['artifactLocation' => $location, 'region' => ['startLine' => $f->line]]]]];
            }
            return json_encode(['$schema' => 'https://json.schemastore.org/sarif-2.1.0.json', 'version' => '2.1.0', 'runs' => [['tool' => ['driver' => ['name' => 'deploy-safe-migrations', 'version' => '0.1.0']], 'invocations' => [['executionSuccessful' => $report->complete]], 'properties' => ['analysisComplete' => $report->complete], 'results' => $results]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        }
        if ($format !== 'console') {
            throw new \InvalidArgumentException('Unknown output format');
        }
        $lines = [$report->complete ? 'Analysis complete for supported syntax. This is not a zero-downtime guarantee.' : 'INCOMPLETE analysis: unsupported code or parse errors require manual review.'];
        foreach ($report->findings as $f) {
            $lines[] = sprintf('%s:%d [%s] %s: %s', $f->file, $f->line, strtoupper($f->severity), $f->rule, $f->message);
            $lines[] = '  Recommendation: '.$f->recommendation;
        }
        $lines[] = count($report->findings).' finding(s).';
        return implode("\n", $lines)."\n";
    }
}
