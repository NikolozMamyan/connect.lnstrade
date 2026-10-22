<?php

namespace App\Message;

final readonly class SampleShipmentNotificationMessage
{
    public function __construct(public int $shipmentId)
    {
    }
}
