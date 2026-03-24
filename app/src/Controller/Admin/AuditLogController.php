<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/audit', name: 'admin_audit_')]
#[IsGranted('ROLE_ADMIN')]
class AuditLogController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantRepository       $tenantRepo,
        private readonly PaginatorInterface     $paginator,
    ) {}

    #[Route('', name: 'log', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $action = '';
        $tenantId = '';
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authenticated user required.');
        }

        $qb = $this->em->createQueryBuilder()
            ->select('a')
            ->from(\App\Entity\AuditLog::class, 'a')
            ->orderBy('a.createdAt', 'DESC');

        $tenants = $this->tenantRepo->findManagedByUser($user);

        // Super-admins see all; regular admins see only their tenants
        if (!$user->isSuperAdmin()) {
            $tenantIds = array_map(
                static fn ($tenant): string => (string) $tenant->getId(),
                $tenants,
            );

            if (empty($tenantIds)) {
                $qb->andWhere('1=0'); // No tenants = no log access
            } else {
                $qb->andWhere('a.tenantId IN (:tenantIds)')
                   ->setParameter('tenantIds', $tenantIds);
            }
        }

        // Filter by action keyword
        if ($action = $request->query->getString('action')) {
            $qb->andWhere('a.action LIKE :action')->setParameter('action', '%' . $action . '%');
        }

        // Filter by tenant
        if ($tenantId = $request->query->getString('tenant')) {
            $qb->andWhere('a.tenantId = :tenantId')->setParameter('tenantId', $tenantId);
        }

        $pagination = $this->paginator->paginate(
            $qb,
            $request->query->getInt('page', 1),
            50
        );

        return $this->render('admin/audit/index.html.twig', [
            'pagination' => $pagination,
            'tenants'    => $user->isSuperAdmin() ? $this->tenantRepo->findAllActive() : $tenants,
            'action'     => $action,
            'tenantId'   => $tenantId,
        ]);
    }
}
