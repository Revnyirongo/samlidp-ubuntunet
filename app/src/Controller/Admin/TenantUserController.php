<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\IdpUser;
use App\Entity\IdpUserActionToken;
use App\Entity\Tenant;
use App\Repository\IdpUserRepository;
use App\Service\AuditLogger;
use App\Service\IdpUserActionTokenService;
use App\Service\IdpUserPasswordManager;
use App\Service\MailerStatus;
use App\Service\NotificationMailer;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/admin/tenants/{tenant}/users', name: 'admin_tenant_user_')]
#[IsGranted('ROLE_ADMIN')]
class TenantUserController extends AbstractController
{
    public function __construct(
        private readonly IdpUserRepository $idpUserRepo,
        private readonly EntityManagerInterface $em,
        private readonly PaginatorInterface $paginator,
        private readonly ValidatorInterface $validator,
        private readonly AuditLogger $audit,
        private readonly IdpUserActionTokenService $tokenService,
        private readonly NotificationMailer $mailer,
        private readonly MailerStatus $mailerStatus,
        private readonly IdpUserPasswordManager $passwordManager,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Tenant $tenant, Request $request): Response
    {
        $this->denyAccessUnlessGranted('TENANT_VIEW', $tenant);
        $this->ensureDatabaseAuth($tenant);

        $q = trim($request->query->getString('q'));

        $qb = $this->idpUserRepo->createQueryBuilder('u')
            ->where('u.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('u.username', 'ASC');

        if ($q !== '') {
            $qb->andWhere('u.username LIKE :q')
                ->setParameter('q', '%' . $q . '%');
        }

        return $this->render('admin/tenant_user/index.html.twig', [
            'tenant' => $tenant,
            'q' => $q,
            'mailer_enabled' => $this->mailerStatus->isEnabled(),
            'pagination' => $this->paginator->paginate($qb, $request->query->getInt('page', 1), 25),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Tenant $tenant, Request $request): Response
    {
        $this->denyAccessUnlessGranted('TENANT_EDIT', $tenant);
        $this->ensureDatabaseAuth($tenant);

        $idpUser = (new IdpUser())->setTenant($tenant);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('tenant_user_new_' . $tenant->getId(), $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $data = $request->request->all('user');
            if ($this->applyUserFormData($tenant, $idpUser, $data, true)) {
                $this->em->persist($idpUser);
                $this->em->flush();

                $this->audit->log(
                    'tenant_user.created',
                    tenantId: $tenant->getId(),
                    entityType: 'IdpUser',
                    entityId: (string) $idpUser->getId(),
                    data: ['username' => $idpUser->getUsername(), 'tenant' => $tenant->getSlug()],
                );

                $this->addFlash('success', sprintf('Created local user "%s".', $idpUser->getUsername()));

                return $this->redirectToRoute('admin_tenant_user_index', ['tenant' => $tenant->getId()]);
            }
        }

        return $this->render('admin/tenant_user/new.html.twig', [
            'tenant' => $tenant,
            'idpUser' => $idpUser,
            'formData' => $this->formDataFromUser($idpUser),
            'mailer_enabled' => $this->mailerStatus->isEnabled(),
        ]);
    }

    #[Route('/bulk-import', name: 'bulk_import', methods: ['POST'])]
    public function bulkImport(Tenant $tenant, Request $request): Response
    {
        $this->denyAccessUnlessGranted('TENANT_EDIT', $tenant);
        $this->ensureDatabaseAuth($tenant);

        if (!$this->isCsrfTokenValid('tenant_user_bulk_' . $tenant->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $content = $this->extractCsvContent($request);
        if ($content === '') {
            $this->addFlash('danger', 'Provide a CSV file or paste CSV text first.');
            return $this->redirectToRoute('admin_tenant_user_index', ['tenant' => $tenant->getId()]);
        }

        $lines = preg_split('/\r\n|\n|\r/', trim($content)) ?: [];
        if (count($lines) < 2) {
            $this->addFlash('danger', 'CSV import requires a header row and at least one data row.');
            return $this->redirectToRoute('admin_tenant_user_index', ['tenant' => $tenant->getId()]);
        }

        $headers = array_map([$this, 'normalizeCsvHeader'], str_getcsv((string) array_shift($lines)));
        if (!in_array('username', $headers, true) && !in_array('uid', $headers, true)) {
            $this->addFlash('danger', 'CSV header must include "username" or "uid".');
            return $this->redirectToRoute('admin_tenant_user_index', ['tenant' => $tenant->getId()]);
        }

        $created = 0;
        $updated = 0;
        $errors = [];

        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }

            $rowValues = str_getcsv($line);
            $row = [];
            foreach ($headers as $columnIndex => $header) {
                $row[$header] = trim($rowValues[$columnIndex] ?? '');
            }

            $username = $row['username'] ?: ($row['uid'] ?? '');
            if ($username === '') {
                $errors[] = sprintf('Row %d: missing username.', $index + 2);
                continue;
            }

            $idpUser = $this->idpUserRepo->findByTenantAndUsername($tenant, $username);
            $isNew = $idpUser === null;
            $idpUser ??= (new IdpUser())->setTenant($tenant);

            if (!$this->applyUserFormData($tenant, $idpUser, [
                'username' => $username,
                'password' => $row['password'] ?? '',
                'email' => $row['email'] ?? ($row['mail'] ?? ''),
                'displayName' => $row['display_name'] ?? ($row['displayname'] ?? ($row['cn'] ?? '')),
                'givenName' => $row['given_name'] ?? ($row['givenname'] ?? ''),
                'sn' => $row['surname'] ?? ($row['sn'] ?? ''),
                'eduPersonPrincipalName' => $row['edupersonprincipalname'] ?? ($row['eppn'] ?? ''),
                'eduPersonAffiliation' => $row['edupersonaffiliation'] ?? ($row['affiliation'] ?? ''),
                'schacHomeOrganization' => $row['schachomeorganization'] ?? ($row['home_organization'] ?? ''),
                'isActive' => $row['active'] ?? '1',
                'attributesJson' => $row['attributes_json'] ?? '',
            ], $isNew, flashErrors: false)) {
                $errors[] = sprintf('Row %d: invalid data for "%s".', $index + 2, $username);
                continue;
            }

            $this->em->persist($idpUser);
            $isNew ? $created++ : $updated++;
        }

        $this->em->flush();

        $this->audit->log(
            'tenant_user.bulk_import',
            tenantId: $tenant->getId(),
            entityType: 'Tenant',
            entityId: (string) $tenant->getId(),
            data: ['created' => $created, 'updated' => $updated, 'errors' => count($errors)],
        );

        $this->addFlash('success', sprintf('Bulk import finished: %d created, %d updated.', $created, $updated));
        foreach (array_slice($errors, 0, 10) as $error) {
            $this->addFlash('warning', $error);
        }

        return $this->redirectToRoute('admin_tenant_user_index', ['tenant' => $tenant->getId()]);
    }

    #[Route('/{user}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Tenant $tenant, IdpUser $user, Request $request): Response
    {
        $this->denyAccessUnlessGranted('TENANT_EDIT', $tenant);
        $this->ensureDatabaseAuth($tenant);
        $this->assertUserBelongsToTenant($tenant, $user);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('tenant_user_edit_' . $user->getId(), $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $data = $request->request->all('user');
            if ($this->applyUserFormData($tenant, $user, $data, false)) {
                $this->em->flush();

                $this->audit->log(
                    'tenant_user.updated',
                    tenantId: $tenant->getId(),
                    entityType: 'IdpUser',
                    entityId: (string) $user->getId(),
                    data: ['username' => $user->getUsername(), 'tenant' => $tenant->getSlug()],
                );

                $this->addFlash('success', sprintf('Updated local user "%s".', $user->getUsername()));

                return $this->redirectToRoute('admin_tenant_user_index', ['tenant' => $tenant->getId()]);
            }
        }

        return $this->render('admin/tenant_user/edit.html.twig', [
            'tenant' => $tenant,
            'idpUser' => $user,
            'formData' => $this->formDataFromUser($user),
            'mailer_enabled' => $this->mailerStatus->isEnabled(),
        ]);
    }

    #[Route('/{user}/toggle-active', name: 'toggle_active', methods: ['POST'])]
    public function toggleActive(Tenant $tenant, IdpUser $user, Request $request): Response
    {
        $this->denyAccessUnlessGranted('TENANT_EDIT', $tenant);
        $this->ensureDatabaseAuth($tenant);
        $this->assertUserBelongsToTenant($tenant, $user);

        if (!$this->isCsrfTokenValid('tenant_user_toggle_' . $user->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $user->setIsActive(!$user->isActive());
        $this->em->flush();

        $this->audit->log(
            $user->isActive() ? 'tenant_user.activated' : 'tenant_user.deactivated',
            tenantId: $tenant->getId(),
            entityType: 'IdpUser',
            entityId: (string) $user->getId(),
            data: ['username' => $user->getUsername(), 'tenant' => $tenant->getSlug()],
        );

        $this->addFlash('success', sprintf(
            'Local user "%s" %s.',
            $user->getUsername(),
            $user->isActive() ? 'activated' : 'deactivated',
        ));

        return $this->redirectToRoute('admin_tenant_user_index', ['tenant' => $tenant->getId()]);
    }

    #[Route('/{user}/send-reset', name: 'send_reset', methods: ['POST'])]
    public function sendReset(Tenant $tenant, IdpUser $user, Request $request): Response
    {
        $this->denyAccessUnlessGranted('TENANT_EDIT', $tenant);
        $this->ensureDatabaseAuth($tenant);
        $this->assertUserBelongsToTenant($tenant, $user);

        if (!$this->isCsrfTokenValid('tenant_user_send_reset_' . $user->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if ($user->getEmail() === null) {
            $this->addFlash('danger', sprintf('Local user "%s" does not have an email address.', $user->getUsername()));
            return $this->redirectToRoute('admin_tenant_user_index', ['tenant' => $tenant->getId()]);
        }

        try {
            $rawToken = $this->tokenService->issue($user, IdpUserActionToken::PURPOSE_PASSWORD_RESET);
            $this->mailer->sendTenantUserPasswordReset($user, $rawToken);
        } catch (\Throwable $e) {
            $this->audit->log(
                'tenant_user.password_reset_failed',
                tenantId: $tenant->getId(),
                entityType: 'IdpUser',
                entityId: (string) $user->getId(),
                data: ['username' => $user->getUsername(), 'error' => $e->getMessage()],
            );

            $this->addFlash('danger', sprintf(
                'Could not send a password reset email to "%s" right now. Please verify the mail service and try again.',
                $user->getUsername(),
            ));

            return $this->redirectToRoute('admin_tenant_user_index', ['tenant' => $tenant->getId()]);
        }

        $this->audit->log(
            'tenant_user.password_reset_requested',
            tenantId: $tenant->getId(),
            entityType: 'IdpUser',
            entityId: (string) $user->getId(),
            data: ['username' => $user->getUsername(), 'tenant' => $tenant->getSlug()],
        );

        $this->addFlash('success', sprintf('Password reset email sent to %s.', $user->getEmail()));

        return $this->redirectToRoute('admin_tenant_user_index', ['tenant' => $tenant->getId()]);
    }

    #[Route('/{user}/send-invite', name: 'send_invite', methods: ['POST'])]
    public function sendInvite(Tenant $tenant, IdpUser $user, Request $request): Response
    {
        $this->denyAccessUnlessGranted('TENANT_EDIT', $tenant);
        $this->ensureDatabaseAuth($tenant);
        $this->assertUserBelongsToTenant($tenant, $user);

        if (!$this->isCsrfTokenValid('tenant_user_send_invite_' . $user->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if ($user->getEmail() === null) {
            $this->addFlash('danger', sprintf('Local user "%s" does not have an email address.', $user->getUsername()));
            return $this->redirectToRoute('admin_tenant_user_index', ['tenant' => $tenant->getId()]);
        }

        try {
            $user->setIsActive(true);
            $rawToken = $this->tokenService->issue($user, IdpUserActionToken::PURPOSE_SET_PASSWORD, new \DateInterval('P1D'));
            $this->mailer->sendTenantUserPasswordReset($user, $rawToken, true);
        } catch (\Throwable $e) {
            $this->audit->log(
                'tenant_user.invite_failed',
                tenantId: $tenant->getId(),
                entityType: 'IdpUser',
                entityId: (string) $user->getId(),
                data: ['username' => $user->getUsername(), 'error' => $e->getMessage()],
            );

            $this->addFlash('danger', sprintf(
                'Could not send an invitation email to "%s" right now. Please verify the mail service and try again.',
                $user->getUsername(),
            ));

            return $this->redirectToRoute('admin_tenant_user_index', ['tenant' => $tenant->getId()]);
        }

        $this->audit->log(
            'tenant_user.invited',
            tenantId: $tenant->getId(),
            entityType: 'IdpUser',
            entityId: (string) $user->getId(),
            data: ['username' => $user->getUsername(), 'tenant' => $tenant->getSlug()],
        );

        $this->addFlash('success', sprintf('Invitation email sent to %s.', $user->getEmail()));

        return $this->redirectToRoute('admin_tenant_user_index', ['tenant' => $tenant->getId()]);
    }

    #[Route('/{user}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Tenant $tenant, IdpUser $user, Request $request): Response
    {
        $this->denyAccessUnlessGranted('TENANT_EDIT', $tenant);
        $this->ensureDatabaseAuth($tenant);
        $this->assertUserBelongsToTenant($tenant, $user);

        if (!$this->isCsrfTokenValid('tenant_user_delete_' . $user->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $username = $user->getUsername();
        $this->em->remove($user);
        $this->em->flush();

        $this->audit->log(
            'tenant_user.deleted',
            tenantId: $tenant->getId(),
            entityType: 'IdpUser',
            entityId: (string) $user->getId(),
            data: ['username' => $username, 'tenant' => $tenant->getSlug()],
        );

        $this->addFlash('success', sprintf('Deleted local user "%s".', $username));

        return $this->redirectToRoute('admin_tenant_user_index', ['tenant' => $tenant->getId()]);
    }

    private function ensureDatabaseAuth(Tenant $tenant): void
    {
        if (!$tenant->usesDatabaseAuth()) {
            throw $this->createNotFoundException('This tenant is not using the managed database user store.');
        }
    }

    private function assertUserBelongsToTenant(Tenant $tenant, IdpUser $user): void
    {
        if ($user->getTenant()?->getId()?->toRfc4122() !== $tenant->getId()?->toRfc4122()) {
            throw $this->createNotFoundException('Tenant user not found.');
        }
    }

    private function extractCsvContent(Request $request): string
    {
        $file = $request->files->get('csv_file');
        if ($file instanceof UploadedFile && $file->isValid()) {
            return trim((string) file_get_contents($file->getPathname()));
        }

        return trim($request->request->getString('csv_text'));
    }

    private function normalizeCsvHeader(string $header): string
    {
        return strtolower(trim(str_replace([' ', '-'], '_', $header)));
    }

    private function applyUserFormData(Tenant $tenant, IdpUser $user, array $data, bool $requirePassword, bool $flashErrors = true): bool
    {
        $username = trim((string) ($data['username'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $email = strtolower(trim((string) ($data['email'] ?? '')));

        if ($username === '') {
            if ($flashErrors) {
                $this->addFlash('danger', 'Username is required.');
            }
            return false;
        }

        $existing = $this->idpUserRepo->findByTenantAndUsername($tenant, $username);
        if ($existing !== null && $existing !== $user) {
            if ($flashErrors) {
                $this->addFlash('danger', sprintf('A local user named "%s" already exists for this tenant.', $username));
            }
            return false;
        }

        if ($requirePassword && strlen($password) < 12) {
            if ($flashErrors) {
                $this->addFlash('danger', 'Password must be at least 12 characters.');
            }
            return false;
        }

        if ($password !== '' && strlen($password) < 12) {
            if ($flashErrors) {
                $this->addFlash('danger', 'Password must be at least 12 characters.');
            }
            return false;
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            if ($flashErrors) {
                $this->addFlash('danger', 'Email address must be valid before you can send invites or password resets.');
            }
            return false;
        }

        $rawAttributesJson = trim((string) ($data['attributesJson'] ?? ''));
        if ($rawAttributesJson !== '' && $this->decodeAttributesJson($rawAttributesJson) === null) {
            if ($flashErrors) {
                $this->addFlash('danger', 'Attributes JSON must be valid JSON.');
            }
            return false;
        }

        $user
            ->setTenant($tenant)
            ->setUsername($username)
            ->setIsActive($this->toBool($data['isActive'] ?? true))
            ->setAttributes($this->buildAttributes($data, $username));

        if ($password !== '') {
            $this->passwordManager->applyPassword($user, $password);
        }

        $errors = $this->validator->validate($user);
        if (count($errors) > 0) {
            if ($flashErrors) {
                foreach ($errors as $error) {
                    $this->addFlash('danger', $error->getPropertyPath() . ': ' . $error->getMessage());
                }
            }
            return false;
        }

        return true;
    }

    private function buildAttributes(array $data, string $username): array
    {
        $attributes = [
            'uid' => [$username],
        ];

        $displayName = trim((string) ($data['displayName'] ?? ''));
        $mail = trim((string) ($data['email'] ?? ''));
        $givenName = trim((string) ($data['givenName'] ?? ''));
        $sn = trim((string) ($data['sn'] ?? ''));
        $eppn = trim((string) ($data['eduPersonPrincipalName'] ?? ''));
        $homeOrg = trim((string) ($data['schacHomeOrganization'] ?? ''));

        if ($mail !== '') {
            $attributes['mail'] = [$mail];
        }

        if ($displayName !== '') {
            $attributes['displayName'] = [$displayName];
            $attributes['cn'] = [$displayName];
        }

        if ($givenName !== '') {
            $attributes['givenName'] = [$givenName];
        }

        if ($sn !== '') {
            $attributes['sn'] = [$sn];
        }

        if ($eppn !== '') {
            $attributes['eduPersonPrincipalName'] = [$eppn];
        }

        $affiliations = $this->splitMultiValue((string) ($data['eduPersonAffiliation'] ?? ''));
        if ($affiliations !== []) {
            $attributes['eduPersonAffiliation'] = $affiliations;
        }

        if ($homeOrg !== '') {
            $attributes['schacHomeOrganization'] = [$homeOrg];
        }

        $rawAttributesJson = trim((string) ($data['attributesJson'] ?? ''));
        if ($rawAttributesJson !== '') {
            foreach ($this->decodeAttributesJson($rawAttributesJson) ?? [] as $key => $value) {
                if (!is_string($key) || $key === '') {
                    continue;
                }
                $attributes[$key] = $this->normalizeAttributeValue($value);
            }
        }

        return $attributes;
    }

    private function formDataFromUser(IdpUser $user): array
    {
        $attributes = $user->getAttributes();

        return [
            'username' => $user->getUsername(),
            'email' => $attributes['mail'][0] ?? '',
            'displayName' => $attributes['displayName'][0] ?? ($attributes['cn'][0] ?? ''),
            'givenName' => $attributes['givenName'][0] ?? '',
            'sn' => $attributes['sn'][0] ?? '',
            'eduPersonPrincipalName' => $attributes['eduPersonPrincipalName'][0] ?? '',
            'eduPersonAffiliation' => isset($attributes['eduPersonAffiliation']) ? implode(', ', $attributes['eduPersonAffiliation']) : '',
            'schacHomeOrganization' => $attributes['schacHomeOrganization'][0] ?? '',
            'attributesJson' => json_encode($attributes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'isActive' => $user->isActive(),
        ];
    }

    private function splitMultiValue(string $value): array
    {
        $parts = array_filter(array_map('trim', preg_split('/[,;\n]/', $value) ?: []), static fn (string $item): bool => $item !== '');

        return array_values($parts);
    }

    private function normalizeAttributeValue(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_map(static fn (mixed $item): string => (string) $item, $value));
        }

        if ($value === null || $value === '') {
            return [];
        }

        return [(string) $value];
    }

    private function decodeAttributesJson(string $rawAttributesJson): ?array
    {
        $decoded = json_decode($rawAttributesJson, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return !in_array(strtolower((string) $value), ['0', 'false', 'no', 'off'], true);
    }

}
