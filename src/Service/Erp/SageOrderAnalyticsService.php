<?php

namespace App\Service\Erp;

final class SageOrderAnalyticsService
{
    /**
     * @var list<array{hubspotId: string, firstName: string, lastName: string, email: string}>
     */
    private const ALLOWED_COMMERCIALS = [
        ['hubspotId' => '78020060', 'firstName' => 'Quentin', 'lastName' => 'Strasser', 'email' => 'quentin.strasser@lnstrade.fr'],
        ['hubspotId' => '65156164', 'firstName' => 'Douglas', 'lastName' => 'Woods', 'email' => 'douglas.woods@lnstrade.fr'],
        ['hubspotId' => '65155850', 'firstName' => 'Cyril', 'lastName' => 'Motz', 'email' => 'cyril.motz@lnstrade.fr'],
        ['hubspotId' => '65524033', 'firstName' => 'Savinien', 'lastName' => 'Saint Paul', 'email' => 'savinien.saint-paul@lnstrade.fr'],
        ['hubspotId' => '65157022', 'firstName' => 'Corentin', 'lastName' => 'BURY', 'email' => 'corentin.bury@lnstrade.fr'],
        ['hubspotId' => '77839925', 'firstName' => 'Enzo', 'lastName' => 'Houdé', 'email' => 'enzo.houde@lnstrade.fr'],
        ['hubspotId' => '78818212', 'firstName' => 'Jerome', 'lastName' => 'Degreve', 'email' => 'jerome.degreve@lnstrade.fr'],
        ['hubspotId' => '29391503', 'firstName' => 'Vincent', 'lastName' => 'TOUATI', 'email' => 'vincent.touati@lnstrade.fr'],
        ['hubspotId' => '65669769', 'firstName' => 'Anthony', 'lastName' => 'Chaoui', 'email' => 'anthony.chaoui@lnstrade.fr'],
    ];

    public function __construct(
        private readonly SageClient $sageClient,
    ) {
    }

    public function getStatistics(array $filters = []): array
    {
        $normalizedFilters = $this->normalizeFilters($filters);
        $headers = $this->sageClient->get('/Document/header', $this->buildHeaderQuery($normalizedFilters));
        $dashboard = $this->buildDashboard($headers, $normalizedFilters['group_by'], $normalizedFilters['table_limit']);

        $selectedOrder = null;
        $selectedLines = [];

        if ($normalizedFilters['selected_piece'] !== '') {
            $selectedOrder = $this->findOrderByPiece($dashboard['allOrders'], $normalizedFilters['selected_piece']);
            $selectedLines = $this->fetchOrderLines($normalizedFilters['selected_piece']);
        }

        return [
            'filters' => $normalizedFilters,
            'summary' => $dashboard['summary'],
            'charts' => $dashboard['charts'],
            'topCommercials' => $dashboard['topCommercials'],
            'representants' => $dashboard['representants'],
            'recentOrders' => $dashboard['recentOrders'],
            'selectedOrder' => $selectedOrder,
            'selectedLines' => $selectedLines,
            'totalOrders' => count($dashboard['allOrders']),
        ];
    }

    public function buildDashboard(array $headers, string $groupBy = 'month', int $tableLimit = 15): array
    {
        $orders = array_values(array_filter(array_map(
            fn (array $header): ?array => $this->normalizeHeader($header),
            $headers
        )));

        usort($orders, static function (array $left, array $right): int {
            return strcmp((string) $right['date_sort'], (string) $left['date_sort']);
        });

        $totalRevenue = 0.0;
        $validatedRevenue = 0.0;
        $unpaidTotal = 0.0;
        $statusCounts = [];
        $commercialRevenue = [];
        $commercialOrders = [];
        $revenueSeries = [];
        $orderSeries = [];

        foreach ($orders as $order) {
            $totalRevenue += $order['montantTTC'];
            $unpaidTotal += max(0, $order['resteAPayer']);

            if ($order['estValide']) {
                $validatedRevenue += $order['montantTTC'];
            }

            $statusLabel = $order['statut'] !== '' ? $order['statut'] : 'Non renseigne';
            $statusCounts[$statusLabel] = ($statusCounts[$statusLabel] ?? 0) + 1;

            $commercialRevenue[$order['representant']] = ($commercialRevenue[$order['representant']] ?? 0.0) + $order['montantTTC'];
            $commercialOrders[$order['representant']] = ($commercialOrders[$order['representant']] ?? 0) + 1;

            $bucket = $this->buildPeriodBucket($order['date'], $groupBy);
            $revenueSeries[$bucket['key']] = [
                'label' => $bucket['label'],
                'value' => ($revenueSeries[$bucket['key']]['value'] ?? 0) + $order['montantTTC'],
            ];
            $orderSeries[$bucket['key']] = [
                'label' => $bucket['label'],
                'value' => ($orderSeries[$bucket['key']]['value'] ?? 0) + 1,
            ];
        }

        arsort($commercialRevenue);
        arsort($statusCounts);
        ksort($revenueSeries);
        ksort($orderSeries);

        $topCommercials = [];
        foreach (array_slice($commercialRevenue, 0, 8, true) as $name => $amount) {
            $topCommercials[] = [
                'name' => $name,
                'amount' => round($amount, 2),
                'orders' => $commercialOrders[$name] ?? 0,
            ];
        }

        $bestCommercial = $topCommercials[0] ?? ['name' => 'N/A', 'amount' => 0.0, 'orders' => 0];
        $averageBasket = count($orders) > 0 ? $totalRevenue / count($orders) : 0.0;

        return [
            'summary' => [
                'order_count' => count($orders),
                'revenue_total' => round($totalRevenue, 2),
                'average_basket' => round($averageBasket, 2),
                'validated_revenue' => round($validatedRevenue, 2),
                'unpaid_total' => round($unpaidTotal, 2),
                'best_commercial' => $bestCommercial,
            ],
            'charts' => [
                'revenue' => [
                    'labels' => array_column($revenueSeries, 'label'),
                    'data' => array_values(array_map(static fn (array $item): float => round((float) $item['value'], 2), $revenueSeries)),
                ],
                'orders' => [
                    'labels' => array_column($orderSeries, 'label'),
                    'data' => array_values(array_map(static fn (array $item): int => (int) $item['value'], $orderSeries)),
                ],
                'commercials' => [
                    'labels' => array_column($topCommercials, 'name'),
                    'data' => array_column($topCommercials, 'amount'),
                ],
                'statuses' => [
                    'labels' => array_keys($statusCounts),
                    'data' => array_values($statusCounts),
                ],
            ],
            'topCommercials' => $topCommercials,
            'representants' => $this->buildRepresentantOptions($orders),
            'recentOrders' => array_slice($orders, 0, max(1, $tableLimit)),
            'allOrders' => $orders,
        ];
    }

