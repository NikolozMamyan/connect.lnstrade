<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Service\Security\CommercialAccessService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class CommercialRouteSubscriber implements EventSubscriberInterface
{
    private const ALLOWED_ROUTE_PREFIXES = [
        'app_dashboard',
        'supervision_statistics_',
        'supervision_settings_',
    ];

    private const ALLOWED_ROUTE_NAMES = [
        'app_index',
        'app_login',
        'app_logout',
    ];

    public function __construct(
        private readonly CommercialAccessService $commercialAccessService,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onKernelRequest',
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $routeName = (string) $request->attributes->get('_route', '');
        $user = $this->tokenStorage->getToken()?->getUser();

        if (!$user instanceof User || !$this->commercialAccessService->isCommercialUser($user)) {
            return;
        }

        if ($routeName === '' || in_array($routeName, self::ALLOWED_ROUTE_NAMES, true)) {
            return;
        }

        foreach (self::ALLOWED_ROUTE_PREFIXES as $prefix) {
            if (str_starts_with($routeName, $prefix)) {
                return;
            }
        }

        throw new AccessDeniedHttpException('Acces reserve a votre perimetre commercial.');
    }
}
