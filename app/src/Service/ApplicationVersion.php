<?php

declare(strict_types=1);

namespace App\Service;

final class ApplicationVersion
{
    private ?string $cachedVersion = null;

    public function __construct(
        private readonly string $versionFile,
    ) {}

    public function getVersion(): string
    {
        if ($this->cachedVersion !== null) {
            return $this->cachedVersion;
        }

        $version = @file_get_contents($this->versionFile);
        $version = is_string($version) ? trim($version) : '';

        $this->cachedVersion = $version !== '' ? $version : 'dev';

        return $this->cachedVersion;
    }
}