    private function normalizeFilters(array $filters): array
    {
        $period = (string) ($filters['period'] ?? 'custom');
        $allowedPeriods = ['current_month', 'current_year', 'last_12_months', 'custom'];
        $period = in_array($period, $allowedPeriods, true) ? $period : 'custom';

        $groupBy = (string) ($filters['group_by'] ?? 'month');
        $groupBy = in_array($groupBy, ['month', 'year'], true) ? $groupBy : 'month';

        $dateDebut = trim((string) ($filters['date_debut'] ?? ''));
        $dateFin = trim((string) ($filters['date_fin'] ?? ''));

        if ($period === 'custom' && ($dateDebut === '' || $dateFin === '')) {
            [$dateDebut, $dateFin] = $this->resolvePeriodDates('rolling_4_months');
        } elseif ($period !== 'custom') {
            [$dateDebut, $dateFin] = $this->resolvePeriodDates($period);
        }

        return [
            'period' => $period,
            'group_by' => $groupBy,
            'table_limit' => 15,
            'date_debut' => $dateDebut,
            'date_fin' => $dateFin,
            'representant' => trim((string) ($filters['representant'] ?? '')),
            'piece' => trim((string) ($filters['piece'] ?? '')),
            'tiers' => '',
            'statut' => '',
            'estvalide' => '',
            'domaine' => null,
            'type' => null,
            'selected_piece' => trim((string) ($filters['selected_piece'] ?? '')),
        ];
    }

    private function resolvePeriodDates(string $period): array
    {
        $today = new \DateTimeImmutable('today');

        return match ($period) {
            'current_month' => [
                $today->modify('first day of this month')->format('Y-m-d'),
                $today->format('Y-m-d'),
            ],
            'last_12_months' => [
                $today->modify('-11 months')->modify('first day of this month')->format('Y-m-d'),
                $today->format('Y-m-d'),
            ],
            'rolling_4_months' => [
                $today->modify('-4 months')->format('Y-m-d'),
                $today->format('Y-m-d'),
            ],
            default => [
                $today->setDate((int) $today->format('Y'), 1, 1)->format('Y-m-d'),
                $today->format('Y-m-d'),
            ],
        };
    }

    private function buildHeaderQuery(array $filters): array
    {
        $query = [];

        if ($filters['date_debut'] !== '') {
            $query['dateDebut'] = $filters['date_debut'].'T00:00:00';
        }

        if ($filters['date_fin'] !== '') {
            $query['dateFin'] = $filters['date_fin'].'T23:59:59';
        }

        if ($filters['representant'] !== '') {
            $query['representant'] = $filters['representant'];
        }

        if ($filters['piece'] !== '') {
            $query['piece'] = $filters['piece'];
        }

        return $query;
    }

