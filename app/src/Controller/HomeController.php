<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\MailerStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    public function __construct(
        private readonly MailerStatus $mailerStatus,
    ) {}

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('home/index.html.twig', [
            'mailer_enabled' => $this->mailerStatus->isEnabled(),
            'docs_url' => 'https://gitlab.ubuntunet.net/',
        ]);
    }
}
