<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\MailerStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class SecurityController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MailerStatus $mailerStatus,
    ) {}

    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('admin_dashboard');
        }

        return $this->render('security/login.html.twig', [
            'last_username' => $authUtils->getLastUsername(),
            'error'         => $authUtils->getLastAuthenticationError(),
            'mailer_enabled' => $this->mailerStatus->isEnabled(),
        ]);
    }

    #[Route('/logout', name: 'app_logout', methods: ['POST'])]
    public function logout(): never
    {
        // Symfony intercepts this
        throw new \LogicException('This method should never be reached.');
    }

    #[Route('/profile', name: 'app_profile')]
    public function profile(#[CurrentUser] User $user): Response
    {
        return $this->render('security/profile.html.twig', ['user' => $user]);
    }
}
