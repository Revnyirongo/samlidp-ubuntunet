<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ServiceProvider;
use App\Entity\Tenant;
use App\Repository\ServiceProviderRepository;
use App\Service\AuditLogger;
use App\Service\MetadataService;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/sp', name: 'admin_sp_')]
#[IsGranted('ROLE_ADMIN')]
class ServiceProviderController extends AbstractController
{
    private const DEFAULT_ATTRIBUTE_CHOICES = [
        'uid',
        'mail',
        'cn',
        'sn',
        'givenName',
        'displayName',
        'eduPersonPrincipalName',
        'eduPersonAffiliation',
        'eduPersonScopedAffiliation',
        'eduPersonEntitlement',
        'schacHomeOrganization',
        'schacHomeOrganizationType',
    ];

    public function __construct(
        private readonly ServiceProviderRepository $spRepo,
        private readonly EntityManagerInterface    $em,
        private readonly MetadataService           $metadataService,
        private readonly string                    $sspMetadataDir,
        private readonly AuditLogger               $audit,
        private readonly PaginatorInterface        $paginator,
        private readonly LoggerInterface           $logger,
    ) {}

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(ServiceProvider $sp): Response
    {
        $this->denyAccessUnlessGranted('TENANT_VIEW', $sp->getTenant());
        $tenant = $sp->getTenant();
        if ($tenant === null) {
            throw $this->createNotFoundException('Service provider is not attached to a tenant.');
        }
        [$attributeChoices, $effectiveRelease, $tenantDefaultRelease] = $this->buildAttributeEditorState($tenant, $sp);

        return $this->render('admin/sp/show.html.twig', [
            'sp'                   => $sp,
            'tenant'               => $tenant,
            'attributeChoices'     => $attributeChoices,
            'effectiveRelease'     => $effectiveRelease,
            'tenantDefaultRelease' => $tenantDefaultRelease,
        ]);
    }

