<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class UserActivitySubscriber implements EventSubscriberInterface
{
    private const UPDATE_INTERVAL = 60;

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly UserRepository $userRepository,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['recordActivity', -10],
        ];
    }

    public function recordActivity(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->tokenStorage->getToken()?->getUser();

        if (!$user instanceof User || null === $user->getId()) {
            return;
        }

        $now = new \DateTimeImmutable();
        $lastActivityAt = $user->getLastActivityAt();

        if (null !== $lastActivityAt && $lastActivityAt >= $now->modify(sprintf('-%d seconds', self::UPDATE_INTERVAL))) {
            return;
        }

        $this->userRepository->updateLastActivity($user, $now);
        $user->setLastActivityAt($now);
    }
}
