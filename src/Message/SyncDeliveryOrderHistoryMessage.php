<?php

namespace App\Message;

final class SyncDeliveryOrderHistoryMessage
{
    public function __construct(
        public readonly string $dateFrom,
        public readonly string $dateTo,
    ) {
    }
}
