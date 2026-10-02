<?php

namespace App\Tests\Service\Erp;

use App\Service\Erp\SageApiRateLimiter;
use App\Service\Erp\SageClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class SageClientRateLimitTest extends TestCase
{
    public function testEveryCallIsRegisteredByTheRateLimiter(): void
    {
        $cache = new ArrayAdapter();
        $limiter = new SageApiRateLimiter(
            $cache,
            new LockFactory(new InMemoryStore()),
            55,
            60,
        );

        $limiter->acquire();
        $limiter->acquire();

        $state = $cache->getItem('sage_api_rate_limit_state')->get();

        self::assertCount(2, $state['timestamps']);
        self::assertSame(0.0, $state['blocked_until']);
    }

    public function testClientRetriesAfterSageReturnsTooManyRequests(): void
    {
        $requestCount = 0;
        $httpClient = new MockHttpClient(static function () use (&$requestCount): MockResponse {
            ++$requestCount;

            return match ($requestCount) {
                1 => new MockResponse('{"accessToken":"sage-token"}'),
                2 => new MockResponse('API calls quota exceeded!', [
                    'http_code' => 429,
                    'response_headers' => ['retry-after' => '2'],
                ]),
                default => new MockResponse('{"documents":[{"number":"BL001"}]}'),
            };
        });

        $limiter = $this->createMock(SageApiRateLimiter::class);
        $limiter->expects(self::exactly(3))->method('acquire');
        $limiter->expects(self::once())->method('blockFor')->with(2);

        $client = new SageClient($httpClient, new ParameterBag([
            'base_uri_sage' => 'https://sage.test',
            'sage_username' => 'user',
            'sage_password' => 'password',
        ]), $limiter);

        self::assertSame(
            ['documents' => [['number' => 'BL001']]],
            $client->get('/documents')
        );
        self::assertSame(3, $requestCount);
    }
}
