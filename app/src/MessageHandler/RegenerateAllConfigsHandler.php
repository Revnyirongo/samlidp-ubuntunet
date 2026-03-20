<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\RegenerateAllConfigsMessage;
use App\Service\MetadataService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class RegenerateAllConfigsHandler
{
    public function __construct(
        private readonly MetadataService $metadataService,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(RegenerateAllConfigsMessage $message): void
    {
        $this->logger->debug('Scheduled: regenerating all SSP configs');
        $this->metadataService->regenerateAllConfigs();
    }
}
