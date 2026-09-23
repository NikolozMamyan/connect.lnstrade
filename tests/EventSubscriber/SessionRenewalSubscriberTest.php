<?php

namespace App\Tests\EventSubscriber;

use App\Entity\User;
use App\EventSubscriber\SessionRenewalSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MetadataBag;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

final class SessionRenewalSubscriberTest extends TestCase
{
    public function testItInvalidatesASessionAfterSixHoursOfInactivity(): void
    {
        $metadata = new MetadataBag();
        $storage = new MockArraySessionStorage('LNSSESSID', $metadata);
        $storage->setSessionData([
            $metadata->getStorageKey() => [
                MetadataBag::CREATED => time() - 22000,
                MetadataBag::UPDATED => time() - 21601,
                MetadataBag::LIFETIME => 21600,
            ],
        ]);
        $session = new Session($storage);
        $session->setId('existing-session-id');
        $request = Request::create('/', cookies: ['LNSSESSID' => 'existing-session-id']);
        $request->setSession($session);
        $subscriber = new SessionRenewalSubscriber($this->createStub(TokenStorageInterface::class), 21600);
        $event = new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        $subscriber->expireIdleSession($event);

        self::assertNotSame('existing-session-id', $session->getId());
    }

    public function testItKeepsAnActiveSession(): void
    {
        $metadata = new MetadataBag();
        $storage = new MockArraySessionStorage('LNSSESSID', $metadata);
        $storage->setSessionData([
            $metadata->getStorageKey() => [
                MetadataBag::CREATED => time() - 3600,
                MetadataBag::UPDATED => time() - 60,
                MetadataBag::LIFETIME => 21600,
            ],
        ]);
        $session = new Session($storage);
        $session->setId('active-session-id');
        $request = Request::create('/', cookies: ['LNSSESSID' => 'active-session-id']);
        $request->setSession($session);
        $subscriber = new SessionRenewalSubscriber($this->createStub(TokenStorageInterface::class), 21600);
        $event = new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        $subscriber->expireIdleSession($event);

        self::assertSame('active-session-id', $session->getId());
    }

    public function testItRenewsAnAuthenticatedSessionForSixHours(): void
    {
        $session = new Session(new MockArraySessionStorage('LNSSESSID'));
        $session->start();
        $request = Request::create('/');
        $request->setSession($session);
        $response = new Response();
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn((new User())->setEmail('user@example.test'));
        $tokenStorage = $this->createStub(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);
        $subscriber = new SessionRenewalSubscriber($tokenStorage, 21600);
        $event = new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );
        $beforeRenewal = time();

        $subscriber->renewAuthenticatedSession($event);

        $cookies = $response->headers->getCookies();
        self::assertCount(1, $cookies);
        self::assertSame('LNSSESSID', $cookies[0]->getName());
        self::assertSame($session->getId(), $cookies[0]->getValue());
        self::assertGreaterThanOrEqual($beforeRenewal + 21600, $cookies[0]->getExpiresTime());
        self::assertLessThanOrEqual(time() + 21600, $cookies[0]->getExpiresTime());
        self::assertTrue($cookies[0]->isHttpOnly());
        self::assertSame('lax', $cookies[0]->getSameSite());
    }

    public function testItDoesNotCreateACookieForAnAnonymousRequest(): void
    {
        $session = new Session(new MockArraySessionStorage('LNSSESSID'));
        $session->start();
        $request = Request::create('/login');
        $request->setSession($session);
        $tokenStorage = $this->createStub(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null);
        $subscriber = new SessionRenewalSubscriber($tokenStorage, 21600);
        $event = new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            new Response(),
        );

        $subscriber->renewAuthenticatedSession($event);

        self::assertSame([], $event->getResponse()->headers->getCookies());
    }
}
