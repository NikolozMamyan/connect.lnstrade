<?php

namespace App\MessageHandler;

use App\Message\SampleShipmentNotificationMessage;
use App\Service\SampleShipment\SampleShipmentService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SampleShipmentNotificationMessageHandler
{
    public function __construct(private readonly SampleShipmentService $shipmentService)
    {
    }

    public function __invoke(SampleShipmentNotificationMessage $message): void
    {
        $this->shipmentService->sendNotification($message->shipmentId);
    }
}
