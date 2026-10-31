<?php

declare(strict_types=1);

namespace DeploySafe;

final readonly class Finding implements \JsonSerializable
{
    public function __construct(
        public string $rule,
        public string $severity,
        public string $file,
        public int $line,
        public string $message,
        public string $recommendation,
    ) {}

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
