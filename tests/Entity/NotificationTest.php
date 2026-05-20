<?php

namespace App\Tests\Entity;

use App\Entity\Notification;
use PHPUnit\Framework\TestCase;

class NotificationTest extends TestCase
{
    public function testNotificationDefaultsToUnread(): void
    {
        $notification = (new Notification())
            ->setTitle('Alerte')
            ->setMessage('Un evenement vient de se produire.');

        self::assertFalse($notification->isRead());
        self::assertSame(Notification::LEVEL_INFO, $notification->getLevel());
        self::assertNotNull($notification->getCreatedAt());
    }

    public function testNotificationLevelIsNormalized(): void
    {
        $notification = (new Notification())
            ->setTitle('Alerte')
            ->setLevel('custom')
            ->markAsRead();

        self::assertSame(Notification::LEVEL_INFO, $notification->getLevel());
        self::assertTrue($notification->isRead());
    }
}
