<?php

namespace App\Tests\Service\SampleShipment;

use App\Service\SampleShipment\SampleShipmentAccessService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class SampleShipmentAccessServiceTest extends TestCase
{
    public function testBothAppsUseTheSamePinWithSeparateScopedCookies(): void
    {
        $samples = new SampleShipmentAccessService('123456', 'secret');
        $consumption = new SampleShipmentAccessService('123456', 'secret', 'lns_consumption_access', '/consommation', 'lnstrade-internal-consumption');
        self::assertTrue($samples->verifyPin('123456'));
        self::assertTrue($consumption->verifyPin('123456'));
        self::assertFalse($consumption->verifyPin('000000'));
        $request = Request::create('https://connect.example/consommation');
        $sampleCookie = $samples->createCookie($request);
        $consoCookie = $consumption->createCookie($request);
        self::assertSame('/echantillons', $sampleCookie->getPath());
        self::assertSame('/consommation', $consoCookie->getPath());
        self::assertTrue($consoCookie->isSecure());
        self::assertTrue($consoCookie->isHttpOnly());
        self::assertSame('strict', $consoCookie->getSameSite());
        self::assertNotSame($sampleCookie->getValue(), $consoCookie->getValue());
        $request->cookies->set($consoCookie->getName(), $consoCookie->getValue());
        self::assertTrue($consumption->isGranted($request));
        self::assertFalse($samples->isGranted($request));
        $request->cookies->set($consoCookie->getName(), $sampleCookie->getValue());
        self::assertFalse($consumption->isGranted($request));
        $request->cookies->set($sampleCookie->getName(), $sampleCookie->getValue());
        self::assertTrue($samples->isGranted($request));
    }
}
