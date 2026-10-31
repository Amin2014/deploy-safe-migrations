<?php

declare(strict_types=1);

namespace DeploySafe;

final class Report implements \JsonSerializable
{
    /** @var list<Finding> */
    public array $findings = [];
    public bool $complete = true;

    public function add(string $rule, string $severity, string $file, int $line, string $message, string $advice): void
    {
        $this->findings[] = new Finding($rule, $severity, $file, max(1, $line), $message, $advice);
        if ($rule === 'unsupported' || $rule === 'parse-error') {
            $this->complete = false;
        }
    }

    public function merge(self $other): void
    {
        array_push($this->findings, ...$other->findings);
        $this->complete = $this->complete && $other->complete;
    }

    public function fails(string $threshold): bool
    {
        $ranks = ['informational' => 1, 'warning' => 2, 'critical' => 3, 'none' => 4];
        if (!isset($ranks[$threshold])) {
            throw new \InvalidArgumentException('Unknown severity threshold');
        }
        foreach ($this->findings as $finding) {
            if ($ranks[$finding->severity] >= $ranks[$threshold]) {
                return true;
            }
        }
        return false;
    }

    public function jsonSerialize(): array
    {
        return ['complete' => $this->complete, 'findings' => $this->findings];
    }
}
