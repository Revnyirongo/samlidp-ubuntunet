<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\RefreshAllMetadataMessage;
use App\Service\MetadataService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class RefreshAllMetadataHandler
{
    public function __construct(
        private readonly MetadataService $metadataService,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(RefreshAllMetadataMessage $message): void
    {
        $this->logger->info('Scheduled: refreshing all tenant metadata');
        $this->metadataService->refreshAllTenantsMetadata();
    }
}
