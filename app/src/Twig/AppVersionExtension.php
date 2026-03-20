<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\ApplicationVersion;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class AppVersionExtension extends AbstractExtension
{
    public function __construct(
        private readonly ApplicationVersion $applicationVersion,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('app_version', fn (): string => $this->applicationVersion->getVersion()),
        ];
    }
}
