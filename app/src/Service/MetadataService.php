<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ServiceProvider;
use App\Entity\Tenant;
use App\Repository\ServiceProviderRepository;
use App\Repository\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * MetadataService — the heart of the multitenant IdP.
 *
 * Responsibilities:
 *  1. Fetch and parse SAML metadata XML (SP metadata, federation aggregates)
 *  2. Store SPs in the database
 *  3. Generate SimpleSAMLphp config files (authsources, metadata, idp-hosted)
 *  4. Manage per-tenant signing keypairs
 */
class MetadataService
{
    // SAML 2.0 namespace
    private const NS_SAML_METADATA = 'urn:oasis:names:tc:SAML:2.0:metadata';
    private const NS_DS             = 'http://www.w3.org/2000/09/xmldsig#';
    private const NS_MDUI           = 'urn:oasis:names:tc:SAML:metadata:ui';
    private const FALLBACK_DNS_RESOLVERS = ['1.1.1.1', '8.8.8.8'];

    // Attribute OIDs commonly used in eduGAIN/REFEDS
    private const ATTRIBUTE_MAP = [
        'urn:oid:1.3.6.1.4.1.5923.1.1.1.7'  => 'eduPersonEntitlement',
        'urn:oid:1.3.6.1.4.1.5923.1.1.1.9'  => 'eduPersonScopedAffiliation',
        'urn:oid:1.3.6.1.4.1.5923.1.1.1.1'  => 'eduPersonAffiliation',
        'urn:oid:1.3.6.1.4.1.5923.1.1.1.6'  => 'eduPersonPrincipalName',
        'urn:oid:2.5.4.3'                     => 'cn',
        'urn:oid:2.16.840.1.113730.3.1.241'  => 'displayName',
        'urn:oid:0.9.2342.19200300.100.1.3'  => 'mail',
        'urn:oid:2.5.4.42'                    => 'givenName',
        'urn:oid:2.5.4.4'                     => 'sn',
        'urn:oid:2.5.4.10'                    => 'o',
        'urn:oid:1.3.6.1.4.1.25178.1.2.9'   => 'schacHomeOrganization',
        'urn:oid:1.3.6.1.4.1.25178.1.2.10'  => 'schacHomeOrganizationType',
    ];

    public function __construct(
        private readonly EntityManagerInterface     $em,
        private readonly ServiceProviderRepository  $spRepo,
        private readonly TenantRepository           $tenantRepo,
        private readonly HttpClientInterface        $httpClient,
        private readonly LoggerInterface            $logger,
        private readonly CacheInterface             $cache,
        private readonly SimpleSamlphpConfigWriter  $configWriter,
        private readonly string                     $samlidpHostname,
    ) {}

    // ─────────────────────────────────────────────────────────
    // PUBLIC API
    // ─────────────────────────────────────────────────────────

    /**
     * Import a single SP from a metadata URL or raw XML.
     * Returns the parsed/updated ServiceProvider entity.
     *
     * @throws \RuntimeException on parse errors
     */
    public function importSpMetadata(
        Tenant $tenant,
        string $metadataXmlOrUrl,
        bool   $isUrl = false,
        bool   $approve = false,
        bool   $regenerate = true,
        bool   $flush = true,
    ): ServiceProvider {
        if ($isUrl) {
            $xml = $this->fetchMetadataUrl($metadataXmlOrUrl);
        } else {
            $xml = $metadataXmlOrUrl;
        }

        $sp = $this->parseEntityDescriptor($xml);
        $sp->setTenant($tenant);
        $sp->setApproved($approve);
        $sp->setSource(ServiceProvider::SOURCE_MANUAL);
        $sp->setRawMetadataXml($xml);
        $sp->setMetadataRefreshedAt(new \DateTimeImmutable());

        // Check for duplicate
        $existing = $this->spRepo->findOneBy([
            'tenant'   => $tenant,
            'entityId' => $sp->getEntityId(),
        ]);
        if ($existing !== null) {
            $this->updateSpFromParsed($existing, $sp);
            if ($flush) {
                $this->em->flush();
            }
            if ($regenerate) {
                $this->regenerateConfigForTenant($tenant);
            }
            return $existing;
        }

        $this->em->persist($sp);
        if ($flush) {
            $this->em->flush();
        }
        if ($regenerate) {
            $this->regenerateConfigForTenant($tenant);
        }

        return $sp;
    }

