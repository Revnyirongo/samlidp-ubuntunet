<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;

class PublicFormProtection
{
    public const PUBLIC_REGISTER = 'public_register';
    public const TENANT_REGISTER = 'tenant_register';
    public const PUBLIC_FORGOT_PASSWORD = 'public_forgot_password';
    public const TENANT_FORGOT_PASSWORD = 'tenant_forgot_password';

    private const SESSION_KEY = '_public_form_protection';
    private const MIN_SUBMIT_SECONDS = 3;
    private const MAX_CHALLENGE_AGE_SECONDS = 7200;

    public function __construct(
        #[Autowire(service: 'limiter.public_registration')]
        private readonly RateLimiterFactory $publicRegistrationLimiter,
        #[Autowire(service: 'limiter.tenant_registration')]
        private readonly RateLimiterFactory $tenantRegistrationLimiter,
        #[Autowire(service: 'limiter.public_password_reset')]
        private readonly RateLimiterFactory $publicPasswordResetLimiter,
        #[Autowire(service: 'limiter.tenant_password_reset')]
        private readonly RateLimiterFactory $tenantPasswordResetLimiter,
    ) {}

    public function issueChallenge(Request $request, string $formKey): string
    {
        $session = $request->getSession();
        $challenge = bin2hex(random_bytes(16));
        $state = $session->get(self::SESSION_KEY, []);
        $state[$formKey] = [
            'challenge' => $challenge,
            'issued_at' => time(),
        ];
        $session->set(self::SESSION_KEY, $state);

        return $challenge;
    }

    public function validateSubmission(Request $request, string $formKey, string $scope = 'global'): ?string
    {
        if (trim($request->request->getString('company')) !== '') {
            return 'Request rejected. Please reload the page and try again.';
        }

        $session = $request->getSession();
        $state = $session->get(self::SESSION_KEY, []);
        $entry = $state[$formKey] ?? null;
        unset($state[$formKey]);
        $session->set(self::SESSION_KEY, $state);

        if (!is_array($entry)) {
            return 'Please reload the page and submit the form again.';
        }

        $submittedChallenge = $request->request->getString('_challenge');
        if ($submittedChallenge === '' || !hash_equals((string) $entry['challenge'], $submittedChallenge)) {
            return 'Please reload the page and submit the form again.';
        }

        $issuedAt = (int) ($entry['issued_at'] ?? 0);
        $age = time() - $issuedAt;
        if ($age < self::MIN_SUBMIT_SECONDS) {
            return 'Please wait a moment and submit the form again.';
        }

        if ($age > self::MAX_CHALLENGE_AGE_SECONDS) {
            return 'This form expired. Please reload the page and try again.';
        }

        $limiter = $this->limiterFor($formKey)->create($this->rateLimitKey($request, $scope));
        $limit = $limiter->consume(1);
        if (!$limit->isAccepted()) {
            return 'Too many requests from this address. Please try again later.';
        }

        return null;
    }

    private function limiterFor(string $formKey): RateLimiterFactory
    {
        return match ($formKey) {
            self::PUBLIC_REGISTER => $this->publicRegistrationLimiter,
            self::TENANT_REGISTER => $this->tenantRegistrationLimiter,
            self::PUBLIC_FORGOT_PASSWORD => $this->publicPasswordResetLimiter,
            self::TENANT_FORGOT_PASSWORD => $this->tenantPasswordResetLimiter,
            default => $this->publicRegistrationLimiter,
        };
    }

    private function rateLimitKey(Request $request, string $scope): string
    {
        $ip = $request->getClientIp() ?? 'unknown';

        return $scope . '|' . $ip;
    }
}
