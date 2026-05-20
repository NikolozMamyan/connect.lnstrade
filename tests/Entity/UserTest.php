<?php

namespace App\Tests\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

class UserTest extends TestCase
{
    public function testFullNameFallsBackToEmail(): void
    {
        $user = (new User())->setEmail('user@example.test');

        self::assertSame('user@example.test', $user->getFullName());
    }

    public function testFullNameAndRolesAreNormalized(): void
    {
        $user = (new User())
            ->setFirstName('Alice')
            ->setLastName('Martin')
            ->setEmail('ALICE@EXAMPLE.TEST')
            ->setRoles(['ROLE_ADMIN', 'ROLE_ADMIN']);

        self::assertSame('Alice Martin', $user->getFullName());
        self::assertSame('alice@example.test', $user->getEmail());
        self::assertSame(['ROLE_ADMIN', 'ROLE_USER'], $user->getRoles());
    }
}
