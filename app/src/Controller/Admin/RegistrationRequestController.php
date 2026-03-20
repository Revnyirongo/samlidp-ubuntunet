<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\RegistrationRequest;
use App\Entity\User;
use App\Entity\UserActionToken;
use App\Repository\RegistrationRequestRepository;
use App\Repository\UserRepository;
use App\Service\NotificationMailer;
use App\Service\UserActionTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/registration-requests', name: 'admin_registration_request_')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class RegistrationRequestController extends AbstractController
{
    public function __construct(
        private readonly RegistrationRequestRepository $registrationRepo,
        private readonly UserRepository $userRepo,
        private readonly EntityManagerInterface $em,
        private readonly PaginatorInterface $paginator,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly UserActionTokenService $tokenService,
        private readonly NotificationMailer $mailer,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $status = trim($request->query->getString('status', RegistrationRequest::STATUS_PENDING));

        $qb = $this->registrationRepo->createQueryBuilder('r')
            ->orderBy('r.createdAt', 'DESC');

        if ($status !== '') {
            $qb->andWhere('r.status = :status')->setParameter('status', $status);
        }

        return $this->render('admin/registration_request/index.html.twig', [
            'pagination' => $this->paginator->paginate($qb, $request->query->getInt('page', 1), 25),
            'status' => $status,
        ]);
    }

    #[Route('/{id}/approve', name: 'approve', methods: ['POST'])]
    public function approve(RegistrationRequest $registrationRequest, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('approve-registration-' . $registrationRequest->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if ($registrationRequest->getStatus() !== RegistrationRequest::STATUS_PENDING) {
            $this->addFlash('warning', 'This registration request has already been reviewed.');
            return $this->redirectToRoute('admin_registration_request_index');
        }

        $user = $this->userRepo->findOneBy(['email' => $registrationRequest->getEmail()]);
        $requiresPasswordSetup = false;

        if (!$user instanceof User) {
            $user = (new User())
                ->setEmail($registrationRequest->getEmail())
                ->setFullName($registrationRequest->getFullName())
                ->setRoles(['ROLE_ADMIN'])
                ->setIsActive(true);
            $user->setPassword($this->hasher->hashPassword($user, bin2hex(random_bytes(16))));
            $this->em->persist($user);
            $requiresPasswordSetup = true;
        } elseif (!$user->isSuperAdmin() && !$user->isAdmin()) {
            $user->setRoles(['ROLE_ADMIN']);
        }

        $user->setIsActive(true);

        if ($registrationRequest->getRequestedTenant() !== null) {
            $registrationRequest->getRequestedTenant()->addAdmin($user);
        }

        $registrationRequest
            ->setStatus(RegistrationRequest::STATUS_APPROVED)
            ->setReviewedAt(new \DateTimeImmutable())
            ->setReviewNotes(trim($request->request->getString('reviewNotes')) ?: null);

        $this->em->flush();

        $mailFailed = false;
        try {
            if ($requiresPasswordSetup) {
                $rawToken = $this->tokenService->issue($user, UserActionToken::PURPOSE_SET_PASSWORD, new \DateInterval('P1D'));
                $this->mailer->sendRegistrationApprovedWithPasswordSetup($user, $registrationRequest, $rawToken);
            } else {
                $this->mailer->sendRegistrationApprovedExistingUser($user, $registrationRequest);
            }
        } catch (\Throwable) {
            $mailFailed = true;
        }

        $this->addFlash($mailFailed ? 'warning' : 'success', $mailFailed
            ? 'Registration request approved, but the notification email could not be sent.'
            : 'Registration request approved.');
        return $this->redirectToRoute('admin_registration_request_index');
    }

    #[Route('/{id}/reject', name: 'reject', methods: ['POST'])]
    public function reject(RegistrationRequest $registrationRequest, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('reject-registration-' . $registrationRequest->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if ($registrationRequest->getStatus() !== RegistrationRequest::STATUS_PENDING) {
            $this->addFlash('warning', 'This registration request has already been reviewed.');
            return $this->redirectToRoute('admin_registration_request_index');
        }

        $registrationRequest
            ->setStatus(RegistrationRequest::STATUS_REJECTED)
            ->setReviewedAt(new \DateTimeImmutable())
            ->setReviewNotes(trim($request->request->getString('reviewNotes')) ?: null);

        $this->em->flush();

        $mailFailed = false;
        try {
            $this->mailer->sendRegistrationRejected($registrationRequest);
        } catch (\Throwable) {
            $mailFailed = true;
        }

        $this->addFlash($mailFailed ? 'warning' : 'success', $mailFailed
            ? 'Registration request rejected, but the rejection email could not be sent.'
            : 'Registration request rejected.');
        return $this->redirectToRoute('admin_registration_request_index');
    }
}
