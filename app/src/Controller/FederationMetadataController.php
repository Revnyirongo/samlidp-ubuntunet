<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\TenantRepository;
use App\Entity\Tenant;
use App\Service\TenantMetadataProfileBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Publishes SAML metadata for individual tenants and a combined aggregate
 * for federation registration (e.g. eduGAIN via SAFIRE/EaPConnect).
 */
class FederationMetadataController extends AbstractController
{
    public function __construct(
        private readonly TenantRepository $tenantRepo,
        private readonly TenantMetadataProfileBuilder $metadataProfileBuilder,
        private readonly string           $samlidpHostname,
    ) {}

    /**
     * Aggregate metadata for ALL active tenants.
     * Publish this URL to your federation operator.
     */
    #[Route('/api/federation/metadata', name: 'federation_metadata_aggregate', methods: ['GET'])]
    public function aggregate(): Response
    {
        $tenants = $this->tenantRepo->findAllActive();

        $xml = $this->buildEntitiesDescriptor($tenants);

        return new Response($xml, 200, [
            'Content-Type' => 'application/samlmetadata+xml; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    #[Route('/api/federation/{federation}/metadata', name: 'federation_metadata_filtered', methods: ['GET'])]
    public function aggregateForFederation(string $federation): Response
    {
        $requested = strtolower(trim($federation));
        $tenants = array_values(array_filter(
            $this->tenantRepo->findAllActive(),
            static fn (Tenant $tenant): bool => in_array($requested, $tenant->getPublishedFederations(), true)
        ));

        $xml = $this->buildEntitiesDescriptor($tenants);

        return new Response($xml, 200, [
            'Content-Type' => 'application/samlmetadata+xml; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    /**
     * Single tenant metadata (proxied from SimpleSAMLphp).
     * Available publicly so SPs can register it.
     */
    #[Route('/api/tenant/{slug}/metadata', name: 'tenant_metadata', methods: ['GET'])]
    public function tenantMetadata(string $slug): Response
    {
        $tenant = $this->tenantRepo->findActiveBySlug($slug);

        if ($tenant === null) {
            return new Response('Tenant not found or not active.', 404, ['Content-Type' => 'text/plain']);
        }

        // Build IdP metadata XML from tenant data
        $xml = $this->buildIdpMetadata($tenant);

        return new Response($xml, 200, [
            'Content-Type'  => 'application/samlmetadata+xml; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    // ── XML builders ─────────────────────────────────────────

    private function buildEntitiesDescriptor(array $tenants): string
    {
        $now = new \DateTimeImmutable();
        $nowIso = $now->format(\DateTimeInterface::ATOM);
        $validUntil = $now->modify('+7 days')->format(\DateTimeInterface::ATOM);
        $publisher = htmlspecialchars('https://' . $this->samlidpHostname, ENT_XML1);

        $entityBlocks = '';
        foreach ($tenants as $tenant) {
            $entityBlocks .= "\n" . $this->buildIdpMetadata($tenant, indent: '    ');
        }

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<md:EntitiesDescriptor
    xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata"
    xmlns:ds="http://www.w3.org/2000/09/xmldsig#"
    xmlns:mdrpi="urn:oasis:names:tc:SAML:metadata:rpi"
    xmlns:mdui="urn:oasis:names:tc:SAML:metadata:ui"
    xmlns:shibmd="urn:mace:shibboleth:metadata:1.0"
    xmlns:mdattr="urn:oasis:names:tc:SAML:metadata:attribute"
    xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion"
    Name="https://{$this->samlidpHostname}"
    validUntil="{$validUntil}"
    cacheDuration="PT1H">

    <!-- UbuntuNet Multitenant IdP Aggregate -->
    <!-- Generated: {$nowIso} -->
    <!-- Tenants: {$this->count($tenants)} -->
    <md:Extensions>
        <mdrpi:PublicationInfo publisher="{$publisher}" creationInstant="{$nowIso}"/>
    </md:Extensions>
{$entityBlocks}
</md:EntitiesDescriptor>
XML;
    }

    private function buildIdpMetadata(Tenant $tenant, string $indent = ''): string
    {
        $profile = $this->metadataProfileBuilder->build($tenant);
        $entityId = htmlspecialchars($tenant->getEntityId(), ENT_XML1);
        $ssoUrl   = htmlspecialchars($tenant->getSsoUrl(),   ENT_XML1);
        $sloUrl   = htmlspecialchars($tenant->getSloUrl(),   ENT_XML1);
        $orgName  = htmlspecialchars($profile['organization_name'], ENT_XML1);
        $orgUrl   = htmlspecialchars($profile['organization_url'], ENT_XML1);
        $certB64  = $tenant->getSigningCertificate()
            ? preg_replace('/\s+/', '', $tenant->getSigningCertificate())
            : '';

        $certBlock = '';
        if ($certB64) {
            $certFormatted = chunk_split($certB64, 64, "\n                    ");
            $certBlock = <<<XML

                <md:KeyDescriptor use="signing">
                    <ds:KeyInfo>
                        <ds:X509Data>
                            <ds:X509Certificate>{$certFormatted}</ds:X509Certificate>
                        </ds:X509Data>
                    </ds:KeyInfo>
                </md:KeyDescriptor>
XML;
        }

        $contactBlock = $this->renderContacts($profile['contacts'], $indent . '    ');
        $descriptorExtensions = $this->renderRegistrationInfo($profile['registration_info'], $indent . '    ');
        $idpExtensions = $this->renderIdpExtensions($profile, $indent . '        ');

        return <<<XML
{$indent}<md:EntityDescriptor entityID="{$entityId}">
{$descriptorExtensions}
{$indent}    <md:IDPSSODescriptor
{$indent}        WantAuthnRequestsSigned="true"
{$indent}        protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">{$certBlock}

{$indent}        <md:NameIDFormat>urn:oasis:names:tc:SAML:2.0:nameid-format:persistent</md:NameIDFormat>
{$indent}        <md:NameIDFormat>urn:oasis:names:tc:SAML:2.0:nameid-format:transient</md:NameIDFormat>

{$indent}        <md:SingleSignOnService
{$indent}            Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST"
{$indent}            Location="{$ssoUrl}"/>
{$indent}        <md:SingleSignOnService
{$indent}            Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect"
{$indent}            Location="{$ssoUrl}"/>

{$indent}        <md:SingleLogoutService
{$indent}            Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect"
{$indent}            Location="{$sloUrl}"/>
{$indent}        <md:SingleLogoutService
{$indent}            Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST"
{$indent}            Location="{$sloUrl}"/>
{$idpExtensions}
{$indent}    </md:IDPSSODescriptor>

{$indent}    <md:Organization>
{$indent}        <md:OrganizationName xml:lang="en">{$orgName}</md:OrganizationName>
{$indent}        <md:OrganizationDisplayName xml:lang="en">{$orgName}</md:OrganizationDisplayName>
{$indent}        <md:OrganizationURL xml:lang="en">{$orgUrl}</md:OrganizationURL>
{$indent}    </md:Organization>
{$contactBlock}
{$indent}</md:EntityDescriptor>
XML;
    }

    private function count(array $arr): int { return count($arr); }

    private function renderRegistrationInfo(array $registrationInfo, string $indent): string
    {
        if ($registrationInfo === [] || empty($registrationInfo['RegistrationAuthority'])) {
            return '';
        }

        $authority = htmlspecialchars((string) $registrationInfo['RegistrationAuthority'], ENT_XML1);
        $instant = '';
        if (!empty($registrationInfo['RegistrationInstant'])) {
            $value = htmlspecialchars((string) $registrationInfo['RegistrationInstant'], ENT_XML1);
            $instant = ' registrationInstant="' . $value . '"';
        }

        $policyBlock = '';
        foreach (($registrationInfo['RegistrationPolicy'] ?? []) as $lang => $url) {
            if (!is_string($lang) || !is_string($url) || $url === '') {
                continue;
            }

            $policyBlock .= sprintf(
                "\n%s        <mdrpi:RegistrationPolicy xml:lang=\"%s\">%s</mdrpi:RegistrationPolicy>",
                $indent,
                htmlspecialchars($lang, ENT_XML1),
                htmlspecialchars($url, ENT_XML1),
            );
        }

        return <<<XML
{$indent}<md:Extensions>
{$indent}    <mdrpi:RegistrationInfo registrationAuthority="{$authority}"{$instant}>{$policyBlock}
{$indent}    </mdrpi:RegistrationInfo>
{$indent}</md:Extensions>
XML;
    }

    private function renderIdpExtensions(array $profile, string $indent): string
    {
        $lines = [];
        $lines[] = $indent . '<md:Extensions>';

        foreach ($profile['scopes'] as $scope) {
            $lines[] = sprintf(
                '%s    <shibmd:Scope regexp="false">%s</shibmd:Scope>',
                $indent,
                htmlspecialchars($scope, ENT_XML1),
            );
        }

        $lines[] = $indent . '    <mdui:UIInfo>';
        foreach (($profile['ui_info']['DisplayName'] ?? []) as $lang => $value) {
            $lines[] = $this->localizedXmlLine('mdui:DisplayName', $lang, $value, $indent . '        ');
        }
        foreach (($profile['ui_info']['Description'] ?? []) as $lang => $value) {
            $lines[] = $this->localizedXmlLine('mdui:Description', $lang, $value, $indent . '        ');
        }
        foreach (($profile['ui_info']['InformationURL'] ?? []) as $lang => $value) {
            $lines[] = $this->localizedXmlLine('mdui:InformationURL', $lang, $value, $indent . '        ');
        }
        foreach (($profile['ui_info']['PrivacyStatementURL'] ?? []) as $lang => $value) {
            $lines[] = $this->localizedXmlLine('mdui:PrivacyStatementURL', $lang, $value, $indent . '        ');
        }
        foreach (($profile['ui_info']['Keywords'] ?? []) as $lang => $keywords) {
            if (!is_array($keywords) || $keywords === []) {
                continue;
            }

            $lines[] = sprintf(
                '%s<mdui:Keywords xml:lang="%s">%s</mdui:Keywords>',
                $indent . '        ',
                htmlspecialchars((string) $lang, ENT_XML1),
                htmlspecialchars(implode(' ', $keywords), ENT_XML1),
            );
        }
        foreach (($profile['ui_info']['Logo'] ?? []) as $logo) {
            if (!is_array($logo) || empty($logo['url'])) {
                continue;
            }

            $lang = !empty($logo['lang']) ? ' xml:lang="' . htmlspecialchars((string) $logo['lang'], ENT_XML1) . '"' : '';
            $lines[] = sprintf(
                '%s<mdui:Logo width="%d" height="%d"%s>%s</mdui:Logo>',
                $indent . '        ',
                (int) ($logo['width'] ?? 200),
                (int) ($logo['height'] ?? 80),
                $lang,
                htmlspecialchars((string) $logo['url'], ENT_XML1),
            );
        }
        $lines[] = $indent . '    </mdui:UIInfo>';

        if (($profile['disco_hints']['DomainHint'] ?? []) !== [] || ($profile['disco_hints']['GeolocationHint'] ?? []) !== []) {
            $lines[] = $indent . '    <mdui:DiscoHints>';
            foreach (($profile['disco_hints']['DomainHint'] ?? []) as $hint) {
                $lines[] = sprintf('%s<mdui:DomainHint>%s</mdui:DomainHint>', $indent . '        ', htmlspecialchars($hint, ENT_XML1));
            }
            foreach (($profile['disco_hints']['GeolocationHint'] ?? []) as $hint) {
                $lines[] = sprintf('%s<mdui:GeolocationHint>%s</mdui:GeolocationHint>', $indent . '        ', htmlspecialchars($hint, ENT_XML1));
            }
            $lines[] = $indent . '    </mdui:DiscoHints>';
        }

        $lines[] = $indent . '</md:Extensions>';

        return "\n" . implode("\n", $lines);
    }

    private function renderContacts(array $contacts, string $indent): string
    {
        if ($contacts === []) {
            return '';
        }

        $blocks = [];
        foreach ($contacts as $contact) {
            $attributes = '';
            foreach (($contact['attributes'] ?? []) as $name => $value) {
                if (!is_string($name) || !is_string($value) || $value === '') {
                    continue;
                }

                $attributes .= sprintf(
                    ' %s="%s"',
                    htmlspecialchars($name, ENT_XML1),
                    htmlspecialchars($value, ENT_XML1),
                );
            }

            $email = htmlspecialchars((string) $contact['emailAddress'], ENT_XML1);
            if (!str_starts_with($email, 'mailto:')) {
                $email = 'mailto:' . $email;
            }

            $name = htmlspecialchars((string) ($contact['givenName'] ?? ''), ENT_XML1);
            $blocks[] = <<<XML
{$indent}<md:ContactPerson contactType="{$contact['contactType']}"{$attributes}>
{$indent}    <md:GivenName>{$name}</md:GivenName>
{$indent}    <md:EmailAddress>{$email}</md:EmailAddress>
{$indent}</md:ContactPerson>
XML;
        }

        return "\n" . implode("\n", $blocks);
    }

    private function localizedXmlLine(string $element, string $lang, string $value, string $indent): string
    {
        return sprintf(
            '%s<%s xml:lang="%s">%s</%s>',
            $indent,
            $element,
            htmlspecialchars($lang, ENT_XML1),
            htmlspecialchars($value, ENT_XML1),
            $element,
        );
    }
}
