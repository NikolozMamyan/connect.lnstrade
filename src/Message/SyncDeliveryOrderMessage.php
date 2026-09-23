<?php

namespace App\Message;

final class SyncDeliveryOrderMessage
{
    public function __construct(
        public readonly string $dateFrom,
        public readonly string $dateTo,
    ) {
    }
}
