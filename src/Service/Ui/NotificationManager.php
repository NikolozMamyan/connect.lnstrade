<?php

namespace App\Service\Ui;

use App\Entity\Notification;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class NotificationManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param array<string, mixed> $routeParams
     */
    public function notify(
        string $title,
        ?string $message = null,
        string $level = Notification::LEVEL_INFO,
        ?string $routeName = null,
        array $routeParams = [],
    ): Notification {
        $notification = (new Notification())
            ->setTitle($title)
            ->setMessage($message)
            ->setLevel($level);

        if ($routeName !== null) {
            $notification->setLinkUrl($this->urlGenerator->generate($routeName, $routeParams));
        }

        $this->entityManager->persist($notification);
        $this->entityManager->flush();

        return $notification;
    }
}
