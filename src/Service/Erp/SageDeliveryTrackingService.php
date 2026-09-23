<?php

namespace App\Service\Erp;

use App\Repository\HubspotCompanyRepository;

final class SageDeliveryTrackingService
{
    private const SALES_DOMAIN = 0;
    private const DOCUMENT_TYPES = [1, 3, 6, 7];

    public function __construct(
        private readonly SageClient $sageClient,
        private readonly HubspotCompanyRepository $companyRepository,
    ) {
    }

    /**
     * @return array{
     *   rows: list<array<string, mixed>>,
     *   summary: array{total: int, bc: int, bl: int, fc: int},
     *   fetchedAt: \DateTimeImmutable
     * }
     */
    public function getTracking(
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        ?string $representative = null,
    ): array {
        $rowsByPiece = [];

        foreach (self::DOCUMENT_TYPES as $type) {
            $query = [
                'domaine' => self::SALES_DOMAIN,
                'type' => $type,
                'dateDebut' => $dateFrom->format('Y-m-d'),
                'dateFin' => $dateTo->format('Y-m-d'),
            ];

            if ($representative !== null && trim($representative) !== '') {
                $query['representant'] = trim($representative);
            }

            foreach ($this->extractList($this->sageClient->get('/Document/header', $query)) as $header) {
                $piece = trim((string) ($header['piece'] ?? ''));

                if ($piece === '' || !$this->isTrackableDocument($type, $piece)) {
                    continue;
                }

                $row = $this->normalizeDocument($header, $type);
                $existing = $rowsByPiece[$piece] ?? null;

                if (!is_array($existing) || (int) $row['sourceType'] > (int) $existing['sourceType']) {
                    $rowsByPiece[$piece] = $row;
                }
            }
        }

        $rows = array_values($rowsByPiece);
        $clientNames = $this->companyRepository->findNamesIndexedByErpIds(array_column($rows, 'clientId'));

        foreach ($rows as &$row) {
            if ($row['clientName'] === '') {
                $row['clientName'] = $clientNames[$row['clientId']] ?? $row['clientId'];
            }
        }
        unset($row);

        usort($rows, static function (array $left, array $right): int {
            $dateComparison = strcmp((string) $right['updatedSort'], (string) $left['updatedSort']);

            return $dateComparison !== 0 ? $dateComparison : strcmp((string) $right['piece'], (string) $left['piece']);
        });

        $summary = ['total' => count($rows), 'bc' => 0, 'bl' => 0, 'fc' => 0];

        foreach ($rows as $row) {
            ++$summary[$row['stage']];
        }

        return [
            'rows' => $rows,
            'summary' => $summary,
            'fetchedAt' => new \DateTimeImmutable(),
        ];
    }

    private function isTrackableDocument(int $type, string $piece): bool
    {
        if ($type === 1) {
            return str_starts_with(strtoupper($piece), 'BC');
        }

        if ($type === 3) {
            return str_starts_with(strtoupper($piece), 'BL');
        }

        return str_starts_with(strtoupper($piece), 'FA');
    }

    /**
     * @param array<string, mixed> $header
     *
     * @return array<string, mixed>
     */
    private function normalizeDocument(array $header, int $type): array
    {
        $stage = match ($type) {
            1 => 'bc',
            3 => 'bl',
            default => 'fc',
        };
        $stageRank = ['bc' => 1, 'bl' => 2, 'fc' => 3][$stage];
        $freeFields = isset($header['champsLibres']) && is_array($header['champsLibres']) ? $header['champsLibres'] : [];
        $documentDate = $this->toDate($header['date'] ?? null);
        $createdAt = $this->toDate($freeFields['Date de création'] ?? null) ?? $documentDate;
        $updatedAt = $this->latestDate([
            $documentDate,
            $createdAt,
            $this->toDate($freeFields['Date de préparation'] ?? null),
            $this->toDate($freeFields['Date paiement'] ?? null),
            $this->toDate($freeFields['Date validation pro forma'] ?? null),
        ]);
        $owner = trim((string) ($header['representant'] ?? ''));

        return [
            'piece' => trim((string) $header['piece']),
            'reference' => trim((string) ($header['reference'] ?? '')),
            'clientId' => trim((string) ($header['tiers'] ?? '')),
            'clientName' => trim((string) ($freeFields['nomtiers'] ?? '')),
            'owner' => $owner !== '' ? $owner : 'Non assigné',
            'ownerInitials' => $this->initials($owner),
            'createdAt' => $createdAt,
            'updatedAt' => $updatedAt,
            'updatedSort' => $updatedAt?->format(\DateTimeInterface::ATOM) ?? '',
            'stage' => $stage,
            'stageRank' => $stageRank,
            'stageLabel' => match ($stage) {
                'bc' => 'Commande générée',
                'bl' => 'En préparation',
                default => 'Expédiée',
            },
            'sourceType' => $type,
            'sageStatus' => trim((string) ($header['statut'] ?? '')),
        ];
    }

    /**
     * @param list<?\DateTimeImmutable> $dates
     */
    private function latestDate(array $dates): ?\DateTimeImmutable
    {
        $dates = array_values(array_filter($dates, static fn (mixed $date): bool => $date instanceof \DateTimeImmutable));

        if ($dates === []) {
            return null;
        }

        usort(
            $dates,
            static fn (\DateTimeImmutable $left, \DateTimeImmutable $right): int => $right->getTimestamp() <=> $left->getTimestamp()
        );

        return $dates[0];
    }

    private function toDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function initials(string $value): string
    {
        $parts = preg_split('/\s+/', trim($value)) ?: [];
        $parts = array_values(array_filter($parts));

        if ($parts === []) {
            return 'NA';
        }

        $first = mb_substr((string) $parts[0], 0, 1);
        $last = count($parts) > 1 ? mb_substr((string) $parts[array_key_last($parts)], 0, 1) : '';

        return mb_strtoupper($first.$last);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractList(array $response): array
    {
        $items = isset($response['results']) && is_array($response['results']) ? $response['results'] : $response;

        return array_values(array_filter($items, 'is_array'));
    }
}
