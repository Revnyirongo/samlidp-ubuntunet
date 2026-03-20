<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Handles the full lifecycle of provisioning a new tenant:
 *  1. Generate signing keypair
 *  2. Write SSP config files
 *  3. Send welcome email to technical contact
 */
class TenantProvisioner
{
    public function __construct(
        private readonly MetadataService        $metadataService,
        private readonly EntityManagerInterface $em,
        private readonly MailerInterface        $mailer,
        private readonly LoggerInterface        $logger,
        private readonly string                 $mailerFromAddress,
        private readonly string                 $mailerFromName,
        private readonly string                 $samlidpHostname,
    ) {}

    public function provision(Tenant $tenant): void
    {
        $this->logger->info('Provisioning new tenant', ['slug' => $tenant->getSlug()]);

        // 1. Generate signing keypair if not already set
        if (empty($tenant->getSigningCertificate())) {
            $this->metadataService->generateTenantKeypair($tenant);
        }

        // 2. Set entity ID if not set
        if (empty($tenant->getEntityId())) {
            $tenant->setEntityId(
                'https://' . $tenant->getSlug() . '.' . $this->samlidpHostname . '/saml2/idp/metadata.php'
            );
        }

        $this->em->flush();

        // 3. Generate SSP config
        $this->metadataService->regenerateConfigForTenant($tenant);

        // 4. Send welcome email
        if ($tenant->getTechnicalContactEmail()) {
            try {
                $this->sendWelcomeEmail($tenant);
            } catch (\Throwable $e) {
                // Non-fatal
                $this->logger->warning('Failed to send welcome email', [
                    'tenant' => $tenant->getSlug(),
                    'error'  => $e->getMessage(),
                ]);
            }
        }

        $this->logger->info('Tenant provisioned', ['slug' => $tenant->getSlug(), 'idpUrl' => $tenant->getIdpUrl()]);
    }

    public function deprovision(Tenant $tenant): void
    {
        $this->logger->info('Deprovisioning tenant', ['slug' => $tenant->getSlug()]);
        $tenant->setStatus(Tenant::STATUS_SUSPENDED);
        $this->em->flush();
        // SSP will ignore suspended tenants because config writer only writes active tenants
    }

    private function sendWelcomeEmail(Tenant $tenant): void
    {
        $email = (new Email())
            ->from(sprintf('"%s" <%s>', $this->mailerFromName, $this->mailerFromAddress))
            ->to($tenant->getTechnicalContactEmail())
            ->subject(sprintf('[Managed IdP] Your IdP "%s" is ready', $tenant->getName()))
            ->html($this->buildWelcomeHtml($tenant))
            ->text($this->buildWelcomeText($tenant));

        $this->mailer->send($email);
    }

    private function buildWelcomeHtml(Tenant $tenant): string
    {
        $name      = htmlspecialchars($tenant->getName());
        $idpUrl    = htmlspecialchars($tenant->getIdpUrl());
        $metaUrl   = htmlspecialchars($tenant->getMetadataUrl());
        $ssoUrl    = htmlspecialchars($tenant->getSsoUrl());
        $adminUrl  = htmlspecialchars('https://' . $this->samlidpHostname . '/admin');
        $greeting  = htmlspecialchars($tenant->getTechnicalContactName() ?: 'Administrator');

        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><title>Your IdP is Ready</title></head>
<body style="font-family: Arial, sans-serif; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
  <div style="background:#1a365d; padding:20px; border-radius:8px 8px 0 0;">
    <h1 style="color:#fff; margin:0; font-size:22px;">Your Managed IdP Is Ready</h1>
  </div>
  <div style="border:1px solid #ddd; border-top:none; padding:24px; border-radius:0 0 8px 8px;">
    <p>Dear {$greeting},</p>
    <p>Your SAML Identity Provider for <strong>{$name}</strong> has been provisioned successfully.</p>

    <h2 style="color:#1a365d; font-size:16px;">Your IdP Details</h2>
    <table style="border-collapse:collapse; width:100%;">
      <tr style="background:#f7f7f7;">
        <td style="padding:8px 12px; font-weight:bold; border:1px solid #ddd; width:35%;">IdP Base URL</td>
        <td style="padding:8px 12px; border:1px solid #ddd;"><a href="{$idpUrl}">{$idpUrl}</a></td>
      </tr>
      <tr>
        <td style="padding:8px 12px; font-weight:bold; border:1px solid #ddd;">Metadata URL</td>
        <td style="padding:8px 12px; border:1px solid #ddd;"><a href="{$metaUrl}">{$metaUrl}</a></td>
      </tr>
      <tr style="background:#f7f7f7;">
        <td style="padding:8px 12px; font-weight:bold; border:1px solid #ddd;">SSO Endpoint</td>
        <td style="padding:8px 12px; border:1px solid #ddd;">{$ssoUrl}</td>
      </tr>
    </table>

    <h2 style="color:#1a365d; font-size:16px; margin-top:24px;">Next Steps</h2>
    <ol>
      <li style="margin-bottom:8px;">Configure your authentication backend (LDAP/AD) in the <a href="{$adminUrl}">admin portal</a>.</li>
      <li style="margin-bottom:8px;">Register your Service Providers by importing their metadata URLs.</li>
      <li style="margin-bottom:8px;">Share your metadata URL (<code>{$metaUrl}</code>) with your SP operators and federations.</li>
      <li style="margin-bottom:8px;">Test your login flow end-to-end before going live.</li>
    </ol>

    <hr style="border:none; border-top:1px solid #eee; margin:24px 0;">
    <p style="font-size:12px; color:#666;">
      This message was sent by the managed identity platform at <a href="https://{$this->samlidpHostname}">{$this->samlidpHostname}</a>.
    </p>
  </div>
</body>
</html>
HTML;
    }

    private function buildWelcomeText(Tenant $tenant): string
    {
        return sprintf(
            "Your SAML IdP for %s is ready.\n\n" .
            "IdP URL:      %s\n" .
            "Metadata URL: %s\n" .
            "SSO URL:      %s\n\n" .
            "Log in to the admin portal at https://%s/admin to configure your backend and register service providers.\n",
            $tenant->getName(),
            $tenant->getIdpUrl(),
            $tenant->getMetadataUrl(),
            $tenant->getSsoUrl(),
            $this->samlidpHostname
        );
    }
}
