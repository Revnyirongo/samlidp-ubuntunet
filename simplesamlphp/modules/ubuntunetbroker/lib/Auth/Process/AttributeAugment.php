<?php

declare(strict_types=1);

namespace SimpleSAML\Module\ubuntunetbroker\Auth\Process;

use SimpleSAML\Assert\Assert;
use SimpleSAML\Auth\ProcessingFilter;

/**
 * Normalize upstream identities into a stable attribute set for downstream SPs.
 *
 * Goals:
 * - make institutional SAML and Google/Microsoft sign-in look consistent
 * - derive a reusable username/scope pair for the existing authproc pipeline
 * - attach CoP entitlements and group-like values in a central place
 */
class AttributeAugment extends ProcessingFilter
{
    private string $idp;
    private string $scope;
    private string $identifierSalt;
    private array $affiliation;
    private array $groups;
    private array $entitlements;
    private array $languages;
    private array $accessGroups;
    private array $functionalGroups;
    private array $institutionalGroups;

    public function __construct(array &$config, $reserved)
    {
        parent::__construct($config, $reserved);

        $this->idp = trim((string) ($config['idp'] ?? ''));
        $this->scope = trim((string) ($config['scope'] ?? ''));
        $this->identifierSalt = trim((string) ($config['identifier_salt'] ?? 'change-me-before-production'));
        $this->affiliation = $this->normalizeStringList($config['affiliation'] ?? ['member']);
        $this->groups = $this->normalizeStringList($config['groups'] ?? []);
        $this->entitlements = $this->normalizeStringList($config['entitlements'] ?? []);
        $this->languages = $this->normalizeStringList($config['languages'] ?? []);
        $this->accessGroups = $this->normalizeStringList($config['access_groups'] ?? []);
        $this->functionalGroups = $this->normalizeStringList($config['functional_groups'] ?? []);
        $this->institutionalGroups = $this->normalizeStringList($config['institutional_groups'] ?? []);
    }

