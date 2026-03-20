<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Tenant;
use App\Entity\ServiceProvider;
use App\Repository\TenantRepository;
use App\Repository\ServiceProviderRepository;
use App\Service\EduroamConfigBuilder;
use App\Service\MetadataService;
use App\Service\TenantProvisioner;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/admin/tenants', name: 'admin_tenant_')]
#[IsGranted('ROLE_ADMIN')]
class TenantController extends AbstractController
{
    public function __construct(
        private readonly TenantRepository          $tenantRepo,
        private readonly ServiceProviderRepository $spRepo,
        private readonly EntityManagerInterface    $em,
        private readonly MetadataService           $metadataService,
        private readonly EduroamConfigBuilder      $eduroamConfigBuilder,
        private readonly TenantProvisioner         $provisioner,
        private readonly PaginatorInterface        $paginator,
        private readonly ValidatorInterface        $validator,
        private readonly LoggerInterface           $logger,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $search = '';
        $status = '';
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            throw $this->createAccessDeniedException('Authenticated user required.');
        }

        $qb = $this->tenantRepo->createQueryBuilder('t')
            ->orderBy('t.createdAt', 'DESC');

        if (!$user->isSuperAdmin()) {
            $qb->innerJoin('t.admins', 'a')
                ->andWhere('a = :user')
                ->setParameter('user', $user);
        }

        // Search
        if ($search = $request->query->getString('q')) {
            $qb->andWhere('t.name LIKE :q OR t.slug LIKE :q')
               ->setParameter('q', '%' . $search . '%');
        }

        // Status filter
        if ($status = $request->query->getString('status')) {
            $qb->andWhere('t.status = :status')->setParameter('status', $status);
        }

        $pagination = $this->paginator->paginate(
            $qb,
            $request->query->getInt('page', 1),
            25
        );