    /**
     * Refresh all SPs for a tenant from their registered metadata aggregate URLs.
     */
    public function refreshTenantMetadata(Tenant $tenant): array
    {
        $results = ['imported' => 0, 'updated' => 0, 'errors' => []];
        $knownServiceProviders = [];

        foreach ($this->spRepo->findBy(['tenant' => $tenant]) as $existingSp) {
            $knownServiceProviders[$existingSp->getEntityId()] = $existingSp;
        }

        foreach ($tenant->getMetadataAggregateUrls() as $aggregateUrl) {
            try {
                $xml  = $this->fetchMetadataUrl($aggregateUrl);
                $data = $this->deduplicateParsedServiceProviders(
                    $this->parseEntitiesDescriptor($xml)
                );
                foreach ($data as $spData) {
                    try {
                        $entityId = $spData->getEntityId();
                        $existing = $knownServiceProviders[$entityId] ?? null;
                        if ($existing !== null) {
                            $this->updateSpFromParsed($existing, $spData);
                            $results['updated']++;
                        } else {
                            $spData->setTenant($tenant);
                            $spData->setSource(ServiceProvider::SOURCE_METADATA);
                            $spData->setApproved(true); // Auto-approve from federation
                            $this->em->persist($spData);
                            $knownServiceProviders[$entityId] = $spData;
                            $results['imported']++;
                        }
                    } catch (\Throwable $e) {
                        $results['errors'][] = $spData->getEntityId() . ': ' . $e->getMessage();
                        $this->logger->warning('Failed to import SP from aggregate', [
                            'entityId' => $spData->getEntityId(),
                            'error'    => $e->getMessage(),
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                $results['errors'][] = $aggregateUrl . ': ' . $e->getMessage();
                $this->logger->error('Failed to fetch metadata aggregate', [
                    'url'   => $aggregateUrl,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $tenant->setMetadataLastRefreshed(new \DateTimeImmutable());
        $this->em->flush();

        if ($results['imported'] > 0 || $results['updated'] > 0) {
            $this->regenerateConfigForTenant($tenant);
        }

        return $results;
    }

    /**
     * Refresh metadata for ALL active tenants.
     */
    public function refreshAllTenantsMetadata(): void
    {
        $tenants = $this->tenantRepo->findBy(['status' => Tenant::STATUS_ACTIVE]);
        foreach ($tenants as $tenant) {
            try {
                $this->refreshTenantMetadata($tenant);
            } catch (\Throwable $e) {
                $this->logger->error('Metadata refresh failed for tenant', [
                    'tenant' => $tenant->getSlug(),
                    'error'  => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Re-generate all SimpleSAMLphp config files for a tenant.
     * Called after any SP/tenant change.
     */
    public function regenerateConfigForTenant(Tenant $tenant): void
    {
        try {
            $this->configWriter->writeIdpHosted($tenant);
            $this->configWriter->writeSpRemoteMetadata($tenant);
            $this->configWriter->writeAuthsource($tenant);
            $this->logger->info('Regenerated SSP config', ['tenant' => $tenant->getSlug()]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to regenerate SSP config', [
                'tenant' => $tenant->getSlug(),
                'error'  => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Regenerate configs for ALL active tenants. Used on startup/deploy.
     */
    public function regenerateAllConfigs(): void
    {
        $tenants = $this->tenantRepo->findBy(['status' => Tenant::STATUS_ACTIVE]);
        foreach ($tenants as $tenant) {
            $this->regenerateConfigForTenant($tenant);
        }
    }

    /**
     * Generate a new RSA keypair for a tenant and store in DB.
     */
    public function generateTenantKeypair(Tenant $tenant): void
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 4096,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($key, $privateKey);
        $cert = $this->generateSelfSignedCert($key, $tenant);

        $tenant->setSigningPrivateKey($privateKey);
        $tenant->setSigningCertificate($cert);
        $this->em->flush();
    }

    /**
     * Validate metadata XML against SAML schema.
     *
     * @throws \RuntimeException with details of all validation errors
     */
    public function validateMetadataXml(string $xml): void
    {
        $doc = $this->loadXml($xml);

        // Use libxml for basic well-formedness; for production add XSD validation
        if (!$doc instanceof \DOMDocument) {
            throw new \RuntimeException('Invalid XML: document could not be parsed.');
        }

        $root = $doc->documentElement;
        $ns   = $root->namespaceURI;

        if (!in_array($ns, [self::NS_SAML_METADATA], true)) {
            throw new \RuntimeException(
                sprintf('Unexpected root namespace "%s". Expected SAML metadata namespace.', $ns)
            );
        }

        $localName = $root->localName;
        if (!in_array($localName, ['EntityDescriptor', 'EntitiesDescriptor'], true)) {
            throw new \RuntimeException(
                sprintf('Root element must be EntityDescriptor or EntitiesDescriptor, got "%s".', $localName)
            );
        }
    }

    // ─────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────

    private function fetchMetadataUrl(string $url): string
    {
        $lastTransportError = null;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                return $this->downloadMetadata($url);
            } catch (TransportExceptionInterface $e) {
                $lastTransportError = $e;
                $this->logger->warning('Transient metadata transport failure', [
                    'url' => $url,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);

                if ($attempt < 3) {
                    continue;
                }
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    sprintf('Failed to fetch metadata from "%s": %s', $url, $e->getMessage()),
                    0,
                    $e
                );
            }
        }

        $message = $lastTransportError?->getMessage() ?? 'Unknown transport error.';
        if ($lastTransportError !== null && $this->looksLikeDnsResolutionFailure($message)) {
            $resolvedIp = $this->resolveHostForUrl($url);
            if ($resolvedIp !== null) {
                try {
                    $this->logger->warning('Retrying metadata fetch with explicit host resolution override', [
                        'url' => $url,
                        'resolved_ip' => $resolvedIp,
                    ]);

                    return $this->downloadMetadata($url, [
                        'resolve' => [
                            (string) parse_url($url, \PHP_URL_HOST) => $resolvedIp,
                        ],
                    ]);
                } catch (\Throwable $fallbackError) {
                    $message = $fallbackError->getMessage();
                    $lastTransportError = $fallbackError instanceof \Throwable ? $fallbackError : $lastTransportError;
                }
            }
        }

        if ($this->looksLikeDnsResolutionFailure($message)) {
            $message .= ' This usually indicates outbound DNS resolution is misconfigured for the application container or host.';
        }

        throw new \RuntimeException(
            sprintf('Failed to fetch metadata from "%s": %s', $url, $message),
            0,
            $lastTransportError
        );
    }

    /**
     * @param array<string, mixed> $extraOptions
     */
    private function downloadMetadata(string $url, array $extraOptions = []): string
    {
        $response = $this->httpClient->request('GET', $url, array_replace_recursive([
            'timeout'         => 120,
            'max_redirects'   => 3,
            'headers'         => ['Accept' => 'application/samlmetadata+xml, application/xml, text/xml'],
            'verify_peer'     => true,
            'verify_host'     => true,
        ], $extraOptions));

        $content = $response->getContent();

        if (strlen($content) < 100) {
            throw new \RuntimeException('Metadata response too short (< 100 bytes).');
        }
        if (strlen($content) > 150 * 1024 * 1024) {
            throw new \RuntimeException('Metadata response too large (> 150 MB).');
        }

        return $content;
    }

    private function looksLikeDnsResolutionFailure(string $message): bool
    {
        $normalized = strtolower($message);

        foreach ([
            'could not resolve host',
            'temporary failure in name resolution',
            'timeout while contacting dns servers',
            'name or service not known',
            'nodename nor servname provided',
        ] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function resolveHostForUrl(string $url): ?string
    {
        $host = parse_url($url, \PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }

        if (filter_var($host, \FILTER_VALIDATE_IP) !== false) {
            return $host;
        }

        foreach (self::FALLBACK_DNS_RESOLVERS as $resolver) {
            $resolved = $this->queryDnsARecord($host, $resolver);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    private function queryDnsARecord(string $hostname, string $resolver): ?string
    {
        $transactionId = random_int(0, 0xffff);
        $packet = pack('nnnnnn', $transactionId, 0x0100, 1, 0, 0, 0)
            . $this->encodeDnsName($hostname)
            . pack('nn', 1, 1);

        $socket = @stream_socket_client(sprintf('udp://%s:53', $resolver), $errno, $errstr, 2.0);
        if (!is_resource($socket)) {
            return null;
        }

        stream_set_timeout($socket, 2);
        fwrite($socket, $packet);
        $response = fread($socket, 512);
        fclose($socket);

        if (!is_string($response) || strlen($response) < 12) {
            return null;
        }

        $header = unpack('ntransaction_id/nflags/nquestions/nanswers/nauthority/nadditional', substr($response, 0, 12));
        if (!is_array($header) || ($header['transaction_id'] ?? null) !== $transactionId || ($header['answers'] ?? 0) < 1) {
            return null;
        }

        $offset = 12;
        for ($i = 0; $i < (int) $header['questions']; $i++) {
            $offset = $this->skipDnsName($response, $offset);
            $offset += 4;
        }

        for ($i = 0; $i < (int) $header['answers']; $i++) {
            $offset = $this->skipDnsName($response, $offset);
            if ($offset + 10 > strlen($response)) {
                return null;
            }

            $record = unpack('ntype/nclass/Nttl/nlength', substr($response, $offset, 10));
            if (!is_array($record)) {
                return null;
            }

            $offset += 10;
            $length = (int) $record['length'];

            if ($offset + $length > strlen($response)) {
                return null;
            }

            if ((int) $record['type'] === 1 && (int) $record['class'] === 1 && $length === 4) {
                $ip = implode('.', array_map('ord', str_split(substr($response, $offset, 4))));
                if (filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4) !== false) {
                    return $ip;
                }
            }

            $offset += $length;
        }

        return null;
    }

    private function encodeDnsName(string $hostname): string
    {
        $labels = array_filter(explode('.', trim($hostname, '.')), static fn (string $label): bool => $label !== '');
        $encoded = '';

        foreach ($labels as $label) {
            $encoded .= chr(strlen($label)) . $label;
        }

        return $encoded . "\0";
    }

    private function skipDnsName(string $packet, int $offset): int
    {
        $length = strlen($packet);

        while ($offset < $length) {
            $len = ord($packet[$offset]);
            if (($len & 0xc0) === 0xc0) {
                return $offset + 2;
            }

            $offset++;
            if ($len === 0) {
                return $offset;
            }

            $offset += $len;
        }

        return $offset;
    }

    /**
     * Parse a single EntityDescriptor into a ServiceProvider entity (unsaved).
     */
    private function parseEntityDescriptor(string $xml): ServiceProvider
    {
        $doc = $this->loadXml($xml);
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('md',   self::NS_SAML_METADATA);
        $xpath->registerNamespace('ds',   self::NS_DS);
        $xpath->registerNamespace('mdui', self::NS_MDUI);

        $root = $doc->documentElement;

        // Support both EntityDescriptor and EntitiesDescriptor (pick first SP)
        if ($root->localName === 'EntitiesDescriptor') {
            $entities = $xpath->query('//md:EntityDescriptor');
            if ($entities->length === 0) {
                throw new \RuntimeException('No EntityDescriptor found in metadata.');
            }
            $root = $entities->item(0);
        }

        $entityId = $root->getAttribute('entityID');
        if (empty($entityId)) {
            throw new \RuntimeException('EntityDescriptor is missing entityID attribute.');
        }

        $sp = new ServiceProvider();
        $sp->setEntityId($entityId);

        // ── Name from MDUI ────────────────────────────────────
        $displayNameNodes = $xpath->query('.//mdui:UIInfo/mdui:DisplayName[@xml:lang="en"]', $root);
        if ($displayNameNodes->length > 0) {
            $sp->setName($displayNameNodes->item(0)->textContent);
        }

        // ── ACS URLs ──────────────────────────────────────────
        // Prefer HTTP-POST binding
        $acsPostNodes = $xpath->query(
            './/md:SPSSODescriptor/md:AssertionConsumerService[@Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST"][string-length(normalize-space(@Location)) > 0]',
            $root
        );
        if ($acsPostNodes->length === 0) {
            $acsPostNodes = $xpath->query(
                './/md:SPSSODescriptor/md:AssertionConsumerService[string-length(normalize-space(@Location)) > 0]',
                $root
            );
        }
        if ($acsPostNodes->length > 0) {
            $best = null;
            $bestScore = [1, PHP_INT_MAX];
            foreach ($acsPostNodes as $node) {
                $score = [
                    strtolower($node->getAttribute('isDefault')) === 'true' ? 0 : 1,
                    (int) ($node->getAttribute('index') ?: PHP_INT_MAX),
                ];

                if ($best === null || $score < $bestScore) {
                    $best = $node;
                    $bestScore = $score;
                }
            }

            if ($best instanceof \DOMElement) {
                $sp->setAcsUrl(trim($best->getAttribute('Location')));
            }
        }

        // ── SLO URLs ──────────────────────────────────────────
        $sloNodes = $xpath->query(
            './/md:SPSSODescriptor/md:SingleLogoutService[@Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect"]',
            $root
        );
        if ($sloNodes->length === 0) {
            $sloNodes = $xpath->query(
                './/md:SPSSODescriptor/md:SingleLogoutService[string-length(normalize-space(@Location)) > 0]',
                $root
            );
        }
        if ($sloNodes->length > 0) {
            $sp->setSloUrl(trim($sloNodes->item(0)->getAttribute('Location')));
        }

        // ── SP certificate (signing / encryption) ────────────
        $certNodes = $xpath->query(
            './/md:SPSSODescriptor/md:KeyDescriptor[@use="signing"]/ds:KeyInfo/ds:X509Data/ds:X509Certificate',
            $root
        );
        if ($certNodes->length === 0) {
            // Fallback: any KeyDescriptor without use attribute
            $certNodes = $xpath->query(
                './/md:SPSSODescriptor/md:KeyDescriptor[not(@use)]/ds:KeyInfo/ds:X509Data/ds:X509Certificate',
                $root
            );
        }
        if ($certNodes->length > 0) {
            $certPem = trim($certNodes->item(0)->textContent);
            $certPem = preg_replace('/\s+/', '', $certPem); // strip whitespace
            $sp->setCertificate($certPem);

            // Parse certificate expiry
            try {
                $pemFormatted  = "-----BEGIN CERTIFICATE-----\n";
                $pemFormatted .= chunk_split($certPem, 64, "\n");
                $pemFormatted .= "-----END CERTIFICATE-----\n";
                $certData      = openssl_x509_read($pemFormatted);
                if ($certData !== false) {
                    $certInfo = openssl_x509_parse($certData);
                    if (isset($certInfo['validTo_time_t'])) {
                        $sp->setCertificateExpiresAt(
                            (new \DateTimeImmutable())->setTimestamp($certInfo['validTo_time_t'])
                        );
                    }
                }
            } catch (\Throwable) {
                // Non-fatal; we just won't have the expiry date
            }
        }

        // ── NameID Format ─────────────────────────────────────
        $nameIdNodes = $xpath->query(
            './/md:SPSSODescriptor/md:NameIDFormat',
            $root
        );
        if ($nameIdNodes->length > 0) {
            $sp->setNameIdFormat(trim($nameIdNodes->item(0)->textContent));
        }

        // ── Technical contact ─────────────────────────────────
        $contactEmailNodes = $xpath->query(
            './/md:ContactPerson[@contactType="technical"]/md:EmailAddress',
            $root
        );
        if ($contactEmailNodes->length > 0) {
            $email = ltrim(trim($contactEmailNodes->item(0)->textContent), 'mailto:');
        $sp->setContactEmail($email);
        }

        // ── Requested attributes ──────────────────────────────
        $requestedAttributes = [];
        $requestedAttributeNodes = $xpath->query(
            './/md:SPSSODescriptor/md:AttributeConsumingService/md:RequestedAttribute',
            $root
        );
        foreach ($requestedAttributeNodes as $node) {
            $name = trim($node->getAttribute('FriendlyName'));
            if ($name === '') {
                $name = trim($node->getAttribute('Name'));
            }

            if ($name === '') {
                continue;
            }

            $requestedAttributes[] = self::ATTRIBUTE_MAP[$name] ?? $name;
        }
        if ($requestedAttributes !== []) {
            $sp->setRequestedAttributes($requestedAttributes);
        }

        return $sp;
    }

    /**
     * Parse an EntitiesDescriptor aggregate and return array of SP entities (unsaved).
     *
     * @return ServiceProvider[]
     */
    private function parseEntitiesDescriptor(string $xml): array
    {
        $doc = $this->loadXml($xml);
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('md', self::NS_SAML_METADATA);

        $entityNodes = $xpath->query('//md:EntityDescriptor[.//md:SPSSODescriptor]');
        $sps = [];

        foreach ($entityNodes as $node) {
            try {
                $nodeXml = $doc->saveXML($node);
                $sp = $this->parseEntityDescriptor($nodeXml);
                $sps[] = $sp;
            } catch (\Throwable $e) {
                $this->logger->warning('Skipping SP in aggregate due to parse error', [
                    'entityId' => $node->getAttribute('entityID'),
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        return $sps;
    }

    private function updateSpFromParsed(ServiceProvider $target, ServiceProvider $source): void
    {
        if ($source->getAcsUrl() !== null && trim($source->getAcsUrl()) !== '') {
            $target->setAcsUrl($source->getAcsUrl());
        }

        if ($source->getSloUrl() !== null && trim($source->getSloUrl()) !== '') {
            $target->setSloUrl($source->getSloUrl());
        }

        if ($source->getCertificate() !== null && trim($source->getCertificate()) !== '') {
            $target->setCertificate($source->getCertificate());
            $target->setCertificateExpiresAt($source->getCertificateExpiresAt());
        }

        if (trim($source->getNameIdFormat()) !== '') {
            $target->setNameIdFormat($source->getNameIdFormat());
        }

        if ($source->getContactEmail() !== null && trim($source->getContactEmail()) !== '') {
            $target->setContactEmail($source->getContactEmail());
        }

        if ($source->getName() !== null) {
            $target->setName($source->getName());
        }
        $target->setMetadataRefreshedAt(new \DateTimeImmutable());
        if ($source->getRawMetadataXml() !== null) {
            $target->setRawMetadataXml($source->getRawMetadataXml());
        }
        $target->setRequestedAttributes($source->getRequestedAttributes());
    }

    /**
     * @param ServiceProvider[] $serviceProviders
     *
     * @return ServiceProvider[]
     */
    private function deduplicateParsedServiceProviders(array $serviceProviders): array
    {
        $selected = [];

        foreach ($serviceProviders as $serviceProvider) {
            $entityId = trim($serviceProvider->getEntityId());
            if ($entityId === '') {
                continue;
            }

            $current = $selected[$entityId] ?? null;
            if ($current === null || $this->scoreParsedServiceProvider($serviceProvider) > $this->scoreParsedServiceProvider($current)) {
                $selected[$entityId] = $serviceProvider;
            }
        }

        return array_values($selected);
    }

    private function scoreParsedServiceProvider(ServiceProvider $serviceProvider): int
    {
        $score = 0;

        if (($acsUrl = $serviceProvider->getAcsUrl()) !== null && trim($acsUrl) !== '') {
            $score += 100;
        }

        if (($sloUrl = $serviceProvider->getSloUrl()) !== null && trim($sloUrl) !== '') {
            $score += 20;
        }

        if (($certificate = $serviceProvider->getCertificate()) !== null && trim($certificate) !== '') {
            $score += 10;
        }

        if (($name = $serviceProvider->getName()) !== null && trim($name) !== '') {
            $score += 5;
        }

        if (($contactEmail = $serviceProvider->getContactEmail()) !== null && trim($contactEmail) !== '') {
            $score += 2;
        }

        return $score;
    }

    private function loadXml(string $xml): \DOMDocument
    {
        $doc = new \DOMDocument();
        $doc->preserveWhiteSpace = false;
        \libxml_use_internal_errors(true);

        // Security: disable external entity loading
        $options = \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOWARNING;
        if (!$doc->loadXML($xml, $options)) {
            $errors = array_map(
                fn(\LibXMLError $e) => trim($e->message),
                \libxml_get_errors()
            );
            \libxml_clear_errors();
            throw new \RuntimeException(
                'XML parse error: ' . implode('; ', $errors)
            );
        }
        \libxml_clear_errors();

        return $doc;
    }

    private function generateSelfSignedCert(\OpenSSLAsymmetricKey $key, Tenant $tenant): string
    {
        $dn = [
            'commonName'            => $tenant->getTenantHostname(),
            'organizationName'      => $tenant->getOrganizationName() ?? 'Managed Identity Platform',
        ];

        $csr  = openssl_csr_new($dn, $key);
        $cert = openssl_csr_sign($csr, null, $key, 3652); // 10 years

        openssl_x509_export($cert, $certPem);

        // Strip PEM headers for SSP storage
        return trim(preg_replace('/-----[^-]+-----/', '', $certPem));
    }
}