    #[Route('/{id}/approve', name: 'approve', methods: ['POST'])]
    public function approve(ServiceProvider $sp, Request $request): Response
    {
        $this->denyAccessUnlessGranted('TENANT_EDIT', $sp->getTenant());
        if (!$this->isCsrfTokenValid('approve-sp-' . $sp->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $sp->setApproved(true);
        $this->em->flush();

        $this->metadataService->regenerateConfigForTenant($sp->getTenant());

        $this->audit->log('sp.approved',
            tenantId:   $sp->getTenant()->getId(),
            entityType: 'ServiceProvider',
            entityId:   (string) $sp->getId(),
            data:       ['entityId' => $sp->getEntityId()]
        );

        $this->addFlash('success', sprintf('"%s" approved and added to SSP config.', $sp->getDisplayName()));
        $this->addPublicationFlash($sp);
        return $this->redirectToRoute('admin_tenant_show', ['id' => $sp->getTenant()->getId()]);
    }

    #[Route('/{id}/revoke', name: 'revoke', methods: ['POST'])]
    public function revoke(ServiceProvider $sp, Request $request): Response
    {
        $this->denyAccessUnlessGranted('TENANT_EDIT', $sp->getTenant());
        if (!$this->isCsrfTokenValid('revoke-sp-' . $sp->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $sp->setApproved(false);
        $this->em->flush();

        $this->metadataService->regenerateConfigForTenant($sp->getTenant());

        $this->audit->log('sp.revoked',
            tenantId:   $sp->getTenant()->getId(),
            entityType: 'ServiceProvider',
            entityId:   (string) $sp->getId(),
            data:       ['entityId' => $sp->getEntityId()]
        );

        $this->addFlash('warning', sprintf('"%s" access revoked.', $sp->getDisplayName()));
        return $this->redirectToRoute('admin_tenant_show', ['id' => $sp->getTenant()->getId()]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(ServiceProvider $sp, Request $request): Response
    {
        $this->denyAccessUnlessGranted('TENANT_EDIT', $sp->getTenant());
        if (!$this->isCsrfTokenValid('delete-sp-' . $sp->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $tenant      = $sp->getTenant();
        $displayName = $sp->getDisplayName();
        $entityId    = $sp->getEntityId();

        $this->em->remove($sp);
        $this->em->flush();

        $this->metadataService->regenerateConfigForTenant($tenant);

        $this->audit->log('sp.deleted',
            tenantId:   $tenant->getId(),
            entityType: 'ServiceProvider',
            entityId:   $entityId,
            data:       ['name' => $displayName]
        );

        $this->addFlash('success', sprintf('SP "%s" deleted.', $displayName));
        return $this->redirectToRoute('admin_tenant_show', ['id' => $tenant->getId()]);
    }

    #[Route('/{id}/refresh', name: 'refresh', methods: ['POST'])]
    public function refresh(ServiceProvider $sp, Request $request): Response
    {
        $this->denyAccessUnlessGranted('TENANT_EDIT', $sp->getTenant());
        if (!$this->isCsrfTokenValid('refresh-sp-' . $sp->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (empty($sp->getRawMetadataXml())) {
            $this->addFlash('warning', 'No metadata URL stored for this SP — import it again manually.');
            return $this->redirectToRoute('admin_sp_show', ['id' => $sp->getId()]);
        }

        try {
            // Re-import from the raw XML (URL-imported SPs store the URL in entity ID resolver)
            $metadataUrl = $request->request->getString('metadata_url');
            if ($metadataUrl) {
                $this->metadataService->importSpMetadata(
                    $sp->getTenant(), $metadataUrl, isUrl: true, approve: $sp->isApproved()
                );
                $this->addFlash('success', 'SP metadata refreshed from URL.');
            } else {
                $this->addFlash('info', 'Provide a metadata URL to refresh from source.');
            }
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Refresh failed: ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_sp_show', ['id' => $sp->getId()]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(ServiceProvider $sp, Request $request): Response
    {
        $this->denyAccessUnlessGranted('TENANT_EDIT', $sp->getTenant());
        $tenant = $sp->getTenant();
        if ($tenant === null) {
            throw $this->createNotFoundException('Service provider is not attached to a tenant.');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('sp_edit_' . $sp->getId(), $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $data = $request->request->all('sp');

            if (isset($data['name']))          $sp->setName($data['name'] ?: null);
            if (isset($data['acsUrl']))        $sp->setAcsUrl($data['acsUrl'] ?: null);
            if (isset($data['sloUrl']))        $sp->setSloUrl($data['sloUrl'] ?: null);
            if (isset($data['nameIdFormat']))  $sp->setNameIdFormat($data['nameIdFormat']);
            if (isset($data['contactEmail']))  $sp->setContactEmail($data['contactEmail'] ?: null);
            if (isset($data['signAssertions']))    $sp->setSignAssertions((bool) $data['signAssertions']);
            if (isset($data['encryptAssertions'])) $sp->setEncryptAssertions((bool) $data['encryptAssertions']);

            // Attribute release override
            $selectionMode = (string) ($data['attributeSelectionMode'] ?? '');
            $selectedAttributes = array_values(array_filter(
                array_map('trim', (array) ($data['selectedAttributes'] ?? [])),
                static fn (string $attr): bool => $attr !== ''
            ));

            if ($selectionMode === 'checkboxes') {
                $customAttributes = array_values(array_filter(
                    array_map('trim', explode("\n", (string) ($data['attributeReleaseOverride'] ?? ''))),
                    static fn (string $attr): bool => $attr !== ''
                ));
                if ($customAttributes !== []) {
                    $selectedAttributes = array_values(array_unique([...$selectedAttributes, ...$customAttributes]));
                }

                $defaultAttrs = $tenant->getAttributeReleasePolicy()['default'] ?? [];
                sort($selectedAttributes);
                $normalizedDefault = array_values(array_unique(array_map('strval', $defaultAttrs)));
                sort($normalizedDefault);

                $sp->setAttributeReleaseOverride($selectedAttributes === $normalizedDefault ? null : $selectedAttributes);
            } elseif (isset($data['attributeReleaseOverride'])) {
                $attrs = array_filter(
                    array_map('trim', explode("\n", $data['attributeReleaseOverride'])),
                    fn($a) => !empty($a)
                );
                $sp->setAttributeReleaseOverride(empty($attrs) ? null : array_values($attrs));
            }

            try {
                $this->em->flush();
                $this->metadataService->regenerateConfigForTenant($sp->getTenant());
                $this->addFlash('success', 'SP updated and config regenerated.');
                $this->addPublicationFlash($sp);
                return $this->redirectToRoute('admin_sp_show', ['id' => $sp->getId()]);
            } catch (\Throwable $e) {
                $this->addFlash('danger', 'Save failed: ' . $e->getMessage());
            }
        }

        [$attributeChoices, $effectiveRelease, $tenantDefaultRelease] = $this->buildAttributeEditorState($tenant, $sp);

        return $this->render('admin/sp/edit.html.twig', [
            'sp'                   => $sp,
            'tenant'               => $tenant,
            'attributeChoices'     => $attributeChoices,
            'effectiveRelease'     => $effectiveRelease,
            'tenantDefaultRelease' => $tenantDefaultRelease,
        ]);
    }

    private function addPublicationFlash(ServiceProvider $serviceProvider): void
    {
        $status = $this->getServiceProviderPublicationStatus($serviceProvider);
        $this->addFlash($status['published'] ? 'info' : 'warning', $status['message']);
    }

    /**
     * @return array{published: bool, message: string}
     */
    private function getServiceProviderPublicationStatus(ServiceProvider $serviceProvider): array
    {
        if (!$serviceProvider->isApproved()) {
            return [
                'published' => false,
                'message' => 'The SP is saved but not approved, so it is not expected in the live runtime metadata.',
            ];
        }

        $entityId = trim($serviceProvider->getEntityId());
        if ($entityId === '') {
            return [
                'published' => false,
                'message' => 'The SP is missing an entity ID, so runtime publication could not be verified.',
            ];
        }

        $metadataPath = $this->sspMetadataDir . '/saml20-sp-remote.php';
        if (!is_file($metadataPath) || !is_readable($metadataPath)) {
            return [
                'published' => false,
                'message' => 'The SimpleSAMLphp runtime metadata file is not available yet. Regenerate config and restart the runtime if needed.',
            ];
        }

        $contents = @file_get_contents($metadataPath);
        if (!is_string($contents)) {
            return [
                'published' => false,
                'message' => 'The SimpleSAMLphp runtime metadata file could not be read for verification.',
            ];
        }

        $needle = "\$metadata['" . addslashes($entityId) . "']";
        if (str_contains($contents, $needle)) {
            return [
                'published' => true,
                'message' => 'Runtime publication verified in the generated SimpleSAMLphp metadata.',
            ];
        }

        return [
            'published' => false,
            'message' => 'The SP was saved, but runtime publication could not be confirmed in the generated SimpleSAMLphp metadata. Regenerate config or restart the runtime containers.',
        ];
    }

    // ── Global SP search (super admin) ───────────────────────

    #[Route('', name: 'index', methods: ['GET'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function index(Request $request): Response
    {
        $q = '';
        $approved = '';

        $qb = $this->spRepo->createQueryBuilder('sp')
            ->join('sp.tenant', 't')
            ->orderBy('sp.createdAt', 'DESC');

        if ($q = $request->query->getString('q')) {
            $qb->andWhere('sp.entityId LIKE :q OR sp.name LIKE :q OR t.name LIKE :q')
               ->setParameter('q', '%' . $q . '%');
        }

        if ($approved = $request->query->getString('approved')) {
            $qb->andWhere('sp.approved = :approved')
               ->setParameter('approved', $approved === '1');
        }

        $pagination = $this->paginator->paginate(
            $qb,
            $request->query->getInt('page', 1),
            50
        );

        return $this->render('admin/sp/index.html.twig', [
            'pagination' => $pagination,
            'q'          => $q,
            'approved'   => $approved,
        ]);
    }

    // ── API: get SP metadata XML ──────────────────────────────

    #[Route('/{id}/metadata-xml', name: 'metadata_xml', methods: ['GET'])]
    public function metadataXml(ServiceProvider $sp): Response
    {
        $this->denyAccessUnlessGranted('TENANT_VIEW', $sp->getTenant());

        if (empty($sp->getRawMetadataXml())) {
            return new Response('No metadata XML stored.', 404, ['Content-Type' => 'text/plain']);
        }

        return new Response(
            $sp->getRawMetadataXml(),
            200,
            [
                'Content-Type'        => 'application/samlmetadata+xml',
                'Content-Disposition' => sprintf(
                    'attachment; filename="sp-metadata-%s.xml"',
                    preg_replace('/[^a-z0-9]+/', '-', strtolower($sp->getDisplayName()))
                ),
            ]
        );
    }

    /**
     * Builds a stable attribute selection model for SP edit/show screens.
     *
     * @return array{0: string[], 1: string[], 2: string[]}
     */
    private function buildAttributeEditorState(Tenant $tenant, ServiceProvider $sp): array
    {
        $tenantDefault = $tenant->getAttributeReleasePolicy()['default'] ?? self::DEFAULT_ATTRIBUTE_CHOICES;
        $tenantDefault = $this->normalizeAttributeList($tenantDefault);

        $effectiveRelease = $this->normalizeAttributeList($sp->getAttributeReleaseOverride() ?? $tenantDefault);
        $requested = $this->normalizeAttributeList($sp->getRequestedAttributes() ?? []);

        $attributeChoices = $this->normalizeAttributeList([
            ...self::DEFAULT_ATTRIBUTE_CHOICES,
            ...$tenantDefault,
            ...$requested,
            ...$effectiveRelease,
        ]);

        return [$attributeChoices, $effectiveRelease, $tenantDefault];
    }

    /**
     * @param array<int, mixed> $values
     * @return string[]
     */
    private function normalizeAttributeList(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $attribute = trim((string) $value);
            if ($attribute !== '') {
                $normalized[] = $attribute;
            }
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized);

        return $normalized;
    }
}
