<?php

declare(strict_types=1);

namespace App\Service;

final class MailerStatus
{
    public function __construct(
        private readonly string $mailerDsn,
    ) {}

    public function isEnabled(): bool
    {
        $dsn = strtolower(trim($this->mailerDsn));

        return $dsn !== '' && $dsn !== 'null://null';
    }
}
