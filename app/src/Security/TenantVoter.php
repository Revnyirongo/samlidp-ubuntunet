<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Tenant;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Controls per-tenant access.
 *
 * Attributes:
 *  - TENANT_VIEW  : can see tenant details
 *  - TENANT_EDIT  : can edit tenant + import SPs
 *  - TENANT_ADMIN : can manage tenant admins
 */
class TenantVoter extends Voter
{
    public const VIEW  = 'TENANT_VIEW';
    public const EDIT  = 'TENANT_EDIT';
    public const ADMIN = 'TENANT_ADMIN';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::ADMIN], true)
            && $subject instanceof Tenant;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        // Super-admins can do everything
        if ($user->isSuperAdmin()) {
            return true;
        }

        /** @var Tenant $tenant */
        $tenant = $subject;

        return match ($attribute) {
            self::VIEW  => $user->canManageTenant($tenant),
            self::EDIT  => $user->canManageTenant($tenant),
            self::ADMIN => false, // Only super-admins manage tenant admins
            default     => false,
        };
    }
}
