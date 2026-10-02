<?php

namespace App\Service\Erp;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\CacheItemInterface;
use Symfony\Component\Lock\LockFactory;

class SageApiRateLimiter
{
    private const CACHE_KEY = 'sage_api_rate_limit_state';
    private const LOCK_KEY = 'sage_api_rate_limit';

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly LockFactory $lockFactory,
        private readonly int $maxCalls,
        private readonly int $intervalSeconds,
    ) {
    }

    public function acquire(): void
    {
        while (true) {
            $waitSeconds = 0.0;
            $lock = $this->lockFactory->createLock(self::LOCK_KEY, 10.0);
            $lock->acquire(true);

            try {
                $now = microtime(true);
                $item = $this->cache->getItem(self::CACHE_KEY);
                $state = $this->readState($item->isHit() ? $item->get() : null);
                $state['timestamps'] = array_values(array_filter(
                    $state['timestamps'],
                    fn (float $timestamp): bool => $timestamp > $now - $this->intervalSeconds
                ));

                if ($state['blocked_until'] > $now) {
                    $waitSeconds = $state['blocked_until'] - $now;
                } elseif (count($state['timestamps']) >= $this->maxCalls) {
                    $waitSeconds = $state['timestamps'][0] + $this->intervalSeconds - $now + 0.1;
                } else {
                    $state['timestamps'][] = $now;
                    $this->saveState($item, $state);

                    return;
                }

                $this->saveState($item, $state);
            } finally {
                $lock->release();
            }

            usleep(max(1, (int) ceil($waitSeconds * 1_000_000)));
        }
    }

    public function blockFor(int $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        $lock = $this->lockFactory->createLock(self::LOCK_KEY, 10.0);
        $lock->acquire(true);

        try {
            $item = $this->cache->getItem(self::CACHE_KEY);
            $state = $this->readState($item->isHit() ? $item->get() : null);
            $state['blocked_until'] = max($state['blocked_until'], microtime(true) + $seconds);
            $this->saveState($item, $state, $seconds);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{timestamps: list<float>, blocked_until: float}
     */
    private function readState(mixed $value): array
    {
        if (!\is_array($value)) {
            return ['timestamps' => [], 'blocked_until' => 0.0];
        }

        $timestamps = array_values(array_filter(
            $value['timestamps'] ?? [],
            static fn (mixed $timestamp): bool => \is_float($timestamp) || \is_int($timestamp)
        ));

        return [
            'timestamps' => array_map(static fn (float|int $timestamp): float => (float) $timestamp, $timestamps),
            'blocked_until' => (float) ($value['blocked_until'] ?? 0.0),
        ];
    }

    /**
     * @param array{timestamps: list<float>, blocked_until: float} $state
     */
    private function saveState(CacheItemInterface $item, array $state, int $extraLifetime = 0): void
    {
        $item->set($state);
        $item->expiresAfter($this->intervalSeconds + $extraLifetime + 60);
        $this->cache->save($item);
    }
}
