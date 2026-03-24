<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\IdpUser;
use App\Entity\ServiceProvider;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\IdpUserRepository;
use App\Repository\TenantRepository;
use App\Repository\UserRepository;
use App\Service\MetadataService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'samlidp:legacy:import',
    description: 'Import tenants, tenant admins, tenant-local users, and SP metadata from the legacy eduID.africa database.',
)]
final class LegacyImportCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantRepository $tenantRepository,
        private readonly UserRepository $userRepository,
        private readonly IdpUserRepository $idpUserRepository,
        private readonly MetadataService $metadataService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('legacy-database-url', null, InputOption::VALUE_OPTIONAL, 'Legacy PostgreSQL DATABASE_URL to import from.', (string) ($_ENV['LEGACY_DATABASE_URL'] ?? getenv('LEGACY_DATABASE_URL') ?: ''))
            ->addOption('tenant', 't', InputOption::VALUE_OPTIONAL, 'Only import a single legacy tenant slug/hostname.')
            ->addOption('skip-sps', null, InputOption::VALUE_NONE, 'Skip importing legacy SP metadata.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Read and validate legacy data without writing changes.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('eduID.africa Legacy Import');

        $legacyDatabaseUrl = trim((string) $input->getOption('legacy-database-url'));
        if ($legacyDatabaseUrl === '') {
            $io->error('LEGACY_DATABASE_URL is required.');
            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $skipSps = (bool) $input->getOption('skip-sps');
        $tenantFilter = $this->normalizeTenantFilter($input->getOption('tenant'));

        try {
            $legacy = $this->connectLegacyPdo($legacyDatabaseUrl);
        } catch (\Throwable $e) {
            $io->error('Could not connect to the legacy database: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $legacyTenants = $this->fetchLegacyTenants($legacy, $tenantFilter);
        if ($legacyTenants === []) {
            $io->warning('No matching legacy tenants were found.');
            return Command::SUCCESS;
        }

        $legacyTenantIds = array_map(static fn (array $row): int => (int) $row['id'], $legacyTenants);

        $organizationElements = $this->fetchOrganizationElements($legacy, $legacyTenantIds);
        $domainMap = $this->fetchDomainsAndScopes($legacy, $legacyTenantIds);
        $federationMap = $this->fetchFederationMap($legacy);
        $tenantFederations = $this->fetchTenantFederations($legacy, $legacyTenantIds);
        $tenantAdminLinks = $this->fetchTenantAdminLinks($legacy, $legacyTenantIds);
        $legacyAdmins = $this->fetchLegacyAdmins($legacy, $tenantAdminLinks);
        $legacyLocalUsers = $this->fetchLegacyLocalUsers($legacy, $legacyTenantIds);
        $assignedAdminIds = [];
        foreach ($tenantAdminLinks as $legacyAdminIds) {
            foreach ($legacyAdminIds as $legacyAdminId) {
                $assignedAdminIds[$legacyAdminId] = true;
            }
        }

        $summary = [
            'tenants' => ['created' => 0, 'updated' => 0],
            'admins' => ['created' => 0, 'updated' => 0, 'assigned' => 0],
            'local_users' => ['created' => 0, 'updated' => 0],
            'sps' => ['created' => 0, 'updated' => 0, 'errors' => 0],
        ];

        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        try {
            $tenantByLegacyId = [];
            foreach ($legacyTenants as $row) {
            $legacyId = (int) $row['id'];
            $slug = strtolower((string) $row['hostname']);
            $tenant = $this->tenantRepository->findOneBy(['slug' => $slug]);
            $isNew = $tenant === null;

            if ($tenant === null) {
                $tenant = new Tenant();
                $tenant->setSlug($slug);
            }

            $org = $organizationElements[$legacyId] ?? ['name' => null, 'url' => null];
            $domains = $domainMap[$legacyId]['domains'] ?? [];
            $scopes = $domainMap[$legacyId]['scopes'] ?? [];
            $publishedFederations = [];
            $metadataAggregateUrls = [];
            foreach ($tenantFederations[$legacyId] ?? [] as $federationId) {
                $federation = $federationMap[$federationId] ?? null;
                if ($federation === null) {
                    continue;
                }

                if ($federation['slug'] !== null) {
                    $publishedFederations[] = $federation['slug'];
                }
                if ($federation['metadata_url'] !== null) {
                    $metadataAggregateUrls[] = $federation['metadata_url'];
                }
            }

            $name = $org['name'] ?? $this->humanizeSlug($slug);
            $orgUrl = $org['url'] ?? null;

            $tenant
                ->setName($name)
                ->setStatus($this->mapTenantStatus((string) $row['status']))
                ->setAuthType(Tenant::AUTH_DATABASE)
                ->setOrganizationName($name)
                ->setOrganizationUrl($orgUrl)
                ->setPublishedFederations($publishedFederations)
                ->setMetadataAggregateUrls(array_values(array_unique(array_filter($metadataAggregateUrls))))
                ->setAttributeReleasePolicy($this->defaultAttributePolicy())
                ->setMetadataProfile($this->buildMetadataProfile($name, $orgUrl, $domains, $scopes, $row['registrationinstant'] ?? null))
                ->setSigningCertificate($this->normalizeCertificateBody($row['cert_pem'] ?? null))
                ->setSigningPrivateKey($this->normalizePemText($row['cert_key'] ?? null))
                ->setMetadataLastRefreshed($this->toDateTimeImmutable($row['registrationinstant'] ?? null));

                $this->em->persist($tenant);
                $tenantByLegacyId[$legacyId] = $tenant;
                $summary['tenants'][$isNew ? 'created' : 'updated']++;
            }

            $this->em->flush();

            $adminUsersByLegacyId = [];
            foreach ($legacyAdmins as $legacyId => $row) {
            $email = strtolower(trim((string) $row['email']));
            if ($email === '') {
                continue;
            }

            $user = $this->userRepository->findOneBy(['email' => $email]);
            $isNew = $user === null;
            if ($user === null) {
                $user = new User();
                $user->setEmail($email);
            }

                $hasTenantAssignments = isset($assignedAdminIds[(int) $legacyId]);
            $roles = $this->mapLegacyAdminRoles((string) $row['roles'], $hasTenantAssignments);
            if ($roles === []) {
                $roles = ['ROLE_ADMIN'];
            }

            $user
                ->setFullName($this->buildFullName((string) $row['givenname'], (string) $row['sn'], (string) $row['username']))
                ->setRoles($roles)
                ->setIsActive((bool) $row['enabled'])
                ->setLastLoginAt($this->toDateTimeImmutable($row['last_login'] ?? null))
                ->setPassword((string) $row['password'])
                ->setLegacySalt($this->nullableString($row['salt'] ?? null))
                ->setTotpSecret($this->nullableString($row['google_authenticator_code'] ?? null))
                ->setTotpEnabled($this->nullableString($row['google_authenticator_code'] ?? null) !== null);

                $this->em->persist($user);
                $adminUsersByLegacyId[(int) $legacyId] = $user;
                $summary['admins'][$isNew ? 'created' : 'updated']++;
            }

            $this->em->flush();

            foreach ($legacyTenants as $row) {
            $legacyTenantId = (int) $row['id'];
            $tenant = $tenantByLegacyId[$legacyTenantId];
            $assignedAdminIds = $tenantAdminLinks[$legacyTenantId] ?? [];
            $firstAdmin = null;

            foreach ($assignedAdminIds as $legacyAdminId) {
                $user = $adminUsersByLegacyId[$legacyAdminId] ?? null;
                if (!$user instanceof User) {
                    continue;
                }

                if (!$tenant->getAdmins()->contains($user)) {
                    $tenant->addAdmin($user);
                    $summary['admins']['assigned']++;
                }

                if ($firstAdmin === null) {
                    $firstAdmin = $user;
                }
            }

            if ($firstAdmin instanceof User) {
                if ($tenant->getTechnicalContactEmail() === null) {
                    $tenant->setTechnicalContactEmail($firstAdmin->getEmail());
                }
                if ($tenant->getTechnicalContactName() === null) {
                    $tenant->setTechnicalContactName($firstAdmin->getFullName());
                }
            }
            }

            $this->em->flush();

            foreach ($legacyLocalUsers as $row) {
            $legacyTenantId = (int) $row['idp_id'];
            $tenant = $tenantByLegacyId[$legacyTenantId] ?? null;
            if (!$tenant instanceof Tenant) {
                continue;
            }

            $username = trim((string) $row['username']);
            if ($username === '') {
                continue;
            }

            $idpUser = $this->idpUserRepository->findByTenantAndUsername($tenant, $username);
            $isNew = $idpUser === null;
            if ($idpUser === null) {
                $idpUser = new IdpUser();
                $idpUser->setTenant($tenant);
                $idpUser->setUsername($username);
            }

            $homeOrganization = $this->deriveHomeOrganization($row);
            $displayName = $this->firstNonEmpty([
                $this->nullableString($row['display_name'] ?? null),
                trim($this->buildFullName((string) ($row['givenname'] ?? ''), (string) ($row['surname'] ?? ''), $username)),
            ]);
            $email = $this->nullableString($row['email'] ?? null);
            $attributes = $this->buildLocalUserAttributes(
                username: $username,
                email: $email,
                givenName: $this->nullableString($row['givenname'] ?? null),
                surname: $this->nullableString($row['surname'] ?? null),
                displayName: $displayName,
                affiliation: $this->nullableString($row['affiliation'] ?? null),
                homeOrganization: $homeOrganization,
            );

            $idpUser
                ->setPassword((string) $row['password'])
                ->setLegacySalt($this->nullableString($row['salt'] ?? null))
                ->setNtPasswordHash($this->nullableUpperString($row['password_ntml'] ?? null))
                ->setAttributes($attributes)
                ->setIsActive((bool) $row['enabled'] && !(bool) $row['deleted'])
                ->setLastLoginAt($this->toDateTimeImmutable($row['lastlogin'] ?? null));

                $this->em->persist($idpUser);
                $summary['local_users'][$isNew ? 'created' : 'updated']++;
            }

            $this->em->flush();

            if (!$skipSps) {
                $legacySps = $this->fetchLegacyServiceProviders($legacy, $legacyTenantIds);
                $batch = 0;

                foreach ($legacySps as $row) {
                $tenant = $tenantByLegacyId[(int) $row['tenant_id']] ?? null;
                if (!$tenant instanceof Tenant) {
                    continue;
                }

                $xml = $this->nullableString($row['metadata_xml'] ?? null);
                if ($xml === null) {
                    continue;
                }

                try {
                    $existing = $this->em->getRepository(ServiceProvider::class)->findOneBy([
                        'tenant' => $tenant,
                        'entityId' => (string) $row['entityid'],
                    ]);

                    $sp = $this->importLegacyServiceProvider($tenant, $row, $xml, $existing);

                    $sp->setApproved(true);
                    $sp->setSource($this->mapSpSource($row['federation_slug'] ?? null));
                    $sp->setMetadataRefreshedAt($this->toDateTimeImmutable($row['last_modified'] ?? null));

                    $summary['sps'][$existing instanceof ServiceProvider ? 'updated' : 'created']++;
                    $batch++;

                    if ($batch % 100 === 0) {
                        $this->em->flush();
                    }
                    } catch (\Throwable $e) {
                        $summary['sps']['errors']++;
                        $io->warning(sprintf(
                            'SP import failed for tenant %s / entity %s: %s',
                            $tenant->getSlug(),
                            (string) $row['entityid'],
                            $e->getMessage()
                        ));
                    }
                }

                $this->em->flush();
            }

            if ($dryRun) {
                $connection->rollBack();
                $this->em->clear();
                $io->note('Dry-run mode was requested; database changes were rolled back.');
                return Command::SUCCESS;
            }

            $this->metadataService->regenerateAllConfigs();
            $connection->commit();
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $e;
        }

        $io->section('Import Summary');
        $io->definitionList(
            ['Tenants created' => (string) $summary['tenants']['created']],
            ['Tenants updated' => (string) $summary['tenants']['updated']],
            ['Admin users created' => (string) $summary['admins']['created']],
            ['Admin users updated' => (string) $summary['admins']['updated']],
            ['Tenant admin assignments' => (string) $summary['admins']['assigned']],
            ['Tenant-local users created' => (string) $summary['local_users']['created']],
            ['Tenant-local users updated' => (string) $summary['local_users']['updated']],
            ['SPs created' => (string) $summary['sps']['created']],
            ['SPs updated' => (string) $summary['sps']['updated']],
            ['SP import errors' => (string) $summary['sps']['errors']],
        );

        $io->success('Legacy data import completed.');
        return Command::SUCCESS;
    }

    private function connectLegacyPdo(string $databaseUrl): \PDO
    {
        $parsed = parse_url($databaseUrl);
        if ($parsed === false || !isset($parsed['host'])) {
            throw new \RuntimeException('LEGACY_DATABASE_URL could not be parsed.');
        }

        $scheme = strtolower((string) ($parsed['scheme'] ?? 'pgsql'));
        $host = (string) $parsed['host'];
        $port = (int) ($parsed['port'] ?? ($scheme === 'mysql' ? 3306 : 5432));
        $database = ltrim((string) ($parsed['path'] ?? ''), '/');
        $user = urldecode((string) ($parsed['user'] ?? ''));
        $password = urldecode((string) ($parsed['pass'] ?? ''));

        $dsn = match ($scheme) {
            'pgsql', 'postgres', 'postgresql' => sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $database),
            'mysql', 'mariadb' => sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database),
            default => throw new \RuntimeException(sprintf('Unsupported legacy database scheme "%s".', $scheme)),
        };

        return new \PDO($dsn, $user, $password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchLegacyTenants(\PDO $legacy, ?string $tenantFilter): array
    {
        $sql = 'SELECT id, hostname, status, registrationinstant, logo, cert_key, cert_pem FROM idp';
        $params = [];

        if ($tenantFilter !== null) {
            $sql .= ' WHERE LOWER(hostname) = :tenant';
            $params['tenant'] = $tenantFilter;
        }

        $sql .= ' ORDER BY id';
        $stmt = $legacy->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @param int[] $tenantIds
     *  @return array<int, array{name:?string,url:?string}>
     */
    private function fetchOrganizationElements(\PDO $legacy, array $tenantIds): array
    {
        if ($tenantIds === []) {
            return [];
        }

        $stmt = $legacy->prepare(sprintf(
            'SELECT idp_id, type, value FROM organization_element WHERE idp_id IN (%s) ORDER BY idp_id, id',
            $this->placeholders($tenantIds),
        ));
        $stmt->execute($tenantIds);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $tenantId = (int) $row['idp_id'];
            $map[$tenantId] ??= ['name' => null, 'url' => null];

            if ($row['type'] === 'Name' && $map[$tenantId]['name'] === null) {
                $map[$tenantId]['name'] = $this->nullableString($row['value']);
            }

            if (str_starts_with((string) $row['type'], 'Information') && $map[$tenantId]['url'] === null) {
                $map[$tenantId]['url'] = $this->normalizeUrl($row['value']);
            }
        }

        return $map;
    }

    /** @param int[] $tenantIds
     *  @return array<int, array{domains: string[], scopes: string[]}>
     */
    private function fetchDomainsAndScopes(\PDO $legacy, array $tenantIds): array
    {
        if ($tenantIds === []) {
            return [];
        }

        $stmt = $legacy->prepare(sprintf(
            'SELECT i.id AS tenant_id, d.domain, s.value AS scope_value
             FROM idp i
             LEFT JOIN domain d ON d.idp_id = i.id
             LEFT JOIN scope s ON s.domain_id = d.id
             WHERE i.id IN (%s)
             ORDER BY i.id, d.id, s.id',
            $this->placeholders($tenantIds),
        ));
        $stmt->execute($tenantIds);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $tenantId = (int) $row['tenant_id'];
            $map[$tenantId] ??= ['domains' => [], 'scopes' => []];

            $domain = $this->nullableString($row['domain'] ?? null);
            if ($domain !== null) {
                $map[$tenantId]['domains'][] = strtolower($domain);
            }

            $scope = $this->legacyScopeToValue($row['scope_value'] ?? null, $domain);
            if ($scope !== null) {
                $map[$tenantId]['scopes'][] = strtolower($scope);
            }
        }

        foreach ($map as $tenantId => $data) {
            $map[$tenantId]['domains'] = array_values(array_unique($data['domains']));
            $map[$tenantId]['scopes'] = array_values(array_unique($data['scopes']));
        }

        return $map;
    }

    /** @return array<int, array{slug:?string,metadata_url:?string}> */
    private function fetchFederationMap(\PDO $legacy): array
    {
        $stmt = $legacy->query('SELECT id, slug, metadataurl FROM federation ORDER BY id');
        $map = [];

        foreach ($stmt->fetchAll() as $row) {
            $map[(int) $row['id']] = [
                'slug' => $this->nullableString($row['slug'] ?? null),
                'metadata_url' => $this->normalizeUrl($row['metadataurl'] ?? null),
            ];
        }

        return $map;
    }

    /** @param int[] $tenantIds
     *  @return array<int, int[]>
     */
    private function fetchTenantFederations(\PDO $legacy, array $tenantIds): array
    {
        if ($tenantIds === []) {
            return [];
        }

        $stmt = $legacy->prepare(sprintf(
            'SELECT id_p_id, federation_id FROM federation_id_p WHERE id_p_id IN (%s) ORDER BY id_p_id, federation_id',
            $this->placeholders($tenantIds),
        ));
        $stmt->execute($tenantIds);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int) $row['id_p_id']][] = (int) $row['federation_id'];
        }

        return $map;
    }

    /** @param int[] $tenantIds
     *  @return array<int, int[]>
     */
    private function fetchTenantAdminLinks(\PDO $legacy, array $tenantIds): array
    {
        if ($tenantIds === []) {
            return [];
        }

        $stmt = $legacy->prepare(sprintf(
            'SELECT id_p_id, user_id FROM id_p_user WHERE id_p_id IN (%s) ORDER BY id_p_id, user_id',
            $this->placeholders($tenantIds),
        ));
        $stmt->execute($tenantIds);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int) $row['id_p_id']][] = (int) $row['user_id'];
        }

        return $map;
    }

    /** @param array<int, int[]> $tenantAdminLinks
     *  @return array<int, array<string, mixed>>
     */
    private function fetchLegacyAdmins(\PDO $legacy, array $tenantAdminLinks): array
    {
        $adminIds = [];
        foreach ($tenantAdminLinks as $ids) {
            foreach ($ids as $id) {
                $adminIds[$id] = true;
            }
        }

        $sql = 'SELECT id, username, email, enabled, salt, password, roles, givenname, sn, google_authenticator_code, last_login
                FROM fos_user
                WHERE roles LIKE \'%ROLE_SUPER_ADMIN%\' OR roles LIKE \'%ROLE_ADMIN%\'';

        if ($adminIds !== []) {
            $sql .= sprintf(' OR id IN (%s)', $this->placeholders(array_keys($adminIds)));
        }

        $stmt = $legacy->prepare($sql);
        $stmt->execute(array_keys($adminIds));

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int) $row['id']] = $row;
        }

        return $result;
    }

    /** @param int[] $tenantIds
     *  @return array<int, array<string, mixed>>
     */
    private function fetchLegacyLocalUsers(\PDO $legacy, array $tenantIds): array
    {
        if ($tenantIds === []) {
            return [];
        }

        $stmt = $legacy->prepare(sprintf(
            'SELECT u.*, s.value AS scope_value, d.domain AS scope_domain
             FROM idp_internal_mysql_user u
             LEFT JOIN scope s ON s.id = u.scope_id
             LEFT JOIN domain d ON d.id = s.domain_id
             WHERE u.idp_id IN (%s)
             ORDER BY u.id',
            $this->placeholders($tenantIds),
        ));
        $stmt->execute($tenantIds);

        return $stmt->fetchAll();
    }

    private function importLegacyServiceProvider(Tenant $tenant, array $row, string $payload, ?ServiceProvider $existing): ServiceProvider
    {
        $trimmedPayload = ltrim($payload);
        if ($trimmedPayload !== '' && str_starts_with($trimmedPayload, '<')) {
            return $this->metadataService->importSpMetadata(
                $tenant,
                $payload,
                isUrl: false,
                approve: true,
                regenerate: false,
                flush: false,
            );
        }

        $metadata = @unserialize($payload, ['allowed_classes' => false]);
        if (!is_array($metadata)) {
            throw new \RuntimeException('Unsupported legacy SP metadata format.');
        }

        $sp = $existing ?? new ServiceProvider();
        $sp
            ->setTenant($tenant)
            ->setEntityId((string) ($metadata['entityid'] ?? $row['entityid']))
            ->setName($this->legacyLocalizedValue($metadata['name'] ?? null) ?? $this->legacyLocalizedValue($metadata['OrganizationDisplayName'] ?? null))
            ->setDescription($this->legacyLocalizedValue($metadata['description'] ?? null))
            ->setAcsUrl($this->legacyFirstEndpoint($metadata['AssertionConsumerService'] ?? null, [
                'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
                'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST-SimpleSign',
            ]))
            ->setSloUrl($this->legacyFirstEndpoint($metadata['SingleLogoutService'] ?? null, [
                'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
                'urn:oasis:names:tc:SAML:2.0:bindings:SOAP',
            ]))
            ->setCertificate($this->legacyFirstCertificate($metadata['keys'] ?? null))
            ->setContactEmail($this->legacyFirstContactEmail($metadata['contacts'] ?? null))
            ->setRequestedAttributes($this->legacyRequestedAttributes($metadata))
            ->setEncryptAssertions($this->legacyHasEncryptionKey($metadata['keys'] ?? null))
            ->setSignAssertions(true)
            ->setNameIdFormat($this->legacyNameIdFormat($metadata))
            ->setRawMetadataXml(null);

        $this->em->persist($sp);

        return $sp;
    }

    /** @param int[] $tenantIds
     *  @return array<int, array<string, mixed>>
     */
    private function fetchLegacyServiceProviders(\PDO $legacy, array $tenantIds): array
    {
        if ($tenantIds === []) {
            return [];
        }

        $stmt = $legacy->prepare(sprintf(
            'SELECT ie.id_p_id AS tenant_id,
                    e.entityid,
                    convert_from(e.entitydata, \'UTF8\') AS metadata_xml,
                    e.last_modified,
                    f.slug AS federation_slug
             FROM id_p_entity ie
             INNER JOIN entity e ON e.id = ie.entity_id
             LEFT JOIN federation f ON f.id = e.federation_id
             WHERE ie.id_p_id IN (%s)
             ORDER BY ie.id_p_id, e.id',
            $this->placeholders($tenantIds),
        ));
        $stmt->execute($tenantIds);

        return $stmt->fetchAll();
    }

    private function normalizeTenantFilter(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));
        return $value === '' ? null : $value;
    }

    private function legacyLocalizedValue(mixed $value): ?string
    {
        if (is_string($value)) {
            $value = trim($value);
            return $value !== '' ? $value : null;
        }

        if (!is_array($value)) {
            return null;
        }

        foreach (['en', 'en-US', 'en-GB'] as $preferredLocale) {
            $localized = $value[$preferredLocale] ?? null;
            if (is_string($localized) && trim($localized) !== '') {
                return trim($localized);
            }
        }

        foreach ($value as $localized) {
            if (is_string($localized) && trim($localized) !== '') {
                return trim($localized);
            }
        }

        return null;
    }

    /** @param mixed $endpoints */
    private function legacyFirstEndpoint(mixed $endpoints, array $preferredBindings = []): ?string
    {
        if (!is_array($endpoints) || $endpoints === []) {
            return null;
        }

        foreach ($preferredBindings as $binding) {
            foreach ($endpoints as $endpoint) {
                if (
                    is_array($endpoint)
                    && ($endpoint['Binding'] ?? null) === $binding
                    && is_string($endpoint['Location'] ?? null)
                    && trim((string) $endpoint['Location']) !== ''
                ) {
                    return trim((string) $endpoint['Location']);
                }
            }
        }

        foreach ($endpoints as $endpoint) {
            if (is_array($endpoint) && is_string($endpoint['Location'] ?? null) && trim((string) $endpoint['Location']) !== '') {
                return trim((string) $endpoint['Location']);
            }
        }

        return null;
    }

    /** @param mixed $keys */
    private function legacyFirstCertificate(mixed $keys): ?string
    {
        if (!is_array($keys)) {
            return null;
        }

        foreach ($keys as $key) {
            if (!is_array($key)) {
                continue;
            }

            $certificate = $this->nullableString($key['X509Certificate'] ?? null);
            if ($certificate !== null) {
                return $this->normalizeCertificateBody($certificate);
            }
        }

        return null;
    }

    /** @param mixed $keys */
    private function legacyHasEncryptionKey(mixed $keys): bool
    {
        if (!is_array($keys)) {
            return false;
        }

        foreach ($keys as $key) {
            if (is_array($key) && (bool) ($key['encryption'] ?? false)) {
                return true;
            }
        }

        return false;
    }

    /** @param mixed $contacts */
    private function legacyFirstContactEmail(mixed $contacts): ?string
    {
        if (!is_array($contacts)) {
            return null;
        }

        foreach ($contacts as $contact) {
            if (!is_array($contact)) {
                continue;
            }

            $emailAddresses = $contact['emailAddress'] ?? null;
            if (!is_array($emailAddresses)) {
                continue;
            }

            foreach ($emailAddresses as $emailAddress) {
                if (!is_string($emailAddress)) {
                    continue;
                }

                $emailAddress = preg_replace('/^mailto:/i', '', trim($emailAddress));
                if (is_string($emailAddress) && $emailAddress !== '') {
                    return $emailAddress;
                }
            }
        }

        return null;
    }

    private function legacyRequestedAttributes(array $metadata): array
    {
        $requested = [];

        foreach (['attributes.required', 'attributes'] as $key) {
            $values = $metadata[$key] ?? null;
            if (!is_array($values)) {
                continue;
            }

            foreach ($values as $value) {
                if (is_string($value) && trim($value) !== '') {
                    $requested[] = trim($value);
                }
            }
        }

        return array_values(array_unique($requested));
    }

    private function legacyNameIdFormat(array $metadata): string
    {
        $value = $metadata['NameIDFormat'] ?? null;

        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (is_string($item) && trim($item) !== '') {
                    return trim($item);
                }
            }
        }

        return 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent';
    }

    private function mapTenantStatus(string $legacyStatus): string
    {
        return strtolower(trim($legacyStatus)) === 'active'
            ? Tenant::STATUS_ACTIVE
            : Tenant::STATUS_PENDING;
    }

    private function defaultAttributePolicy(): array
    {
        return [
            'default' => [
                'uid',
                'mail',
                'displayName',
                'eduPersonPrincipalName',
                'eduPersonAffiliation',
                'eduPersonScopedAffiliation',
                'schacHomeOrganization',
            ],
        ];
    }

    private function buildMetadataProfile(string $name, ?string $organizationUrl, array $domains, array $scopes, mixed $registrationInstant): array
    {
        $profile = [
            'display_name' => $name,
            'description' => sprintf('Identity Provider for %s', $name),
            'domain_hints' => array_values(array_unique($domains)),
            'scopes' => array_values(array_unique($scopes)),
        ];

        $infoUrl = $organizationUrl !== null ? $this->normalizeUrl($organizationUrl) : null;
        if ($infoUrl !== null) {
            $profile['information_url'] = $infoUrl;
        }

        $registrationAt = $this->toDateTimeImmutable($registrationInstant);
        if ($registrationAt instanceof \DateTimeImmutable) {
            $profile['registration_instant'] = $registrationAt->format(DATE_ATOM);
        }

        return $profile;
    }

    private function mapLegacyAdminRoles(string $serializedRoles, bool $hasTenantAssignments): array
    {
        $legacyRoles = [];
        $unserialized = @unserialize($serializedRoles, ['allowed_classes' => false]);
        if (is_array($unserialized)) {
            foreach ($unserialized as $role) {
                if (is_string($role)) {
                    $legacyRoles[] = $role;
                }
            }
        }

        if (in_array('ROLE_SUPER_ADMIN', $legacyRoles, true)) {
            return ['ROLE_SUPER_ADMIN'];
        }

        if ($hasTenantAssignments || in_array('ROLE_ADMIN', $legacyRoles, true)) {
            return ['ROLE_ADMIN'];
        }

        return [];
    }

    private function buildFullName(string $givenName, string $surname, string $fallback): string
    {
        $fullName = trim(trim($givenName) . ' ' . trim($surname));
        return $fullName !== '' ? $fullName : $fallback;
    }

    private function buildLocalUserAttributes(
        string $username,
        ?string $email,
        ?string $givenName,
        ?string $surname,
        ?string $displayName,
        ?string $affiliation,
        ?string $homeOrganization,
    ): array {
        $attributes = [
            'uid' => [$username],
        ];

        if ($email !== null) {
            $attributes['mail'] = [$email];
        }
        if ($givenName !== null) {
            $attributes['givenName'] = [$givenName];
        }
        if ($surname !== null) {
            $attributes['sn'] = [$surname];
        }
        if ($displayName !== null) {
            $attributes['displayName'] = [$displayName];
            $attributes['cn'] = [$displayName];
        }
        if ($affiliation !== null) {
            $attributes['eduPersonAffiliation'] = [$affiliation];
        }
        if ($homeOrganization !== null) {
            $attributes['schacHomeOrganization'] = [$homeOrganization];
            if ($affiliation !== null) {
                $attributes['eduPersonScopedAffiliation'] = [sprintf('%s@%s', $affiliation, $homeOrganization)];
            }
        }

        $eppn = str_contains($username, '@')
            ? $username
            : ($homeOrganization !== null ? sprintf('%s@%s', $username, $homeOrganization) : null);
        if ($eppn !== null) {
            $attributes['eduPersonPrincipalName'] = [$eppn];
        }

        return $attributes;
    }

    private function deriveHomeOrganization(array $row): ?string
    {
        $domain = $this->nullableString($row['scope_domain'] ?? null);
        $scopeValue = $this->nullableString($row['scope_value'] ?? null);

        return $this->legacyScopeToValue($scopeValue, $domain) ?? $domain;
    }

    private function legacyScopeToValue(mixed $scopeValue, ?string $domain): ?string
    {
        $scope = $this->nullableString($scopeValue);
        $domain = $this->nullableString($domain);

        if ($scope === null) {
            return null;
        }

        if ($scope === '@') {
            return $domain;
        }

        if ($domain === null) {
            return $scope;
        }

        if (str_contains($scope, '.')) {
            return $scope;
        }

        return sprintf('%s.%s', $scope, $domain);
    }

    private function normalizeCertificateBody(mixed $certificate): ?string
    {
        $certificate = $this->normalizePemText($certificate);
        if ($certificate === null) {
            return null;
        }

        $certificate = preg_replace('/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s+/', '', $certificate);
        return is_string($certificate) && $certificate !== '' ? $certificate : null;
    }

    private function normalizePemText(mixed $value): ?string
    {
        $value = $this->nullableString($value);
        if ($value === null) {
            return null;
        }

        return str_replace(["\r\n", "\r"], "\n", $value);
    }

    private function normalizeUrl(mixed $value): ?string
    {
        $value = $this->nullableString($value);
        if ($value === null) {
            return null;
        }

        if (!preg_match('#^https?://#i', $value)) {
            $value = 'https://' . ltrim($value, '/');
        }

        return filter_var($value, FILTER_VALIDATE_URL) !== false ? $value : null;
    }

    private function toDateTimeImmutable(mixed $value): ?\DateTimeImmutable
    {
        $value = $this->nullableString($value);
        if ($value === null) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private function nullableUpperString(mixed $value): ?string
    {
        $value = $this->nullableString($value);
        return $value === null ? null : strtoupper($value);
    }

    private function firstNonEmpty(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function humanizeSlug(string $slug): string
    {
        $slug = preg_replace('/[^a-z0-9]+/i', ' ', $slug) ?? $slug;
        $slug = trim($slug);
        return $slug === '' ? 'Imported Institution' : ucwords($slug);
    }

    /** @param array<int, mixed> $values */
    private function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

    private function mapSpSource(mixed $legacyFederationSlug): string
    {
        $slug = strtolower(trim((string) $legacyFederationSlug));
        if ($slug === 'edugain') {
            return ServiceProvider::SOURCE_EDUGAIN;
        }

        return $slug !== '' ? ServiceProvider::SOURCE_METADATA : ServiceProvider::SOURCE_MANUAL;
    }
}
