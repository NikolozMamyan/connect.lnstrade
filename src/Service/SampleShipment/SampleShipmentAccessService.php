<?php

namespace App\Service\SampleShipment;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

final class SampleShipmentAccessService
{
    public const COOKIE_NAME = 'lns_sample_access';

    public function __construct(
        private readonly string $pin,
        private readonly string $appSecret,
    ) {
    }

    public function isGranted(Request $request): bool
    {
        $cookie = (string) $request->cookies->get(self::COOKIE_NAME, '');

        return $cookie !== '' && hash_equals($this->accessToken(), $cookie);
    }

    public function verifyPin(string $pin): bool
    {
        return $this->pin !== '' && hash_equals($this->pin, trim($pin));
    }

    public function createCookie(Request $request): Cookie
    {
        return Cookie::create(self::COOKIE_NAME)
            ->withValue($this->accessToken())
            ->withExpires(new \DateTimeImmutable('+30 days'))
            ->withPath('/echantillons')
            ->withSecure($request->isSecure())
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_STRICT);
    }

    private function accessToken(): string
    {
        $secret = $this->appSecret !== '' ? $this->appSecret : $this->pin;

        return hash_hmac('sha256', 'lnstrade-sample-shipment|'.$this->pin, $secret);
    }
}
