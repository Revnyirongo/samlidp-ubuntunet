<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\IdpUser;
use App\Entity\RegistrationRequest;
use App\Entity\Tenant;
use App\Entity\TenantUserRegistrationRequest;
use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class NotificationMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly string $mailerFromAddress,
        private readonly string $mailerFromName,
        private readonly string $samlidpHostname,
        private readonly LoggerInterface $logger,
    ) {}

    public function sendPasswordReset(User $user, string $rawToken, bool $setPassword = false): void
    {
        $url = $this->urlGenerator->generate('app_reset_password', ['token' => $rawToken], UrlGeneratorInterface::ABSOLUTE_URL);
        $subject = $setPassword ? '[eduID.africa] Set your password' : '[eduID.africa] Reset your password';
        $intro = $setPassword
            ? 'Your administrator account is ready. Set your password using the secure link below.'
            : 'A password reset was requested for your eduID.africa administrator account.';

        $this->send(
            to: $user->getEmail(),
            subject: $subject,
            html: $this->wrapHtml(
                sprintf('<p>Hello %s,</p><p>%s</p><p><a href="%s" style="display:inline-block;padding:12px 18px;background:#174d7d;color:#fff;text-decoration:none;border-radius:10px;font-weight:700;">%s</a></p><p>This link expires in 60 minutes.</p>', htmlspecialchars($user->getFullName()), htmlspecialchars($intro), htmlspecialchars($url), $setPassword ? 'Set password' : 'Reset password')
            ),
            text: sprintf("Hello %s,\n\n%s\n\n%s\n\nThis link expires in 60 minutes.\n", $user->getFullName(), $intro, $url),
        );
    }

    public function sendPasswordChangedConfirmation(User $user): void
    {
        $this->send(
            to: $user->getEmail(),
            subject: '[eduID.africa] Your password was changed',
            html: $this->wrapHtml(sprintf('<p>Hello %s,</p><p>Your administrator password was changed successfully.</p><p>If you did not initiate this change, contact support immediately.</p>', htmlspecialchars($user->getFullName()))),
            text: sprintf("Hello %s,\n\nYour administrator password was changed successfully.\nIf you did not initiate this change, contact support immediately.\n", $user->getFullName()),
        );
    }

    public function sendTenantUserPasswordReset(IdpUser $user, string $rawToken, bool $setPassword = false): void
    {
        $tenant = $user->getTenant();
        $email = $user->getEmail();
        if (!$tenant instanceof Tenant || !is_string($email) || $email === '') {
            throw new \InvalidArgumentException('Tenant user reset email requires both a tenant and a user email.');
        }

        $path = $this->urlGenerator->generate('app_tenant_reset_password', ['token' => $rawToken], UrlGeneratorInterface::ABSOLUTE_PATH);
        $url = $this->tenantAbsoluteUrl($tenant, $path);
        $subject = $setPassword
            ? sprintf('[%s] Set your password', $tenant->getName())
            : sprintf('[%s] Reset your password', $tenant->getName());
        $intro = $setPassword
            ? 'Your institutional account is ready. Set your password using the secure link below.'
            : 'A password reset was requested for your institutional account.';
        $expiryText = $setPassword ? '24 hours' : '60 minutes';
        $name = $user->getDisplayName() ?? $user->getUsername();

        $this->send(
            to: $email,
            subject: $subject,
            html: $this->wrapHtml(
                sprintf(
                    '<p>Hello %s,</p><p>%s</p><p><a href="%s" style="display:inline-block;padding:12px 18px;background:#174d7d;color:#fff;text-decoration:none;border-radius:10px;font-weight:700;">%s</a></p><p>This link expires in %s.</p><p>Tenant: <strong>%s</strong></p>',
                    htmlspecialchars($name),
                    htmlspecialchars($intro),
                    htmlspecialchars($url),
                    $setPassword ? 'Set password' : 'Reset password',
                    $expiryText,
                    htmlspecialchars($tenant->getName()),
                )
            ),
            text: sprintf(
                "Hello %s,\n\n%s\n\n%s\n\nTenant: %s\n\nThis link expires in %s.\n",
                $name,
                $intro,
                $url,
                $tenant->getName(),
                $expiryText,
            ),
        );
    }

    public function sendTenantUserPasswordChangedConfirmation(IdpUser $user): void
    {
        $tenant = $user->getTenant();
        $email = $user->getEmail();
        if (!$tenant instanceof Tenant || !is_string($email) || $email === '') {
            throw new \InvalidArgumentException('Tenant user password confirmation email requires both a tenant and a user email.');
        }

        $name = $user->getDisplayName() ?? $user->getUsername();

        $this->send(
            to: $email,
            subject: sprintf('[%s] Your password was changed', $tenant->getName()),
            html: $this->wrapHtml(
                sprintf(
                    '<p>Hello %s,</p><p>Your password for <strong>%s</strong> was changed successfully.</p><p>If you did not initiate this change, contact your institution IT helpdesk immediately.</p>',
                    htmlspecialchars($name),
                    htmlspecialchars($tenant->getName()),
                )
            ),
            text: sprintf(
                "Hello %s,\n\nYour password for %s was changed successfully.\nIf you did not initiate this change, contact your institution IT helpdesk immediately.\n",
                $name,
                $tenant->getName(),
            ),
        );
    }

    public function sendRegistrationReceived(RegistrationRequest $request): void
    {
        $tenantName = $request->getRequestedTenant()?->getName() ?? 'the eduID.africa service';

        $this->send(
            to: $request->getEmail(),
            subject: '[eduID.africa] Registration request received',
            html: $this->wrapHtml(sprintf('<p>Hello %s,</p><p>Your registration request for <strong>%s</strong> has been received. An administrator will review it and email you once access is approved.</p>', htmlspecialchars($request->getFullName()), htmlspecialchars($tenantName))),
            text: sprintf("Hello %s,\n\nYour registration request for %s has been received. An administrator will review it and email you once access is approved.\n", $request->getFullName(), $tenantName),
        );
    }

    public function sendRegistrationReviewNotification(string $recipientEmail, RegistrationRequest $request): void
    {
        $tenantName = $request->getRequestedTenant()?->getName() ?? 'Unassigned';
        $reviewUrl = $this->urlGenerator->generate('admin_registration_request_index', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $this->send(
            to: $recipientEmail,
            subject: '[eduID.africa] New tenant admin registration request',
            html: $this->wrapHtml(sprintf('<p>A new tenant administrator registration request was submitted.</p><p><strong>Name:</strong> %s<br><strong>Email:</strong> %s<br><strong>Tenant:</strong> %s</p><p><a href="%s">Review requests</a></p>', htmlspecialchars($request->getFullName()), htmlspecialchars($request->getEmail()), htmlspecialchars($tenantName), htmlspecialchars($reviewUrl))),
            text: sprintf("A new tenant administrator registration request was submitted.\n\nName: %s\nEmail: %s\nTenant: %s\n\nReview: %s\n", $request->getFullName(), $request->getEmail(), $tenantName, $reviewUrl),
        );
    }

    public function sendRegistrationApprovedWithPasswordSetup(User $user, RegistrationRequest $request, string $rawToken): void
    {
        $url = $this->urlGenerator->generate('app_reset_password', ['token' => $rawToken], UrlGeneratorInterface::ABSOLUTE_URL);
        $tenantName = $request->getRequestedTenant()?->getName() ?? 'eduID.africa';

        $this->send(
            to: $user->getEmail(),
            subject: '[eduID.africa] Your registration was approved',
            html: $this->wrapHtml(sprintf('<p>Hello %s,</p><p>Your administrator registration for <strong>%s</strong> was approved.</p><p><a href="%s" style="display:inline-block;padding:12px 18px;background:#174d7d;color:#fff;text-decoration:none;border-radius:10px;font-weight:700;">Set password</a></p><p>This link expires in 24 hours.</p>', htmlspecialchars($user->getFullName()), htmlspecialchars($tenantName), htmlspecialchars($url))),
            text: sprintf("Hello %s,\n\nYour administrator registration for %s was approved.\nSet your password here: %s\n\nThis link expires in 24 hours.\n", $user->getFullName(), $tenantName, $url),
        );
    }

    public function sendRegistrationApprovedExistingUser(User $user, RegistrationRequest $request): void
    {
        $loginUrl = $this->urlGenerator->generate('app_login', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $tenantName = $request->getRequestedTenant()?->getName() ?? 'eduID.africa';

        $this->send(
            to: $user->getEmail(),
            subject: '[eduID.africa] Access granted',
            html: $this->wrapHtml(sprintf('<p>Hello %s,</p><p>Your administrator registration for <strong>%s</strong> was approved. You can now sign in using your existing account.</p><p><a href="%s">Open login</a></p>', htmlspecialchars($user->getFullName()), htmlspecialchars($tenantName), htmlspecialchars($loginUrl))),
            text: sprintf("Hello %s,\n\nYour administrator registration for %s was approved. You can now sign in using your existing account.\n\nLogin: %s\n", $user->getFullName(), $tenantName, $loginUrl),
        );
    }

    public function sendRegistrationRejected(RegistrationRequest $request): void
    {
        $tenantName = $request->getRequestedTenant()?->getName() ?? 'eduID.africa';
        $notes = trim((string) $request->getReviewNotes());
        $notesText = $notes !== '' ? "\n\nReview note: " . $notes : '';
        $notesHtml = $notes !== '' ? '<p><strong>Review note:</strong> ' . htmlspecialchars($notes) . '</p>' : '';

        $this->send(
            to: $request->getEmail(),
            subject: '[eduID.africa] Registration request update',
            html: $this->wrapHtml(sprintf('<p>Hello %s,</p><p>Your administrator registration request for <strong>%s</strong> was not approved at this time.</p>%s', htmlspecialchars($request->getFullName()), htmlspecialchars($tenantName), $notesHtml)),
            text: sprintf("Hello %s,\n\nYour administrator registration request for %s was not approved at this time.%s\n", $request->getFullName(), $tenantName, $notesText),
        );
    }

    public function sendTenantRegistrationReceived(TenantUserRegistrationRequest $request): void
    {
        $this->send(
            to: $request->getEmail(),
            subject: sprintf('[%s] Registration request received', $request->getTenant()->getName()),
            html: $this->wrapHtml(sprintf(
                '<p>Hello %s,</p><p>Your account request for <strong>%s</strong> has been received. A tenant administrator will review it and email you after approval.</p>',
                htmlspecialchars($request->getFullName()),
                htmlspecialchars($request->getTenant()->getName()),
            )),
            text: sprintf(
                "Hello %s,\n\nYour account request for %s has been received. A tenant administrator will review it and email you after approval.\n",
                $request->getFullName(),
                $request->getTenant()->getName(),
            ),
        );
    }

    public function sendTenantRegistrationReviewNotification(string $recipientEmail, TenantUserRegistrationRequest $request): void
    {
        $reviewUrl = $this->urlGenerator->generate(
            'admin_tenant_registration_request_index',
            ['tenant' => $request->getTenant()->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $this->send(
            to: $recipientEmail,
            subject: sprintf('[%s] New user registration request', $request->getTenant()->getName()),
            html: $this->wrapHtml(sprintf(
                '<p>A new tenant user registration request was submitted.</p><p><strong>Tenant:</strong> %s<br><strong>Name:</strong> %s<br><strong>Email:</strong> %s<br><strong>Username:</strong> %s</p><p><a href="%s">Review requests</a></p>',
                htmlspecialchars($request->getTenant()->getName()),
                htmlspecialchars($request->getFullName()),
                htmlspecialchars($request->getEmail()),
                htmlspecialchars($request->getUsername()),
                htmlspecialchars($reviewUrl),
            )),
            text: sprintf(
                "A new tenant user registration request was submitted.\n\nTenant: %s\nName: %s\nEmail: %s\nUsername: %s\n\nReview: %s\n",
                $request->getTenant()->getName(),
                $request->getFullName(),
                $request->getEmail(),
                $request->getUsername(),
                $reviewUrl,
            ),
        );
    }

    public function sendTenantRegistrationRejected(TenantUserRegistrationRequest $request): void
    {
        $notes = trim((string) $request->getReviewNotes());
        $notesText = $notes !== '' ? "\n\nReview note: " . $notes : '';
        $notesHtml = $notes !== '' ? '<p><strong>Review note:</strong> ' . htmlspecialchars($notes) . '</p>' : '';

        $this->send(
            to: $request->getEmail(),
            subject: sprintf('[%s] Registration request update', $request->getTenant()->getName()),
            html: $this->wrapHtml(sprintf(
                '<p>Hello %s,</p><p>Your account request for <strong>%s</strong> was not approved at this time.</p>%s',
                htmlspecialchars($request->getFullName()),
                htmlspecialchars($request->getTenant()->getName()),
                $notesHtml,
            )),
            text: sprintf(
                "Hello %s,\n\nYour account request for %s was not approved at this time.%s\n",
                $request->getFullName(),
                $request->getTenant()->getName(),
                $notesText,
            ),
        );
    }

    public function sendTestMessage(string $recipientEmail): void
    {
        $this->send(
            to: $recipientEmail,
            subject: '[eduID.africa] SMTP test message',
            html: $this->wrapHtml('<p>This is a test message from the eduID.africa mail configuration.</p><p>If you received this, SMTP transport, authentication, and sender formatting are working.</p>'),
            text: "This is a test message from the eduID.africa mail configuration.\n\nIf you received this, SMTP transport, authentication, and sender formatting are working.\n",
        );
    }

    private function send(string $to, string $subject, string $html, string $text): void
    {
        $email = (new Email())
            ->from(sprintf('"%s" <%s>', $this->mailerFromName, $this->mailerFromAddress))
            ->to($to)
            ->subject($subject)
            ->html($html)
            ->text($text);

        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Notification email delivery failed.', [
                'to' => $to,
                'subject' => $subject,
                'mailer_from' => $this->mailerFromAddress,
                'exception' => $e,
            ]);

            throw $e;
        }
    }

    private function wrapHtml(string $body): string
    {
        $host = htmlspecialchars($this->samlidpHostname);

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<body style="font-family:Arial,sans-serif;color:#0f172a;max-width:620px;margin:0 auto;padding:24px;background:#f8fafc;">
  <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:16px;padding:24px;">
    <h1 style="margin:0 0 20px;font-size:22px;color:#174d7d;">eduID.africa</h1>
    {$body}
    <hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0;">
    <p style="font-size:12px;color:#64748b;margin:0;">Sent from {$host}</p>
  </div>
</body>
</html>
HTML;
    }

    private function tenantAbsoluteUrl(Tenant $tenant, string $path): string
    {
        $host = preg_replace('#^https?://#', '', $this->samlidpHostname) ?: $this->samlidpHostname;

        return sprintf('https://%s.%s%s', $tenant->getSlug(), $host, $path);
    }
}
