<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Message\RefreshAllMetadataMessage;
use App\Message\CheckCertificateExpiryMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule('default')]
class MainSchedule implements ScheduleProviderInterface
{
    public function __construct(
        private readonly CacheInterface $cache,
    ) {}

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->stateful($this->cache)
            ->add(
                // Refresh all SP metadata every 4 hours
                RecurringMessage::every('4 hours', new RefreshAllMetadataMessage())
            )
            ->add(
                // Check certificate expiry daily at 06:00
                RecurringMessage::cron('0 6 * * *', new CheckCertificateExpiryMessage())
            )
            ->add(
                // Regenerate all SSP configs every 30 minutes (catches any drift)
                RecurringMessage::every('30 minutes', new \App\Message\RegenerateAllConfigsMessage())
            );
    }
}
