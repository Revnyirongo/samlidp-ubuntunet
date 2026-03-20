<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\CheckCertificateExpiryMessage;
use App\Repository\ServiceProviderRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;

#[AsMessageHandler]
class CheckCertificateExpiryHandler
{
    private const WARN_DAYS = [60, 30, 14, 7, 1];

    public function __construct(
        private readonly ServiceProviderRepository $spRepo,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly string $mailerFromAddress,
    ) {}

    public function __invoke(CheckCertificateExpiryMessage $message): void
    {
        $this->logger->info('Scheduled: checking certificate expiry');
        $now = new \DateTimeImmutable();

        foreach ($this->spRepo->findAll() as $sp) {
            $expiry = $sp->getCertificateExpiresAt();
            if ($expiry === null) {
                continue;
            }

            $daysLeft = (int) $now->diff($expiry)->days;
            if ($expiry < $now) {
                $this->sendExpiryAlert($sp->getTenant()->getTechnicalContactEmail(), sprintf(
                    'SP certificate EXPIRED for "%s" (entityID: %s) in tenant "%s".',
                    $sp->getDisplayName(),
                    $sp->getEntityId(),
                    $sp->getTenant()->getName()
                ));
                continue;
            }

            if (in_array($daysLeft, self::WARN_DAYS, true)) {
                $this->sendExpiryAlert($sp->getTenant()->getTechnicalContactEmail(), sprintf(
                    'SP certificate for "%s" (entityID: %s) in tenant "%s" expires in %d day(s).',
                    $sp->getDisplayName(),
                    $sp->getEntityId(),
                    $sp->getTenant()->getName(),
                    $daysLeft
                ));
            }
        }
    }

    private function sendExpiryAlert(?string $to, string $message): void
    {
        if (!$to) {
            $this->logger->warning('Certificate expiry alert: no contact email', ['message' => $message]);
            return;
        }

        try {
            $email = (new Email())
                ->from($this->mailerFromAddress)
                ->to($to)
                ->subject('[UbuntuNet IdP] Certificate Expiry Warning')
                ->text($message . "\n\nPlease update the SP metadata via your IdP admin portal.\n\nUbuntuNet IdP Service");

            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send expiry alert email', ['error' => $e->getMessage()]);
        }
    }
}