        return $this->render('admin/tenant/index.html.twig', [
            'pagination' => $pagination,
            'search'     => $search,
            'status'     => $status,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function new(Request $request): Response
    {
        $tenant = new Tenant();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('tenant_new', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            try {
                $this->fillTenantFromRequest($tenant, $request);
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('danger', $e->getMessage());
                return $this->render('admin/tenant/new.html.twig', [
                    'tenant' => $tenant,
                ]);
            }
            $errors = $this->validator->validate($tenant);

            if (count($errors) === 0) {
                try {
                    $this->em->persist($tenant);
                    $this->em->flush();

                    // Generate keypair and provision SSP config
                    $this->provisioner->provision($tenant);

                    $this->addFlash('success', sprintf(
                        'Tenant "%s" created. IdP URL: %s',
                        $tenant->getName(),
                        $tenant->getIdpUrl()
                    ));
                    return $this->redirectToRoute('admin_tenant_show', ['id' => $tenant->getId()]);
                } catch (\Throwable $e) {
                    $this->logger->error('Tenant creation failed', ['error' => $e->getMessage()]);
                    $this->addFlash('danger', 'Creation failed: ' . $e->getMessage());
                }
            } else {
                foreach ($errors as $error) {
                    $this->addFlash('danger', $error->getPropertyPath() . ': ' . $error->getMessage());
                }
            }
        }

        return $this->render('admin/tenant/new.html.twig', [
            'tenant' => $tenant,
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(Tenant $tenant, Request $request): Response
    {
        $this->denyAccessUnlessGranted('TENANT_VIEW', $tenant);

        $spPagination = $this->paginator->paginate(
            $this->spRepo->createQueryBuilder('sp')
                ->where('sp.tenant = :t')->setParameter('t', $tenant)
                ->orderBy('sp.name', 'ASC'),
            $request->query->getInt('page', 1),
            20
        );

        return $this->render('admin/tenant/show.html.twig', [
            'tenant'       => $tenant,
            'eduroam'      => $this->eduroamConfigBuilder->build($tenant),
            'spPagination' => $spPagination,
        ]);
    }

    #[Route('/{id}/eduroam', name: 'eduroam', methods: ['GET'])]
    public function eduroam(Tenant $tenant): Response
    {
        $this->denyAccessUnlessGranted('TENANT_VIEW', $tenant);

        return $this->render('admin/tenant/eduroam.html.twig', [
            'tenant' => $tenant,
            'bundle' => $this->eduroamConfigBuilder->build($tenant),
        ]);
    }

    #[Route('/{id}/eduroam/{fileKey}', name: 'eduroam_file', methods: ['GET'])]
    public function eduroamFile(Tenant $tenant, string $fileKey): Response
    {
        $this->denyAccessUnlessGranted('TENANT_VIEW', $tenant);

        $bundle = $this->eduroamConfigBuilder->build($tenant);
        $file = $bundle['files'][$fileKey] ?? null;
        if (!is_array($file)) {
            throw $this->createNotFoundException('eduroam config file not found.');
        }

        $response = new Response((string) ($file['content'] ?? ''));
        $response->headers->set('Content-Type', 'text/plain; charset=UTF-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $file['filename'] ?? ($fileKey . '.conf')));

        return $response;
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Tenant $tenant, Request $request): Response
    {
        $this->denyAccessUnlessGranted('TENANT_EDIT', $tenant);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('tenant_edit_' . $tenant->getId(), $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            try {
                $this->fillTenantFromRequest($tenant, $request);
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('danger', $e->getMessage());
                return $this->render('admin/tenant/edit.html.twig', [
                    'tenant' => $tenant,
                ]);
            }
            $errors = $this->validator->validate($tenant);

            if (count($errors) === 0) {
                try {
                    $this->em->flush();
                    $this->metadataService->regenerateConfigForTenant($tenant);
                    $this->addFlash('success', 'Tenant updated and config regenerated.');
                    return $this->redirectToRoute('admin_tenant_show', ['id' => $tenant->getId()]);
                } catch (\Throwable $e) {
                    $this->addFlash('danger', 'Update failed: ' . $e->getMessage());
                }
            } else {
                foreach ($errors as $error) {
                    $this->addFlash('danger', $error->getPropertyPath() . ': ' . $error->getMessage());
                }
            }
        }

        return $this->render('admin/tenant/edit.html.twig', [
            'tenant' => $tenant,
        ]);
    }

    // ── Metadata import ───────────────────────────────────────

    #[Route('/{id}/import-sp', name: 'import_sp', methods: ['POST'])]
    public function importSp(Tenant $tenant, Request $request): Response
    {
        $this->denyAccessUnlessGranted('TENANT_EDIT', $tenant);
        if (!$this->isCsrfTokenValid('import-sp-' . $tenant->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $metadataUrl = trim($request->request->getString('metadata_url'));
        $metadataXml = trim($request->request->getString('metadata_xml'));
        $approve     = $request->request->getBoolean('approve', false);

        try {
            if (!empty($metadataUrl)) {
                $sp = $this->metadataService->importSpMetadata($tenant, $metadataUrl, isUrl: true, approve: $approve);
            } elseif (!empty($metadataXml)) {
                // Validate before import
                $this->metadataService->validateMetadataXml($metadataXml);
                $sp = $this->metadataService->importSpMetadata($tenant, $metadataXml, isUrl: false, approve: $approve);
            } else {
                throw new \InvalidArgumentException('Please provide either a metadata URL or paste XML.');
            }

            $this->addFlash('success', sprintf(
                'SP "%s" imported successfully.',
                $sp->getDisplayName()
            ));
            if ($sp->getRequestedAttributes() !== null && $sp->getRequestedAttributes() !== []) {
                $this->addFlash('info', 'Review the requested attributes and choose what this SP may receive.');
            }
            return $this->redirectToRoute('admin_sp_edit', ['id' => $sp->getId()]);
        } catch (\Throwable $e) {
            $this->logger->warning('SP import failed', [
                'tenant' => $tenant->getSlug(),
                'error'  => $e->getMessage(),
            ]);
            $this->addFlash('danger', 'Import failed: ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_tenant_show', ['id' => $tenant->getId()]);
    }

    #[Route('/{id}/refresh-metadata', name: 'refresh_metadata', methods: ['POST'])]
    public function refreshMetadata(Tenant $tenant, Request $request): Response
    {
        $this->denyAccessUnlessGranted('TENANT_EDIT', $tenant);
        if (!$this->isCsrfTokenValid('refresh-meta-' . $tenant->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            $results = $this->metadataService->refreshTenantMetadata($tenant);
            $this->addFlash('success', sprintf(
                'Metadata refreshed: %d imported, %d updated. Errors: %d',
                $results['imported'],
                $results['updated'],
                count($results['errors'])
            ));
            if (!empty($results['errors'])) {
                foreach (array_slice($results['errors'], 0, 5) as $err) {
                    $this->addFlash('warning', $err);
                }
            }
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Metadata refresh failed: ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_tenant_show', ['id' => $tenant->getId()]);
    }

    #[Route('/{id}/regenerate-config', name: 'regenerate_config', methods: ['POST'])]
    public function regenerateConfig(Tenant $tenant, Request $request): Response
    {
        $this->denyAccessUnlessGranted('TENANT_EDIT', $tenant);
        if (!$this->isCsrfTokenValid('regen-' . $tenant->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            $this->metadataService->regenerateConfigForTenant($tenant);
            $this->addFlash('success', 'SimpleSAMLphp config regenerated.');
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Config regeneration failed: ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_tenant_show', ['id' => $tenant->getId()]);
    }

    #[Route('/{id}/regenerate-keypair', name: 'regenerate_keypair', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function regenerateKeypair(Tenant $tenant, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('regen-key-' . $tenant->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            $this->metadataService->generateTenantKeypair($tenant);
            $this->metadataService->regenerateConfigForTenant($tenant);
            $this->addFlash('success', 'New keypair generated and configs updated. Download/re-publish IdP metadata!');
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Keypair generation failed: ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_tenant_show', ['id' => $tenant->getId()]);
    }

    // ── Status management ─────────────────────────────────────

    #[Route('/{id}/activate', name: 'activate', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function activate(Tenant $tenant, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('activate-' . $tenant->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $tenant->setStatus(Tenant::STATUS_ACTIVE);
        $this->em->flush();
        $this->metadataService->regenerateConfigForTenant($tenant);
        $this->addFlash('success', sprintf('"%s" is now active.', $tenant->getName()));
        return $this->redirectToRoute('admin_tenant_show', ['id' => $tenant->getId()]);
    }

    #[Route('/{id}/suspend', name: 'suspend', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function suspend(Tenant $tenant, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('suspend-' . $tenant->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $tenant->setStatus(Tenant::STATUS_SUSPENDED);
        $this->em->flush();
        $this->addFlash('warning', sprintf('"%s" has been suspended.', $tenant->getName()));
        return $this->redirectToRoute('admin_tenant_show', ['id' => $tenant->getId()]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function delete(Tenant $tenant, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete-' . $tenant->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $name = $tenant->getName();

        $this->em->remove($tenant);
        $this->em->flush();
        $this->metadataService->regenerateAllConfigs();

        $this->addFlash('success', sprintf('Tenant "%s" deleted.', $name));

        return $this->redirectToRoute('admin_tenant_index');
    }

    // ── API: validate metadata XML (AJAX) ─────────────────────

    #[Route('/api/validate-metadata', name: 'api_validate_metadata', methods: ['POST'])]
    public function apiValidateMetadata(Request $request): JsonResponse
    {
        $xml = $request->request->getString('xml');
        if (empty($xml)) {
            return $this->json(['valid' => false, 'error' => 'No XML provided.'], 400);
        }

        try {
            $this->metadataService->validateMetadataXml($xml);
            return $this->json(['valid' => true]);
        } catch (\RuntimeException $e) {
            return $this->json(['valid' => false, 'error' => $e->getMessage()], 422);
        }
    }

    // ─────────────────────────────────────────────────────────

    private function fillTenantFromRequest(Tenant $tenant, Request $request): void
    {
        $data = $request->request->all('tenant');

        if (isset($data['slug'])) {
            $tenant->setSlug($data['slug']);
        }
        if (isset($data['name'])) {
            $tenant->setName($data['name']);
        }
        if (isset($data['entityId'])) {
            $tenant->setEntityId($data['entityId']);
        }
        if (isset($data['status'])) {
            $tenant->setStatus($data['status']);
        }
        if (isset($data['authType'])) {
            $tenant->setAuthType($data['authType']);
        }
        if (isset($data['technicalContactEmail'])) {
            $tenant->setTechnicalContactEmail($data['technicalContactEmail'] ?: null);
        }
        if (isset($data['technicalContactName'])) {
            $tenant->setTechnicalContactName($data['technicalContactName'] ?: null);
        }
        if (isset($data['organizationName'])) {
            $tenant->setOrganizationName($data['organizationName'] ?: null);
        }
        if (isset($data['organizationUrl'])) {
            $tenant->setOrganizationUrl($data['organizationUrl'] ?: null);
        }
        $removeLogo = !empty($data['removeLogo']);
        $rawLogoUrl = trim((string) ($data['logoUrl'] ?? ''));

        if ($removeLogo) {
            $this->removeManagedLogo($tenant->getLogoUrl());
            $tenant->setLogoUrl(null);
        }
        if (!$removeLogo && $rawLogoUrl !== '') {
            $this->removeManagedLogo($tenant->getLogoUrl());
            $tenant->setLogoUrl($rawLogoUrl);
        }

        $files = $request->files->all('tenant');
        $logoFile = $files['logoFile'] ?? null;
        if ($logoFile instanceof UploadedFile && $logoFile->isValid()) {
            $tenant->setLogoUrl($this->storeTenantLogo($tenant, $logoFile));
        }
        if (isset($data['metadataProfile']) && is_array($data['metadataProfile'])) {
            $profileInput = $data['metadataProfile'];
            $normalized = [];

            foreach ([
                'display_name',
                'description',
                'information_url',
                'privacy_statement_url',
                'support_contact_name',
                'support_contact_email',
                'security_contact_name',
                'security_contact_email',
                'registration_authority',
                'registration_policy_url',
                'registration_instant',
            ] as $key) {
                if (!isset($profileInput[$key])) {
                    continue;
                }

                $value = trim((string) $profileInput[$key]);
                if ($value !== '') {
                    $normalized[$key] = $value;
                }
            }

            foreach ([
                'keywords',
                'domain_hints',
                'geolocation_hints',
                'scopes',
            ] as $key) {
                if (!isset($profileInput[$key])) {
                    continue;
                }

                $items = array_values(array_filter(
                    array_map(
                        static fn (string $item): string => trim($item),
                        preg_split('/[\r\n,]+/', (string) $profileInput[$key]) ?: []
                    ),
                    static fn (string $item): bool => $item !== ''
                ));

                if ($items !== []) {
                    $normalized[$key] = array_values(array_unique($items));
                }
            }

            foreach (['logo_width', 'logo_height'] as $key) {
                if (!isset($profileInput[$key]) || $profileInput[$key] === '') {
                    continue;
                }

                $value = (int) $profileInput[$key];
                if ($value > 0) {
                    $normalized[$key] = $value;
                }
            }

            $tenant->setMetadataProfile($normalized);
        }
        if (isset($data['eduroamProfile']) && is_array($data['eduroamProfile'])) {
            $profileInput = $data['eduroamProfile'];
            $normalized = [];
            $normalized['enabled'] = !empty($profileInput['enabled']);

            foreach (['realm', 'local_radius_hostname', 'default_eap_method'] as $key) {
                if (!isset($profileInput[$key])) {
                    continue;
                }

                $value = trim((string) $profileInput[$key]);
                if ($value !== '') {
                    $normalized[$key] = $value;
                }
            }

            $normalized['anonymous_outer_identity'] = !empty($profileInput['anonymous_outer_identity']);

            if (isset($profileInput['national_proxy_servers'])) {
                $items = array_values(array_filter(
                    array_map(
                        static fn (string $item): string => trim($item),
                        preg_split('/[\r\n,]+/', (string) $profileInput['national_proxy_servers']) ?: []
                    ),
                    static fn (string $item): bool => $item !== ''
                ));

                if ($items !== []) {
                    $normalized['national_proxy_servers'] = array_values(array_unique($items));
                }
            }

            $tenant->setEduroamProfile($normalized);
        }
        if (isset($data['mfaPolicy'])) {
            $tenant->setMfaPolicy($data['mfaPolicy']);
        }
        if (isset($data['adminNotes'])) {
            $tenant->setAdminNotes($data['adminNotes'] ?: null);
        }

        // JSON config fields
        if (isset($data['ldapConfig'])) {
            $tenant->setLdapConfig($this->decodeConfigJson((string) $data['ldapConfig'], 'LDAP configuration'));
        }

        if (isset($data['samlUpstreamConfig'])) {
            $tenant->setSamlUpstreamConfig($this->decodeConfigJson((string) $data['samlUpstreamConfig'], 'SAML proxy configuration'));
        }

        if (isset($data['radiusConfig'])) {
            $tenant->setRadiusConfig($this->decodeConfigJson((string) $data['radiusConfig'], 'RADIUS configuration'));
        }

        if (isset($data['attributeReleasePolicy'])) {
            $decoded = json_decode((string) $data['attributeReleasePolicy'], true);
            if (is_array($decoded)) {
                $tenant->setAttributeReleasePolicy($decoded);
            }
        }

        // Metadata aggregate URLs
        if (isset($data['metadataAggregateUrls'])) {
            $urls = array_filter(
                array_map('trim', explode("\n", $data['metadataAggregateUrls'])),
                fn($u) => !empty($u) && filter_var($u, FILTER_VALIDATE_URL)
            );
            $tenant->setMetadataAggregateUrls(array_values($urls));
        }

        if (isset($data['publishedFederations'])) {
            $tenant->setPublishedFederations(
                array_filter(array_map('trim', explode("\n", $data['publishedFederations'])))
            );
        }

        $this->applyDerivedDefaults($tenant);
    }

    private function applyDerivedDefaults(Tenant $tenant): void
    {
        if ($tenant->getOrganizationName() === null || trim($tenant->getOrganizationName()) === '') {
            $tenant->setOrganizationName($tenant->getName());
        }

        $profile = $tenant->getMetadataProfile();
        $orgName = trim((string) ($tenant->getOrganizationName() ?? $tenant->getName()));
        $orgUrl = trim((string) ($tenant->getOrganizationUrl() ?? ''));
        $contactName = trim((string) ($tenant->getTechnicalContactName() ?? ''));
        $contactEmail = strtolower(trim((string) ($tenant->getTechnicalContactEmail() ?? '')));
        $primaryDomain = $this->derivePrimaryDomain($tenant, $profile);

        $profile['display_name'] ??= $orgName !== '' ? $orgName . ' Identity Provider' : null;
        $profile['description'] ??= $orgName !== '' ? sprintf('Federated identity provider for %s.', $orgName) : null;
        $profile['registration_authority'] ??= 'https://idp.ubuntunet.net';
        $profile['registration_policy_url'] ??= 'https://idp.ubuntunet.net/federation/metadata-registration-practice-statement';

        if (($profile['information_url'] ?? '') === '' && $orgUrl !== '') {
            $profile['information_url'] = $orgUrl;
        }
        if (($profile['privacy_statement_url'] ?? '') === '' && $orgUrl !== '') {
            $profile['privacy_statement_url'] = rtrim($orgUrl, '/') . '/privacy';
        }
        if (($profile['support_contact_name'] ?? '') === '' && $contactName !== '') {
            $profile['support_contact_name'] = $contactName;
        }
        if (($profile['support_contact_email'] ?? '') === '' && $contactEmail !== '') {
            $profile['support_contact_email'] = $contactEmail;
        }
        if (($profile['security_contact_name'] ?? '') === '' && $contactName !== '') {
            $profile['security_contact_name'] = $contactName;
        }
        if (($profile['security_contact_email'] ?? '') === '' && $contactEmail !== '') {
            $profile['security_contact_email'] = $contactEmail;
        }
        if (empty($profile['domain_hints']) && $primaryDomain !== null) {
            $profile['domain_hints'] = [$primaryDomain];
        }
        if (empty($profile['scopes']) && $primaryDomain !== null) {
            $profile['scopes'] = [$primaryDomain];
        }
        if (empty($profile['logo_width'])) {
            $profile['logo_width'] = 200;
        }
        if (empty($profile['logo_height'])) {
            $profile['logo_height'] = 80;
        }

        $tenant->setMetadataProfile(array_filter(
            $profile,
            static fn (mixed $value): bool => !($value === null || $value === '' || $value === [])
        ));

        $eduroamProfile = $tenant->getEduroamProfile();
        if (($eduroamProfile['enabled'] ?? false) && $primaryDomain !== null) {
            $eduroamProfile['realm'] ??= $primaryDomain;
            $eduroamProfile['local_radius_hostname'] ??= 'radius.' . $primaryDomain;
            $tenant->setEduroamProfile($eduroamProfile);
        }
    }

    private function derivePrimaryDomain(Tenant $tenant, array $profile = []): ?string
    {
        $organizationUrl = trim((string) ($tenant->getOrganizationUrl() ?? ''));
        if ($organizationUrl !== '') {
            $host = parse_url($organizationUrl, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                return strtolower($host);
            }
        }

        $contactEmail = trim((string) ($tenant->getTechnicalContactEmail() ?? ''));
        if ($contactEmail !== '' && str_contains($contactEmail, '@')) {
            $parts = explode('@', strtolower($contactEmail));
            $domain = end($parts);
            if (is_string($domain) && $domain !== '') {
                return $domain;
            }
        }

        foreach (['domain_hints', 'scopes'] as $key) {
            $values = $profile[$key] ?? null;
            if (!is_array($values)) {
                continue;
            }

            foreach ($values as $value) {
                $candidate = strtolower(trim((string) $value));
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function storeTenantLogo(Tenant $tenant, UploadedFile $file): string
    {
        $allowedMimeTypes = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
        ];

        $mimeType = $file->getMimeType() ?? '';
        $extension = $allowedMimeTypes[$mimeType] ?? null;
        if ($extension === null) {
            throw new \RuntimeException('Unsupported logo format. Use PNG, JPG, WEBP, or SVG.');
        }

        $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/tenant-logos';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            throw new \RuntimeException('Failed to create tenant logo upload directory.');
        }

        $prefix = $tenant->getSlug() !== '' ? $tenant->getSlug() : 'tenant-logo';
        $filename = sprintf('%s-%s.%s', $prefix, bin2hex(random_bytes(8)), $extension);
        $file->move($uploadDir, $filename);

        $this->removeManagedLogo($tenant->getLogoUrl());

        return '/uploads/tenant-logos/' . $filename;
    }

    private function removeManagedLogo(?string $logoUrl): void
    {
        if (!is_string($logoUrl) || !str_starts_with($logoUrl, '/uploads/tenant-logos/')) {
            return;
        }

        $path = $this->getParameter('kernel.project_dir') . '/public' . $logoUrl;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function decodeConfigJson(string $json, string $label): ?array
    {
        $trimmed = trim($json);
        if ($trimmed === '') {
            return null;
        }

        try {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException(sprintf('%s must be valid JSON: %s', $label, $e->getMessage()));
        }

        if (!is_array($decoded)) {
            throw new \InvalidArgumentException(sprintf('%s must decode to a JSON object.', $label));
        }

        return $decoded;
    }
}
