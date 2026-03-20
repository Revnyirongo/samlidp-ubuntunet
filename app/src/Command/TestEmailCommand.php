<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\MailerStatus;
use App\Service\NotificationMailer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:mail:test',
    description: 'Send a test email using the configured mail transport.',
)]
class TestEmailCommand extends Command
{
    public function __construct(
        private readonly NotificationMailer $mailer,
        private readonly MailerStatus $mailerStatus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('recipient', InputArgument::REQUIRED, 'Recipient email address');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $recipient = strtolower(trim((string) $input->getArgument('recipient')));

        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $io->error(sprintf('Invalid recipient email: %s', $recipient));
            return Command::FAILURE;
        }

        if (!$this->mailerStatus->isEnabled()) {
            $io->error('Mail delivery is disabled. Configure MAILER_DSN, MAILER_FROM_ADDRESS, and MAILER_FROM_NAME first.');
            return Command::FAILURE;
        }

        try {
            $this->mailer->sendTestMessage($recipient);
        } catch (\Throwable $e) {
            $io->error('Mail send failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf('Test email sent to %s.', $recipient));

        return Command::SUCCESS;
    }
}
