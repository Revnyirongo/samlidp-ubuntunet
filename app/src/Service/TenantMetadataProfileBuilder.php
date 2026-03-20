<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Tenant;

final class TenantMetadataProfileBuilder
{
    public function __construct(
        private readonly string $samlidpHostname,
    ) {}

    public function build(Tenant $tenant): array
    {
        $profile = $tenant->getMetadataProfile();
        $orgName = $tenant->getOrganizationName() ?: $tenant->getName();
        $orgUrl = $this->validUrl($tenant->getOrganizationUrl()) ?: $tenant->getIdpUrl();
        $orgHost = $this->urlHost($orgUrl);

        $displayName = $this->string($profile['display_name'] ?? null) ?: $orgName;
        $description = $this->string($profile['description'] ?? null)
            ?: sprintf('Identity Provider for %s', $displayName);
        $informationUrl = $this->validUrl($profile['information_url'] ?? null) ?: $orgUrl;
        $privacyUrl = $this->validUrl($profile['privacy_statement_url'] ?? null);

        $domainHints = $this->normalizeUniqueList($profile['domain_hints'] ?? []);
        if ($orgHost !== null) {
            $domainHints[] = $orgHost;
        }
        $domainHints = $this->normalizeUniqueList($domainHints);

        $scopes = $this->normalizeUniqueList($profile['scopes'] ?? []);
        $scopes[] = sprintf('%s.%s', $tenant->getSlug(), $this->samlidpHostname);
        if ($orgHost !== null) {
            $scopes[] = $orgHost;
        }
        $scopes = $this->normalizeUniqueList($scopes);

        $uiInfo = [
            'DisplayName' => ['en' => $displayName],
            'Description' => ['en' => $description],
            'InformationURL' => ['en' => $informationUrl],
        ];

        if ($privacyUrl !== null) {
            $uiInfo['PrivacyStatementURL'] = ['en' => $privacyUrl];
        }

        $keywords = $this->normalizeKeywords($profile['keywords'] ?? []);
        if ($keywords !== []) {
            $uiInfo['Keywords'] = ['en' => $keywords];
        }

        $logoUrl = $this->validLogoUrl($tenant->getLogoUrl());
        if ($logoUrl !== null) {
            $uiInfo['Logo'] = [[
                'url' => $logoUrl,
                'height' => $this->positiveInt($profile['logo_height'] ?? null) ?? 80,
                'width' => $this->positiveInt($profile['logo_width'] ?? null) ?? 200,
                'lang' => 'en',
            ]];
        }

        $discoHints = [];
        if ($domainHints !== []) {
            $discoHints['DomainHint'] = $domainHints;
        }

        $geoHints = $this->normalizeGeoHints($profile['geolocation_hints'] ?? []);
        if ($geoHints !== []) {
            $discoHints['GeolocationHint'] = $geoHints;
        }

        $contacts = [];
        if ($tenant->getTechnicalContactEmail()) {
            $contacts[] = [
                'contactType' => 'technical',
                'emailAddress' => $tenant->getTechnicalContactEmail(),
                'givenName' => $tenant->getTechnicalContactName() ?: $orgName,
            ];
        }

        $supportEmail = $this->validEmail($profile['support_contact_email'] ?? null);
        if ($supportEmail !== null) {
            $contacts[] = [
                'contactType' => 'support',
                'emailAddress' => $supportEmail,
                'givenName' => $this->string($profile['support_contact_name'] ?? null) ?: $orgName,
            ];
        }

        $securityEmail = $this->validEmail($profile['security_contact_email'] ?? null);
        if ($securityEmail !== null) {
            $contacts[] = [
                'contactType' => 'other',
                'emailAddress' => 'mailto:' . $securityEmail,
                'givenName' => $this->string($profile['security_contact_name'] ?? null) ?: 'Security Response Team',
                'attributes' => [
                    'xmlns:remd' => 'http://refeds.org/metadata',
                    'remd:contactType' => 'http://refeds.org/metadata/contactType/security',
                ],
            ];
        }

        $registrationAuthority = $this->string($profile['registration_authority'] ?? null);
        if ($registrationAuthority === null && $tenant->getPublishedFederations() !== []) {
            $registrationAuthority = 'https://' . $this->samlidpHostname;
        }

        $registrationInfo = [];
        if ($registrationAuthority !== null) {
            $registrationInfo['RegistrationAuthority'] = $registrationAuthority;

            $registrationPolicyUrl = $this->validUrl($profile['registration_policy_url'] ?? null)
                ?: sprintf(
                    'https://%s/federation/metadata-registration-practice-statement',
                    $this->samlidpHostname
                );
            $registrationInfo['RegistrationPolicy'] = ['en' => $registrationPolicyUrl];

            $registrationInstant = $this->registrationInstant($profile['registration_instant'] ?? null);
            if ($registrationInstant !== null) {
                $registrationInfo['RegistrationInstant'] = $registrationInstant;
            }
        }

        return [
            'display_name' => $displayName,
            'description' => $description,
            'organization_name' => $orgName,
            'organization_url' => $orgUrl,
            'ui_info' => $uiInfo,
            'disco_hints' => $discoHints,
            'contacts' => $contacts,
            'scopes' => $scopes,
            'registration_info' => $registrationInfo,
        ];
    }

    private function string(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private function validUrl(mixed $value): ?string
    {
        $value = $this->string($value);
        if ($value === null || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return $value;
    }

    private function validLogoUrl(mixed $value): ?string
    {
        $value = $this->string($value);
        if ($value === null) {
            return null;
        }

        if (str_starts_with($value, 'data:image/')) {
            return $value;
        }

        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        return $scheme === 'https' ? $value : null;
    }

    private function validEmail(mixed $value): ?string
    {
        $value = $this->string($value);
        if ($value === null || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $value;
    }

    private function positiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        $int = (int) $value;
        return $int > 0 ? $int : null;
    }

    private function registrationInstant(mixed $value): ?string
    {
        $value = $this->string($value);
        if ($value === null) {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z');
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeKeywords(mixed $value): array
    {
        $keywords = $this->normalizeUniqueList($value);

        return array_values(array_filter(
            array_map(
                static fn (string $keyword): string => str_replace('+', ' ', trim($keyword)),
                $keywords
            ),
            static fn (string $keyword): bool => $keyword !== ''
        ));
    }

    private function normalizeGeoHints(mixed $value): array
    {
        return array_values(array_filter(
            $this->normalizeUniqueList($value),
            static fn (string $hint): bool => str_starts_with($hint, 'geo:')
        ));
    }

    private function normalizeUniqueList(mixed $value): array
    {
        if (is_string($value)) {
            $parts = preg_split('/[\r\n,]+/', $value) ?: [];
        } elseif (is_array($value)) {
            $parts = $value;
        } else {
            $parts = [];
        }

        $normalized = [];
        foreach ($parts as $item) {
            if (!is_string($item)) {
                continue;
            }

            $trimmed = trim($item);
            if ($trimmed !== '') {
                $normalized[] = $trimmed;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function urlHost(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }

        return strtolower($host);
    }
}
