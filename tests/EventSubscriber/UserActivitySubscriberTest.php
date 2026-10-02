<?php

namespace App\Tests\EventSubscriber;

use App\Entity\User;
use App\EventSubscriber\UserActivitySubscriber;
use App\Repository\UserRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

final class UserActivitySubscriberTest extends TestCase
{
    public function testItRecordsAuthenticatedUserActivity(): void
    {
        $user = $this->createPersistedUser();
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $tokenStorage = $this->createStub(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);
        $repository = $this->createMock(UserRepository::class);
        $repository->expects(self::once())
            ->method('updateLastActivity')
            ->with(self::identicalTo($user), self::isInstanceOf(\DateTimeImmutable::class));

        $subscriber = new UserActivitySubscriber($tokenStorage, $repository);
        $subscriber->recordActivity($this->createRequestEvent());

        self::assertInstanceOf(\DateTimeImmutable::class, $user->getLastActivityAt());
    }

    public function testItDoesNotRewriteRecentActivity(): void
    {
        $user = $this->createPersistedUser()->setLastActivityAt(new \DateTimeImmutable('-30 seconds'));
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $tokenStorage = $this->createStub(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);
        $repository = $this->createMock(UserRepository::class);
        $repository->expects(self::never())->method('updateLastActivity');

        $subscriber = new UserActivitySubscriber($tokenStorage, $repository);
        $subscriber->recordActivity($this->createRequestEvent());
    }

    private function createPersistedUser(): User
    {
        $user = (new User())->setEmail('user@example.test');
        $id = new \ReflectionProperty(User::class, 'id');
        $id->setValue($user, 1);

        return $user;
    }

    private function createRequestEvent(): RequestEvent
    {
        return new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create('/supervision/users'),
            HttpKernelInterface::MAIN_REQUEST,
        );
    }
}
