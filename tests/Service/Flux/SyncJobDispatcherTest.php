<?php

namespace App\Tests\Service\Flux;

use App\Message\SyncClientMessage;
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
}