    private function normalizeHeader(array $header): ?array
    {
        $piece = trim((string) ($header['piece'] ?? $header['numBC'] ?? ''));

        if ($piece === '') {
            return null;
        }

        $rawRepresentant = trim((string) ($header['representant'] ?? ''));
        $representant = $this->resolveCommercialDisplayName($rawRepresentant);

        if ($representant === null) {
            return null;
        }

        $date = $this->createDate($header['date'] ?? $header['dateBC'] ?? $header['dateCreation'] ?? null);
        $freeFields = isset($header['champsLibres']) && is_array($header['champsLibres']) ? $header['champsLibres'] : [];

        return [
            'piece' => $piece,
            'reference' => (string) ($header['reference'] ?? $header['referenceBC'] ?? $piece),
            'date' => $date,
            'date_sort' => $date?->format(\DATE_ATOM) ?? '',
            'date_label' => $date?->format('d/m/Y') ?? '-',
            'tiers' => trim((string) ($header['tiers'] ?? $header['numClient'] ?? '')),
            'company_name' => trim((string) ($freeFields['nomtiers'] ?? $header['nomEntreprise'] ?? '')),
            'representant' => $representant,
            'representant_raw' => $rawRepresentant,
            'expediteur' => trim((string) ($header['expediteur'] ?? $header['modeExpedition'] ?? '')),
            'statut' => trim((string) ($header['statut'] ?? $header['statutBC'] ?? '')),
            'estValide' => (bool) ($header['estValide'] ?? $header['estvalide'] ?? false),
            'montantHT' => (float) ($header['montantHT'] ?? 0),
            'montantTTC' => (float) ($header['montantTTC'] ?? $header['totalTTC'] ?? $header['montant'] ?? 0),
            'resteAPayer' => (float) ($header['resteAPayer'] ?? 0),
            'paiement' => trim((string) ($header['paiement'] ?? '')),
            'createur' => trim((string) ($header['createur'] ?? '')),
            'raw' => $header,
        ];
    }

    private function fetchOrderLines(string $piece): array
    {
        $lines = $this->sageClient->get('/Document/line', ['piece' => $piece]);

        return array_values(array_map(static function (array $line): array {
            return [
                'piece' => (string) ($line['piece'] ?? ''),
                'referenceArticle' => (string) ($line['referenceArticle'] ?? $line['reference'] ?? ''),
                'designationArticle' => (string) ($line['designationArticle'] ?? $line['designation'] ?? ''),
                'qteArticle' => (float) ($line['qteArticle'] ?? $line['quantite'] ?? 0),
                'qtePreparee' => (float) ($line['qtePreparee'] ?? $line['quantitePreparee'] ?? 0),
                'uniteArticle' => (string) ($line['uniteArticle'] ?? $line['unite'] ?? ''),
                'prixHTArticle' => (float) ($line['prixHTArticle'] ?? $line['prixHT'] ?? 0),
                'prixTTCArticle' => (float) ($line['prixTTCArticle'] ?? $line['prixTTC'] ?? 0),
                'montantArticle' => (float) ($line['montantArticle'] ?? $line['montantTTC'] ?? $line['montant'] ?? 0),
            ];
        }, $lines));
    }

    private function findOrderByPiece(array $orders, string $piece): ?array
    {
        foreach ($orders as $order) {
            if ($order['piece'] === $piece) {
                return $order;
            }
        }

        return null;
    }

    private function createDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function buildPeriodBucket(?\DateTimeImmutable $date, string $groupBy): array
    {
        if ($date === null) {
            return ['key' => 'unknown', 'label' => 'Sans date'];
        }

        if ($groupBy === 'year') {
            return [
                'key' => $date->format('Y'),
                'label' => $date->format('Y'),
            ];
        }

        return [
            'key' => $date->format('Y-m'),
            'label' => $date->format('m/Y'),
        ];
    }

    private function buildRepresentantOptions(array $orders): array
    {
        $present = [];

        foreach ($orders as $order) {
            $present[$order['representant']] = true;
        }

        $options = [];

        foreach (self::ALLOWED_COMMERCIALS as $commercial) {
            $label = $this->buildCommercialDisplayName($commercial);

            if (!isset($present[$label])) {
                continue;
            }

            $options[] = [
                'value' => $this->buildSageCommercialValue($commercial),
                'label' => $label,
            ];
        }

        return $options;
    }

    private function resolveCommercialDisplayName(string $rawRepresentant): ?string
    {
        if ($rawRepresentant === '') {
            return null;
        }

        $normalizedRaw = $this->normalizeCommercialToken($rawRepresentant);

        foreach (self::ALLOWED_COMMERCIALS as $commercial) {
            $tokens = [
                $this->normalizeCommercialToken($this->buildCommercialDisplayName($commercial)),
                $this->normalizeCommercialToken($commercial['lastName'].' '.$commercial['firstName']),
                $this->normalizeCommercialToken($this->buildSageCommercialValue($commercial)),
            ];

            if (in_array($normalizedRaw, $tokens, true)) {
                return $this->buildCommercialDisplayName($commercial);
            }
        }

        return null;
    }

    private function buildCommercialDisplayName(array $commercial): string
    {
        return trim($commercial['firstName'].' '.$commercial['lastName']);
    }

    private function buildSageCommercialValue(array $commercial): string
    {
        return mb_strtoupper($commercial['lastName']).' '.$commercial['firstName'];
    }

    private function normalizeCommercialToken(string $value): string
    {
        $value = trim($value);
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $value = mb_strtoupper($value);
        $value = preg_replace('/[^A-Z0-9]+/', ' ', $value);

        return trim((string) $value);
    }
}
