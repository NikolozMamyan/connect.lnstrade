<?php

namespace App\Service\Log;

use App\Entity\SyncLog;
use Doctrine\ORM\EntityManagerInterface;

class SyncLogService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function info(string $fluxKey, string $title, ?string $message = null, ?array $context = null): void
    {
        $this->write($fluxKey, 'info', $title, $message, $context);
    }

    public function success(string $fluxKey, string $title, ?string $message = null, ?array $context = null): void
    {
        $this->write($fluxKey, 'success', $title, $message, $context);
    }

    public function warning(string $fluxKey, string $title, ?string $message = null, ?array $context = null): void
    {
        $this->write($fluxKey, 'warning', $title, $message, $context);
    }

    public function error(string $fluxKey, string $title, ?string $message = null, ?array $context = null): void
    {
        $this->write($fluxKey, 'error', $title, $message, $context);
    }

    private function write(string $fluxKey, string $level, string $title, ?string $message, ?array $context): void
    {
        $log = new SyncLog();
        $log
            ->setFluxKey($fluxKey)
            ->setLevel($level)
            ->setTitle($title)
            ->setMessage($message)
            ->setContext($context);

        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }
}
