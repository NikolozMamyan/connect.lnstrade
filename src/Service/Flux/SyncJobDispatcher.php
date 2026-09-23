<?php

namespace App\Service\Flux;

use App\Message\SyncClientMessage;
use App\Message\SyncDeliveryOrderMessage;
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
    public const DELIVERY_ORDER = 'delivery-order';

    private const TYPES = [
        self::CLIENT,
        self::PRODUCT,
        self::PRODUCT_STOCK,
        self::INVOICE,
        self::DELIVERY_ORDER,
    ];

    private const DEDUPLICATION_TTL = [
        self::CLIENT => 14400,
        self::PRODUCT => 14400,
        self::PRODUCT_STOCK => 7200,
        self::INVOICE => 3600,
        self::DELIVERY_ORDER => 3600,
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

    public function dispatch(
        string $type,
        ?\DateTimeImmutable $dateFrom = null,
        ?\DateTimeImmutable $dateTo = null,
    ): void
    {
        $dateTo ??= new \DateTimeImmutable('today');
        $dateFrom ??= $dateTo->modify('-30 days');
        $message = match ($type) {
            self::CLIENT => new SyncClientMessage(),
            self::PRODUCT => new SyncProductMessage(),
            self::PRODUCT_STOCK => new SyncProductStockMessage(),
            self::INVOICE => new SyncInvoiceMessage(),
            self::DELIVERY_ORDER => new SyncDeliveryOrderMessage(
                $dateFrom->format('Y-m-d'),
                $dateTo->format('Y-m-d'),
            ),
            default => throw new \InvalidArgumentException(sprintf('Type de synchronisation inconnu : %s', $type)),
        };

        $deduplicationKey = 'queued-sync-'.$type;

        if ($type === self::DELIVERY_ORDER) {
            $deduplicationKey .= sprintf('-%s-%s', $dateFrom->format('Ymd'), $dateTo->format('Ymd'));
        }

        $this->messageBus->dispatch($message, [
            new DeduplicateStamp($deduplicationKey, self::DEDUPLICATION_TTL[$type]),
        ]);
    }
}
