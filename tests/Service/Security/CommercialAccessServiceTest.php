<?php

namespace App\Tests\Service\Security;

use App\Entity\Commercial;
use App\Entity\User;
use App\Repository\CommercialRepository;
use App\Service\Security\CommercialAccessService;
use PHPUnit\Framework\TestCase;

class CommercialAccessServiceTest extends TestCase
{
    public function testDetectsCommercialUserWithoutAdminRole(): void
    {
        $service = new CommercialAccessService($this->createStub(CommercialRepository::class));
        $user = (new User())->setEmail('com@example.test')->setRoles(['ROLE_COM']);

        self::assertTrue($service->isCommercialUser($user));
    }

    public function testDoesNotTreatAdminAsRestrictedCommercialUser(): void
    {
        $service = new CommercialAccessService($this->createStub(CommercialRepository::class));
        $user = (new User())->setEmail('admin@example.test')->setRoles(['ROLE_ADMIN', 'ROLE_COM']);

        self::assertFalse($service->isCommercialUser($user));
    }

    public function testResolveCommercialMatchesActiveCommercialByEmail(): void
    {
        $commercial = (new Commercial())
            ->setFirstName('Quentin')
            ->setLastName('Strasser')
            ->setEmail('quentin.strasser@lnstrade.fr');

        $repository = $this->createMock(CommercialRepository::class);
        $repository->expects(self::once())
            ->method('findOneBy')
            ->with([
                'email' => 'quentin.strasser@lnstrade.fr',
                'isActive' => true,
            ])
            ->willReturn($commercial);

        $service = new CommercialAccessService($repository);
        $user = (new User())
            ->setEmail('QUENTIN.STRASSER@LNSTRADE.FR')
            ->setRoles(['ROLE_COM']);

        self::assertSame($commercial, $service->resolveCommercial($user));
    }
}
