<?php

namespace App\Tests\Command;

use App\Command\SyncDeliveryOrdersCommand;
use App\Message\SyncDeliveryOrderMessage;
use App\Service\Flux\SyncJobDispatcher;
use App\Service\Log\SyncLogService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class SyncDeliveryOrdersCommandTest extends TestCase
{
    public function testCommandQueuesNinetyDaysByDefault(): void
    {
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus
            ->expects(self::once())
            ->method('dispatch')
            ->with(
                self::callback(static function (object $message): bool {
                    return $message instanceof SyncDeliveryOrderMessage
                        && $message->dateFrom === (new \DateTimeImmutable('today'))->modify('-89 days')->format('Y-m-d')
                        && $message->dateTo === (new \DateTimeImmutable('today'))->format('Y-m-d');
                }),
                self::isArray(),
            )
            ->willReturnCallback(static fn (object $message, array $stamps): Envelope => new Envelope($message, $stamps));
        $logService = $this->createMock(SyncLogService::class);
        $logService->expects(self::once())->method('info');
        $tester = new CommandTester(new SyncDeliveryOrdersCommand(new SyncJobDispatcher($messageBus), $logService));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('90 derniers jours', $tester->getDisplay());
    }

    public function testCommandRejectsUnsupportedPeriod(): void
    {
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');
        $logService = $this->createMock(SyncLogService::class);
        $logService->expects(self::never())->method('info');
        $tester = new CommandTester(new SyncDeliveryOrdersCommand(new SyncJobDispatcher($messageBus), $logService));

        self::assertSame(Command::INVALID, $tester->execute(['--days' => '45']));
        self::assertStringContainsString('30, 60 ou 90', $tester->getDisplay());
    }
}