    public function process(array &$state): void
    {
        Assert::keyExists($state, 'Attributes');

        $attributes = &$state['Attributes'];
        $this->normalizeAliases($attributes);

        $mail = $this->firstValue($attributes, ['mail', 'email']);
        $scope = $this->firstValue($attributes, ['scope', 'schacHomeOrganization']);
        if ($scope === '' && $mail !== '' && strpos($mail, '@') !== false) {
            $scope = substr($mail, strpos($mail, '@') + 1);
        }
        if ($scope === '') {
            $scope = $this->scope;
        }
        if ($scope !== '') {
            $attributes['scope'] = [$scope];
            if (!isset($attributes['schacHomeOrganization'])) {
                $attributes['schacHomeOrganization'] = [$scope];
            }
        }

        $username = $this->firstValue($attributes, ['username', 'uid', 'preferred_username']);
        if ($username === '' && $mail !== '') {
            $username = strstr($mail, '@', true) ?: $mail;
        }
        if ($username === '') {
            $seed = $this->firstValue($attributes, ['sub', 'oidc_sub', 'eduPersonPrincipalName']);
            if ($seed === '') {
                $seed = hash('sha256', $this->idp . '|' . $this->identifierSalt . '|' . json_encode($attributes));
            }
            $username = substr($seed, 0, 32);
        }
        $attributes['username'] = [$username];
        if (!isset($attributes['uid'])) {
            $attributes['uid'] = [$username];
        }
        if ($scope !== '') {
            $attributes['eduPersonPrincipalName'] = [$username . '@' . $scope];
        }

        if (!isset($attributes['mail']) && $mail !== '') {
            $attributes['mail'] = [$mail];
        }

        $givenName = $this->firstValue($attributes, ['givenName', 'given_name']);
        if ($givenName !== '') {
            $attributes['givenName'] = [$givenName];
        }

        $surname = $this->firstValue($attributes, ['sn', 'surName', 'family_name']);
        if ($surname !== '') {
            $attributes['surName'] = [$surname];
            $attributes['sn'] = [$surname];
        }

        $displayName = $this->firstValue($attributes, ['displayName', 'display_name', 'cn', 'name']);
        if ($displayName === '') {
            $displayName = trim($givenName . ' ' . $surname);
        }
        if ($displayName !== '') {
            $attributes['displayName'] = [$displayName];
            $attributes['display_name'] = [$displayName];
            $attributes['cn'] = [$displayName];
        }

        $affiliation = $this->firstValue($attributes, ['affiliation', 'eduPersonAffiliation']);
        if ($affiliation === '' && $this->affiliation !== []) {
            $affiliation = $this->affiliation[0];
        }
        if ($affiliation !== '') {
            $attributes['affiliation'] = [$affiliation];
            if (!isset($attributes['eduPersonAffiliation'])) {
                $attributes['eduPersonAffiliation'] = [$affiliation];
            }
            if ($scope !== '' && !isset($attributes['eduPersonScopedAffiliation'])) {
                $attributes['eduPersonScopedAffiliation'] = [$affiliation . '@' . $scope];
            }
        }

        if (!isset($attributes['realm'])) {
            $attributes['realm'] = [$scope !== '' ? $scope : $this->idp];
        }

        $persistentSeed = $mail !== ''
            ? strtolower($mail)
            : $this->firstValue($attributes, ['sub', 'oidc_sub', 'eduPersonPrincipalName', 'uid', 'username']);

        if ($persistentSeed !== '') {
            $pairwise = hash('sha256', $this->identifierSalt . '|pairwise|' . $this->idp . '|' . $persistentSeed);
            $targeted = 'urn:ubuntunet:targeted:' . hash('sha256', $this->identifierSalt . '|targeted|' . $this->idp . '|' . $persistentSeed);
            if (!isset($attributes['pairwise-id'])) {
                $attributes['pairwise-id'] = [$pairwise];
            }
            if (!isset($attributes['eduPersonTargetedID'])) {
                $attributes['eduPersonTargetedID'] = [$targeted];
            }
        }

        $languageValues = $this->prefixedValues('urn:ubuntunet:language:', $this->languages);
        $accessValues = $this->prefixedValues('urn:ubuntunet:access:', $this->accessGroups);
        $functionalValues = $this->prefixedValues('urn:ubuntunet:function:', $this->functionalGroups);
        $institutionalValues = $this->prefixedValues('urn:ubuntunet:institution:', $this->institutionalGroups);
        $groupEntitlements = $this->prefixedValues('urn:ubuntunet:group:', $this->groups);

        $attributes['eduPersonEntitlement'] = $this->mergeAttributeValues(
            $attributes['eduPersonEntitlement'] ?? [],
            array_merge(
                $this->entitlements,
                $languageValues,
                $accessValues,
                $functionalValues,
                $institutionalValues,
                $groupEntitlements
            )
        );

        $attributes['isMemberOf'] = $this->mergeAttributeValues(
            $attributes['isMemberOf'] ?? [],
            array_merge($this->groups, $this->accessGroups, $this->functionalGroups, $this->institutionalGroups)
        );

        if ($this->languages !== []) {
            $attributes['preferredLanguage'] = $this->mergeAttributeValues(
                $attributes['preferredLanguage'] ?? [],
                $this->languages
            );
        }

        if ($this->accessGroups !== []) {
            $attributes['copAccessGroup'] = $this->mergeAttributeValues(
                $attributes['copAccessGroup'] ?? [],
                $this->accessGroups
            );
        }

        if ($this->functionalGroups !== []) {
            $attributes['copFunctionalGroup'] = $this->mergeAttributeValues(
                $attributes['copFunctionalGroup'] ?? [],
                $this->functionalGroups
            );
        }

        if ($this->institutionalGroups !== []) {
            $attributes['copInstitutionalGroup'] = $this->mergeAttributeValues(
                $attributes['copInstitutionalGroup'] ?? [],
                $this->institutionalGroups
            );
        }
    }

    private function normalizeAliases(array &$attributes): void
    {
        foreach ($attributes as $name => $values) {
            if (!is_array($values)) {
                $attributes[$name] = [$values];
            }
        }

        $aliasMap = [
            'email' => 'mail',
            'given_name' => 'givenName',
            'family_name' => 'sn',
            'name' => 'displayName',
            'preferred_username' => 'username',
        ];

        foreach ($aliasMap as $source => $target) {
            if (!isset($attributes[$target]) && isset($attributes[$source])) {
                $attributes[$target] = $this->mergeAttributeValues([], $attributes[$source]);
            }
        }
    }

    private function firstValue(array $attributes, array $names): string
    {
        foreach ($names as $name) {
            if (!isset($attributes[$name]) || !is_array($attributes[$name])) {
                continue;
            }

            foreach ($attributes[$name] as $value) {
                if (!is_scalar($value)) {
                    continue;
                }

                $value = trim((string) $value);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    private function normalizeStringList($value): array
    {
        if (!is_array($value)) {
            $value = [$value];
        }

        $normalized = [];
        foreach ($value as $item) {
            $item = trim((string) $item);
            if ($item !== '') {
                $normalized[] = $item;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function mergeAttributeValues(array $existing, array $additional): array
    {
        $values = [];
        foreach (array_merge($existing, $additional) as $item) {
            if (!is_scalar($item)) {
                continue;
            }

            $item = trim((string) $item);
            if ($item !== '') {
                $values[] = $item;
            }
        }

        return array_values(array_unique($values));
    }

    private function prefixedValues(string $prefix, array $values): array
    {
        $prefixed = [];
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $prefixed[] = $prefix . strtolower(str_replace(' ', '-', $value));
            }
        }

        return $prefixed;
    }
}
