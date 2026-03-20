<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\ServiceProviderRepository;
use App\Repository\TenantRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class DashboardController extends AbstractController
{
    public function __construct(
        private readonly TenantRepository          $tenantRepo,
        private readonly ServiceProviderRepository $spRepo,
    ) {}

    #[Route('/admin', name: 'admin_dashboard')]
    public function index(): Response
    {
        $user = $this->getUser();

        // Super admins see all tenants; regular admins see only their tenants
        $recentTenants = $user->isSuperAdmin()
            ? $this->tenantRepo->findBy([], ['createdAt' => 'DESC'], 10)
            : $user->getManagedTenants()->slice(0, 10);

        return $this->render('admin/dashboard/index.html.twig', [
            'statusCounts'  => $this->tenantRepo->countByStatus(),
            'totalSps'      => count($this->spRepo->findAll()),
            'recentTenants' => $recentTenants,
            'expiringSps'   => $this->spRepo->findExpiringSoon(60),
        ]);
    }
}
