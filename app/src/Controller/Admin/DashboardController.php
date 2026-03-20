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
        if (!$user instanceof \App\Entity\User) {
            throw $this->createAccessDeniedException('Authenticated user required.');
        }

        $managedTenants = $this->tenantRepo->findManagedByUser($user);
        usort($managedTenants, static fn ($a, $b) => $b->getCreatedAt() <=> $a->getCreatedAt());
        $recentTenants = array_slice($managedTenants, 0, 10);

        return $this->render('admin/dashboard/index.html.twig', [
            'statusCounts'  => $this->tenantRepo->countByStatusForUser($user),
            'totalSps'      => $this->spRepo->countByUser($user),
            'recentTenants' => $recentTenants,
            'expiringSps'   => $this->spRepo->findExpiringSoonByUser($user, 60),
        ]);
    }
}
