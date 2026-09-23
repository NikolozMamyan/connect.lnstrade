<?php

namespace App\Tests\Service\Flux;

use App\Message\SyncClientMessage;
use App\Message\SyncDeliveryOrderMessage;
use App\Service\Flux\SyncJobDispatcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;

final class SyncJobDispatcherTest extends TestCase
{
    public function testDispatchAddsAStableDeduplicationStamp(): void
    {
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus
            ->expects(self::once())
            ->method('dispatch')
            ->with(
                self::isInstanceOf(SyncClientMessage::class),
                self::callback(static function (array $stamps): bool {
                    $stamp = $stamps[0] ?? null;

                    return $stamp instanceof DeduplicateStamp
                        && (string) $stamp->getKey() === 'queued-sync-client'
                        && $stamp->getTtl() === 14400.0;
                })
            )
            ->willReturnCallback(static fn (object $message, array $stamps): Envelope => new Envelope($message, $stamps));

        (new SyncJobDispatcher($messageBus))->dispatch(SyncJobDispatcher::CLIENT);
    }

    public function testUnknownTypeIsRejected(): void
    {
        $dispatcher = new SyncJobDispatcher($this->createStub(MessageBusInterface::class));

        $this->expectException(\InvalidArgumentException::class);
        $dispatcher->dispatch('unknown');
    }

    public function testDeliveryOrderDispatchCarriesTheRequestedPeriod(): void
    {
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus
            ->expects(self::once())
            ->method('dispatch')
            ->with(
                self::callback(static function (object $message): bool {
                    return $message instanceof SyncDeliveryOrderMessage
                        && $message->dateFrom === '2026-09-01'
                        && $message->dateTo === '2026-09-23';
                }),
                self::callback(static function (array $stamps): bool {
                    $stamp = $stamps[0] ?? null;

                    return $stamp instanceof DeduplicateStamp
                        && (string) $stamp->getKey() === 'queued-sync-delivery-order-20260901-20260923'
                        && $stamp->getTtl() === 3600.0;
                }),
            )
            ->willReturnCallback(static fn (object $message, array $stamps): Envelope => new Envelope($message, $stamps));

        (new SyncJobDispatcher($messageBus))->dispatch(
            SyncJobDispatcher::DELIVERY_ORDER,
            new \DateTimeImmutable('2026-09-01'),
            new \DateTimeImmutable('2026-09-23'),
        );
    }
}
