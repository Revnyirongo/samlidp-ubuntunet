<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\IdpUser;
use App\Entity\IdpUserActionToken;
use App\Entity\Tenant;
use App\Entity\TenantUserRegistrationRequest;
use App\Repository\IdpUserRepository;
use App\Repository\TenantUserRegistrationRequestRepository;
use App\Service\AuditLogger;
use App\Service\IdpUserActionTokenService;
use App\Service\IdpUserPasswordManager;
use App\Service\NotificationMailer;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/tenants/{tenant}/registration-requests', name: 'admin_tenant_registration_request_')]
#[IsGranted('ROLE_ADMIN')]
class TenantRegistrationRequestController extends AbstractController
{
    public function __construct(
        private readonly TenantUserRegistrationRequestRepository $registrationRepo,
        private readonly IdpUserRepository $idpUserRepo,
        private readonly EntityManagerInterface $em,
        private readonly PaginatorInterface $paginator,
        private readonly NotificationMailer $mailer,
        private readonly AuditLogger $audit,
        private readonly IdpUserPasswordManager $passwordManager,
        private readonly IdpUserActionTokenService $tokenService,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Tenant $tenant, Request $request): Response
    {
        $this->denyAccessUnlessGranted('TENANT_VIEW', $tenant);

        $status = trim($request->query->getString('status', TenantUserRegistrationRequest::STATUS_PENDING));
        $qb = $this->registrationRepo->createQueryBuilder('r')
            ->where('r.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('r.createdAt', 'DESC');

        if ($status !== '') {
            $qb->andWhere('r.status = :status')->setParameter('status', $status);
        }

        return $this->render('admin/tenant_registration_request/index.html.twig', [
            'tenant' => $tenant,
            'status' => $status,
            'pagination' => $this->paginator->paginate($qb, $request->query->getInt('page', 1), 25),
        ]);
    }

    #[Route('/{id}/approve', name: 'approve', methods: ['POST'])]
    public function approve(Tenant $tenant, TenantUserRegistrationRequest $registrationRequest, Request $request): Response
    {
        $this->denyAccessUnlessGranted('TENANT_EDIT', $tenant);
        $this->assertRequestBelongsToTenant($tenant, $registrationRequest);

        if (!$this->isCsrfTokenValid('approve-tenant-registration-' . $registrationRequest->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if ($registrationRequest->getStatus() !== TenantUserRegistrationRequest::STATUS_PENDING) {
            $this->addFlash('warning', 'This registration request has already been reviewed.');
            return $this->redirectToRoute('admin_tenant_registration_request_index', ['tenant' => $tenant->getId()]);
        }

        $user = $this->resolveExistingUser($tenant, $registrationRequest);
        if ($user === false) {
            return $this->redirectToRoute('admin_tenant_registration_request_index', ['tenant' => $tenant->getId()]);
        }

        $isNew = !$user instanceof IdpUser;
        $user ??= (new IdpUser())->setTenant($tenant);

        $user
            ->setTenant($tenant)
            ->setUsername($registrationRequest->getUsername())
            ->setAttributes($this->buildAttributes($tenant, $registrationRequest))
            ->setIsActive(false);

        if ($isNew || $user->getPassword() === '') {
            $this->passwordManager->applyPassword($user, bin2hex(random_bytes(16)));
        }

        $registrationRequest
            ->setStatus(TenantUserRegistrationRequest::STATUS_APPROVED)
            ->setReviewNotes(trim($request->request->getString('reviewNotes')) ?: null)
            ->setReviewedAt(new \DateTimeImmutable());

        $this->em->persist($user);
        $this->em->flush();

        $mailFailed = false;
        try {
            $rawToken = $this->tokenService->issue($user, IdpUserActionToken::PURPOSE_SET_PASSWORD, new \DateInterval('P1D'));
            $this->mailer->sendTenantUserPasswordReset($user, $rawToken, true);
        } catch (\Throwable) {
            $mailFailed = true;
        }

        $this->audit->log(
            'tenant_user_registration.approved',
            tenantId: $tenant->getId(),
            entityType: 'TenantUserRegistrationRequest',
            entityId: (string) $registrationRequest->getId(),
            data: ['username' => $registrationRequest->getUsername(), 'email' => $registrationRequest->getEmail()],
        );

        $this->addFlash($mailFailed ? 'warning' : 'success', $mailFailed
            ? 'Registration approved, but the invitation email could not be sent. Use "Send Invite" from Local Users after fixing mail delivery.'
            : 'Registration request approved and invitation email sent.');

        return $this->redirectToRoute('admin_tenant_registration_request_index', ['tenant' => $tenant->getId()]);
    }

    #[Route('/{id}/reject', name: 'reject', methods: ['POST'])]
    public function reject(Tenant $tenant, TenantUserRegistrationRequest $registrationRequest, Request $request): Response
    {
        $this->denyAccessUnlessGranted('TENANT_EDIT', $tenant);
        $this->assertRequestBelongsToTenant($tenant, $registrationRequest);

        if (!$this->isCsrfTokenValid('reject-tenant-registration-' . $registrationRequest->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if ($registrationRequest->getStatus() !== TenantUserRegistrationRequest::STATUS_PENDING) {
            $this->addFlash('warning', 'This registration request has already been reviewed.');
            return $this->redirectToRoute('admin_tenant_registration_request_index', ['tenant' => $tenant->getId()]);
        }

        $registrationRequest
            ->setStatus(TenantUserRegistrationRequest::STATUS_REJECTED)
            ->setReviewNotes(trim($request->request->getString('reviewNotes')) ?: null)
            ->setReviewedAt(new \DateTimeImmutable());
        $this->em->flush();

        $mailFailed = false;
        try {
            $this->mailer->sendTenantRegistrationRejected($registrationRequest);
        } catch (\Throwable) {
            $mailFailed = true;
        }

        $this->audit->log(
            'tenant_user_registration.rejected',
            tenantId: $tenant->getId(),
            entityType: 'TenantUserRegistrationRequest',
            entityId: (string) $registrationRequest->getId(),
            data: ['username' => $registrationRequest->getUsername(), 'email' => $registrationRequest->getEmail()],
        );

        $this->addFlash($mailFailed ? 'warning' : 'success', $mailFailed
            ? 'Registration rejected, but the rejection email could not be sent.'
            : 'Registration request rejected.');

        return $this->redirectToRoute('admin_tenant_registration_request_index', ['tenant' => $tenant->getId()]);
    }

    private function assertRequestBelongsToTenant(Tenant $tenant, TenantUserRegistrationRequest $registrationRequest): void
    {
        if ($registrationRequest->getTenant()?->getId()?->toRfc4122() !== $tenant->getId()?->toRfc4122()) {
            throw $this->createNotFoundException('Registration request not found.');
        }
    }

    private function resolveExistingUser(Tenant $tenant, TenantUserRegistrationRequest $registrationRequest): IdpUser|false|null
    {
        $byUsername = $this->idpUserRepo->findByTenantAndUsername($tenant, $registrationRequest->getUsername());
        $byEmail = $this->idpUserRepo->findByTenantAndEmail($tenant, $registrationRequest->getEmail());

        if ($byUsername !== null && $byEmail !== null && $byUsername !== $byEmail) {
            $this->addFlash('danger', 'This request conflicts with two different existing local users. Resolve the existing accounts first.');
            return false;
        }

        $user = $byUsername ?? $byEmail;
        if ($user instanceof IdpUser && $user->getEmail() !== null && strtolower($user->getEmail()) !== strtolower($registrationRequest->getEmail())) {
            $this->addFlash('danger', 'An existing user already uses this username with a different email address.');
            return false;
        }

        return $user;
    }

    private function buildAttributes(Tenant $tenant, TenantUserRegistrationRequest $registrationRequest): array
    {
        $username = $registrationRequest->getUsername();
        $fullName = $registrationRequest->getFullName();
        $givenName = $registrationRequest->getGivenName() ?? $this->guessGivenName($fullName);
        $surname = $registrationRequest->getSurname() ?? $this->guessSurname($fullName);
        $homeOrganization = $this->deriveHomeOrganization($tenant);

        $attributes = [
            'uid' => [$username],
            'mail' => [$registrationRequest->getEmail()],
            'displayName' => [$fullName],
            'cn' => [$fullName],
        ];

        if ($givenName !== null) {
            $attributes['givenName'] = [$givenName];
        }

        if ($surname !== null) {
            $attributes['sn'] = [$surname];
        }

        if ($registrationRequest->getAffiliation() !== null) {
            $attributes['eduPersonAffiliation'] = [$registrationRequest->getAffiliation()];
        }

        if ($homeOrganization !== null) {
            $attributes['schacHomeOrganization'] = [$homeOrganization];
            $attributes['eduPersonPrincipalName'] = [$username . '@' . $homeOrganization];
        }

        return $attributes;
    }

    private function deriveHomeOrganization(Tenant $tenant): ?string
    {
        $profile = $tenant->getMetadataProfile();

        $scopes = $profile['scope'] ?? $profile['scopes'] ?? null;
        if (is_array($scopes)) {
            foreach ($scopes as $scope) {
                $candidate = strtolower(trim((string) $scope));
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        $discoHints = $profile['disco_hints'] ?? [];
        if (is_array($discoHints)) {
            foreach ($discoHints['domain'] ?? [] as $domain) {
                $candidate = strtolower(trim((string) $domain));
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        $organizationUrl = $tenant->getOrganizationUrl();
        if (is_string($organizationUrl) && $organizationUrl !== '') {
            $host = parse_url($organizationUrl, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                return strtolower($host);
            }
        }

        return strtolower($tenant->getSlug() . '.idp.ubuntunet.net');
    }

    private function guessGivenName(string $fullName): ?string
    {
        $parts = preg_split('/\s+/', trim($fullName)) ?: [];

        return $parts[0] ?? null;
    }

    private function guessSurname(string $fullName): ?string
    {
        $parts = array_values(array_filter(preg_split('/\s+/', trim($fullName)) ?: []));
        if (count($parts) < 2) {
            return null;
        }

        return $parts[count($parts) - 1];
    }
}
