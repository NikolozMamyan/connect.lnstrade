<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class SessionRenewalSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly int $sessionLifetime,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['expireIdleSession', 9],
            KernelEvents::RESPONSE => ['renewAuthenticatedSession', -900],
        ];
    }

    public function expireIdleSession(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!$request->hasSession()) {
            return;
        }

        $session = $request->getSession();

        if (!$request->cookies->has($session->getName())) {
            return;
        }

        $session->start();

        if (time() - $session->getMetadataBag()->getLastUsed() > $this->sessionLifetime) {
            $session->invalidate();
        }
    }

    public function renewAuthenticatedSession(ResponseEvent $event): void
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        if (!$event->isMainRequest() || !$user instanceof User) {
            return;
        }

        $request = $event->getRequest();

        if (!$request->hasSession(true)) {
            return;
        }

        $session = $request->getSession();

        if (!$session->isStarted() || $session->getId() === '') {
            return;
        }

        $cookieParameters = session_get_cookie_params();
        $event->getResponse()->headers->setCookie(Cookie::create(
            $session->getName(),
            $session->getId(),
            time() + $this->sessionLifetime,
            (string) ($cookieParameters['path'] ?: '/'),
            $cookieParameters['domain'] !== '' ? (string) $cookieParameters['domain'] : null,
            (bool) $cookieParameters['secure'] || $request->isSecure(),
            true,
            false,
            Cookie::SAMESITE_LAX,
        ));
    }
}
