<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\MetadataService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'samlidp:metadata:refresh',
    description: 'Refresh SP metadata for all active tenants from their registered aggregate URLs.',
)]
class MetadataRefreshCommand extends Command
{
    public function __construct(
        private readonly MetadataService $metadataService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'tenant',
            't',
            InputOption::VALUE_OPTIONAL,
            'Only refresh a specific tenant slug (default: all active tenants)'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('UbuntuNet SAML IdP — Metadata Refresh');

        $tenantSlug = $input->getOption('tenant');

        try {
            if ($tenantSlug) {
                // Single tenant
                $tenant = $this->metadataService->getTenantRepository()->findOneBy(['slug' => $tenantSlug]);
                if (!$tenant) {
                    $io->error("Tenant '{$tenantSlug}' not found.");
                    return Command::FAILURE;
                }
                $results = $this->metadataService->refreshTenantMetadata($tenant);
                $io->success(sprintf(
                    'Tenant %s: %d imported, %d updated, %d errors.',
                    $tenantSlug,
                    $results['imported'],
                    $results['updated'],
                    count($results['errors'])
                ));
                foreach ($results['errors'] as $err) {
                    $io->warning($err);
                }
            } else {
                // All tenants
                $io->text('Refreshing metadata for all active tenants...');
                $this->metadataService->refreshAllTenantsMetadata();
                $io->success('All tenant metadata refreshed.');
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Metadata refresh failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
