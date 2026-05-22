<?php

namespace App\Service\Security;

use App\Entity\Commercial;
use App\Entity\User;
use App\Repository\CommercialRepository;

final class CommercialAccessService
{
    public function __construct(
        private readonly CommercialRepository $commercialRepository,
    ) {
    }

    public function isCommercialUser(?User $user): bool
    {
        if (!$user instanceof User) {
            return false;
        }

        $roles = $user->getRoles();

        return in_array('ROLE_COM', $roles, true) && !in_array('ROLE_ADMIN', $roles, true);
    }

    public function resolveCommercial(?User $user): ?Commercial
    {
        if (!$this->isCommercialUser($user)) {
            return null;
        }

        $email = trim((string) $user->getEmail());

        if ($email === '') {
            return null;
        }

        return $this->commercialRepository->findOneBy([
            'email' => mb_strtolower($email),
            'isActive' => true,
        ]);
    }
}
