<?php

namespace App\Service\Flux;

use App\Message\SyncClientMessage;
use App\Message\SyncInvoiceMessage;
use App\Message\SyncProductMessage;
use App\Message\SyncProductStockMessage;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;

final class SyncJobDispatcher
{
    public const CLIENT = 'client';
    public const PRODUCT = 'product';
    public const PRODUCT_STOCK = 'product-stock';
    public const INVOICE = 'invoice';

    private const TYPES = [
        self::CLIENT,
        self::PRODUCT,
        self::PRODUCT_STOCK,
        self::INVOICE,
    ];

    private const DEDUPLICATION_TTL = [
        self::CLIENT => 14400,
        self::PRODUCT => 14400,
        self::PRODUCT_STOCK => 7200,
        self::INVOICE => 3600,
    ];

    public function __construct(private readonly MessageBusInterface $messageBus)
    {
    }

    /**
     * @return list<string>
     */
    public static function supportedTypes(): array
    {
        return self::TYPES;
    }

    public function dispatch(string $type): void
    {
        $message = match ($type) {
            self::CLIENT => new SyncClientMessage(),
            self::PRODUCT => new SyncProductMessage(),
            self::PRODUCT_STOCK => new SyncProductStockMessage(),
            self::INVOICE => new SyncInvoiceMessage(),
            default => throw new \InvalidArgumentException(sprintf('Type de synchronisation inconnu : %s', $type)),
        };

        $this->messageBus->dispatch($message, [
            new DeduplicateStamp('queued-sync-'.$type, self::DEDUPLICATION_TTL[$type]),
        ]);
    }
}
