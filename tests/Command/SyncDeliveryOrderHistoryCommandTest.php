<?php

namespace App\Tests\Command;

use App\Command\SyncDeliveryOrderHistoryCommand;
use App\Message\SyncDeliveryOrderHistoryMessage;
use App\Service\Flux\SyncJobDispatcher;
use App\Service\Log\SyncLogService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class SyncDeliveryOrderHistoryCommandTest extends TestCase
{
    public function testCommandQueuesTheCompleteHistoryByDefault(): void
    {
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus
            ->expects(self::once())
            ->method('dispatch')
            ->with(
                self::callback(static function (object $message): bool {
                    return $message instanceof SyncDeliveryOrderHistoryMessage
                        && $message->dateFrom === '2025-01-01'
                        && $message->dateTo === (new \DateTimeImmutable('today'))->format('Y-m-d');
                }),
                self::isArray(),
            )
            ->willReturnCallback(static fn (object $message, array $stamps): Envelope => new Envelope($message, $stamps));
        $logService = $this->createMock(SyncLogService::class);
        $logService->expects(self::once())->method('info');
        $tester = new CommandTester(new SyncDeliveryOrderHistoryCommand(new SyncJobDispatcher($messageBus), $logService));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('01/01/2025', $tester->getDisplay());
        self::assertStringContainsString('Un seul recapitulatif', $tester->getDisplay());
    }

    public function testCommandRejectsAnInvalidStartDate(): void
    {
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');
        $logService = $this->createMock(SyncLogService::class);
        $logService->expects(self::never())->method('info');
        $tester = new CommandTester(new SyncDeliveryOrderHistoryCommand(new SyncJobDispatcher($messageBus), $logService));

        self::assertSame(Command::INVALID, $tester->execute(['--from' => '24-09-2020']));
        self::assertStringContainsString('AAAA-MM-JJ', $tester->getDisplay());
    }
}
