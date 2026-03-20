<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FederationPolicyController extends AbstractController
{
    public function __construct(
        private readonly string $samlidpHostname,
        private readonly string $mailerFromAddress,
    ) {}

    #[Route('/federation/metadata-registration-practice-statement', name: 'federation_mrps', methods: ['GET'])]
    public function mrps(): Response
    {
        $host = htmlspecialchars($this->samlidpHostname, ENT_QUOTES);
        $contact = htmlspecialchars($this->mailerFromAddress, ENT_QUOTES);

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>UbuntuNet MRPS</title>
  <style>
    body { font-family: system-ui, sans-serif; line-height: 1.55; margin: 0; background: #f8fafc; color: #0f172a; }
    main { max-width: 860px; margin: 0 auto; padding: 48px 20px 72px; }
    h1, h2 { line-height: 1.2; }
    section { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 24px; margin-top: 20px; }
    code { background: #f1f5f9; padding: 2px 6px; border-radius: 6px; }
  </style>
</head>
<body>
<main>
  <h1>UbuntuNet Metadata Registration Practice Statement</h1>
  <p>This statement describes the rules and procedures used by the managed UbuntuNet Identity Provider service at <code>{$host}</code> when registering and publishing identity provider metadata for interfederation.</p>

  <section>
    <h2>Eligibility</h2>
    <p>Only institutions and organizations onboarded to the UbuntuNet managed IdP service are eligible for publication. Each tenant must have an identified administrative owner and operational contact before activation.</p>
  </section>

  <section>
    <h2>Validation</h2>
    <p>Before publication, tenant metadata is reviewed for correctness of entity identifiers, endpoints, organization details, contact information, certificate material, and claimed scopes. Scope values are expected to reflect domains legitimately controlled by the tenant organization.</p>
  </section>

  <section>
    <h2>Metadata Contents</h2>
    <p>The service publishes organization information, technical contacts, optional support and security contacts, discovery user interface metadata, and registration information. Metadata is signed and published by the federation operator where required for federation export.</p>
  </section>

  <section>
    <h2>Change Management</h2>
    <p>Material changes to endpoints, certificates, contact information, and scope claims are applied through the management portal or operator workflow and are subject to operational review before republication into federation aggregates.</p>
  </section>

  <section>
    <h2>Suspension and Removal</h2>
    <p>Metadata may be suspended or removed when an entity no longer meets eligibility requirements, presents invalid or misleading information, fails security review, or is operated in a way that threatens trust in the federation.</p>
  </section>

  <section>
    <h2>Contact</h2>
    <p>Questions regarding metadata registration and publication should be sent to <a href="mailto:{$contact}">{$contact}</a>.</p>
  </section>
</main>
</body>
</html>
HTML;

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
