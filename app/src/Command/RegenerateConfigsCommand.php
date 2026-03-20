<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\TenantRepository;
use App\Service\MetadataService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'samlidp:config:regenerate',
    description: 'Regenerate SimpleSAMLphp config files for all (or one) active tenant(s).',
)]
class RegenerateConfigsCommand extends Command
{
    public function __construct(
        private readonly MetadataService $metadataService,
        private readonly TenantRepository $tenantRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('tenant', 't', InputOption::VALUE_OPTIONAL, 'Tenant slug (default: all active)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $slug = $input->getOption('tenant');

        if ($slug) {
            $tenant = $this->tenantRepository->findOneBy(['slug' => $slug]);
            if (!$tenant) {
                $io->error("Tenant '{$slug}' not found.");
                return Command::FAILURE;
            }
            $this->metadataService->regenerateConfigForTenant($tenant);
            $io->success("Config regenerated for tenant: {$slug}");
        } else {
            $io->text('Regenerating configs for all active tenants...');
            $this->metadataService->regenerateAllConfigs();
            $io->success('All active tenant configs regenerated.');
        }

        return Command::SUCCESS;
    }
}
