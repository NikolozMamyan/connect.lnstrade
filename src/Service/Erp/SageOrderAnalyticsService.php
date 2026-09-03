<?php

namespace App\Service\Erp;

final class SageOrderAnalyticsService
{
    private const SALES_DOMAIN = 0;
    private const SALES_ORDER_TYPE = 1;
    private const SALES_INVOICE_PENDING_TYPE = 6;
    private const SALES_INVOICE_POSTED_TYPE = 7;

    /**
     * @var list<array{hubspotId: string, firstName: string, lastName: string, email: string}>
     */
    private const ALLOWED_COMMERCIALS = [
        ['hubspotId' => '78020060', 'firstName' => 'Quentin', 'lastName' => 'Strasser', 'email' => 'quentin.strasser@lnstrade.fr'],
        ['hubspotId' => '65156164', 'firstName' => 'Douglas', 'lastName' => 'Woods', 'email' => 'douglas.woods@lnstrade.fr'],
        ['hubspotId' => '65155850', 'firstName' => 'Cyril', 'lastName' => 'Motz', 'email' => 'cyril.motz@lnstrade.fr'],
        ['hubspotId' => '65524033', 'firstName' => 'Savinien', 'lastName' => 'Saint Paul', 'email' => 'savinien.saint-paul@lnstrade.fr'],
        ['hubspotId' => '65157022', 'firstName' => 'Corentin', 'lastName' => 'BURY', 'email' => 'corentin.bury@lnstrade.fr'],
        ['hubspotId' => '77839925', 'firstName' => 'Enzo', 'lastName' => 'Houde', 'email' => 'enzo.houde@lnstrade.fr'],
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
        $documentTypes = $this->resolveDocumentTypes($normalizedFilters);
        $headers = $this->fetchHeaders($normalizedFilters, $documentTypes);
        $dashboard = $this->buildDashboard(
            $headers,
            $normalizedFilters['group_by'],
            $normalizedFilters['table_limit'],
            $documentTypes
        );

        $comparison = [
            'enabled' => false,
            'reason' => 'La comparaison est masquée lors d’une recherche par numéro de pièce.',
        ];

        if ($normalizedFilters['piece'] === '') {
            $comparisonFilters = $this->buildComparisonFilters($normalizedFilters);
            $comparisonHeaders = $this->fetchHeaders($comparisonFilters, $documentTypes);
            $comparisonDashboard = $this->buildDashboard(
                $comparisonHeaders,
                $normalizedFilters['group_by'],
                $normalizedFilters['table_limit'],
                $documentTypes
            );
            $comparison = $this->buildComparison(
                $dashboard['summary'],
                $comparisonDashboard['summary'],
                $normalizedFilters,
                $comparisonFilters
            );
        }

        $selectedDocument = null;
        $selectedLines = [];

        if ($normalizedFilters['selected_piece'] !== '' && $normalizedFilters['selected_type'] !== null) {
            $selectedDocument = $this->findDocument(
                $dashboard['allDocuments'],
                $normalizedFilters['selected_piece'],
                $normalizedFilters['selected_type']
            );
            $selectedLines = $selectedDocument === null
                ? []
                : $this->fetchDocumentLines($normalizedFilters['selected_piece'], $normalizedFilters['selected_type']);
        }

        return [
            'filters' => $normalizedFilters,
            'summary' => $dashboard['summary'],
            'comparison' => $comparison,
            'charts' => $dashboard['charts'],
            'topCommercials' => $dashboard['topCommercials'],
            'topClients' => $dashboard['topClients'],
            'representants' => $dashboard['representants'],
            'recentDocuments' => $dashboard['recentDocuments'],
            'selectedDocument' => $selectedDocument,
            'selectedLines' => $selectedLines,
            'totalDocuments' => count($dashboard['allDocuments']),
        ];
    }

    /**
     * @return array{document: array<string, mixed>|null, lines: list<array<string, mixed>>}
     */
    public function getDocumentDetail(int $type, string $piece, ?string $representant = null): array
    {
        $piece = trim($piece);

        if ($piece === '' || !in_array($type, $this->getAllowedDocumentTypes(), true)) {
            return ['document' => null, 'lines' => []];
        }

        $query = [
            'domaine' => self::SALES_DOMAIN,
            'type' => $type,
            'piece' => $piece,
        ];

        if ($representant !== null && trim($representant) !== '') {
            $query['representant'] = trim($representant);
        }

        $documents = array_values(array_filter(array_map(
            fn (array $header): ?array => $this->normalizeHeader($header, $type),
            $this->sageClient->get('/Document/header', $query)
        )));
        $document = $this->findDocument($documents, $piece, $type);

        return [
            'document' => $document,
            'lines' => $document === null ? [] : $this->fetchDocumentLines($piece, $type),
        ];
    }

    public function buildDashboard(
        array $headers,
        string $groupBy = 'month',
        int $tableLimit = 15,
        array $includedTypes = [self::SALES_ORDER_TYPE],
    ): array
    {
        $documents = array_values(array_filter(array_map(
            fn (array $header): ?array => $this->normalizeHeader(
                $header,
                (int) ($header['_document_type'] ?? self::SALES_ORDER_TYPE)
            ),
            $headers
        )));

        usort($documents, static function (array $left, array $right): int {
            return strcmp((string) $right['date_sort'], (string) $left['date_sort']);
        });

        $summary = [
            'document_count' => count($documents),
            'order_count' => 0,
            'invoice_count' => 0,
            'pending_invoice_count' => 0,
            'posted_invoice_count' => 0,
            'credit_count' => 0,
            'ordered_amount_ht' => 0.0,
            'ordered_amount_ttc' => 0.0,
            'pending_invoice_amount_ht' => 0.0,
            'pending_invoice_amount_ttc' => 0.0,
            'posted_revenue_ht' => 0.0,
            'posted_revenue_ttc' => 0.0,
            'invoice_amount_ht' => 0.0,
            'invoice_amount_ttc' => 0.0,
            'average_order_ht' => 0.0,
            'average_invoice_ht' => 0.0,
            'to_prepare_count' => 0,
            'to_prepare_amount_ht' => 0.0,
            'to_prepare_share' => 0.0,
        ];
        $statusCounts = ['orders' => [], 'invoices' => []];
        $commercialMetrics = [];
        $clientMetrics = [];
        $amountSeries = [];
        $volumeSeries = [];

        foreach ($documents as $document) {
            $category = $document['category'];
            $amountHt = $document['montantHT'];
            $amountTtc = $document['montantTTC'];

            if ($category === 'order') {
                ++$summary['order_count'];
                $summary['ordered_amount_ht'] += $amountHt;
                $summary['ordered_amount_ttc'] += $amountTtc;

                if (mb_strtolower($document['statut']) === 'à préparer') {
                    ++$summary['to_prepare_count'];
                    $summary['to_prepare_amount_ht'] += $amountHt;
                }
            } elseif ($category === 'pending_invoice') {
                ++$summary['invoice_count'];
                ++$summary['pending_invoice_count'];
                $summary['pending_invoice_amount_ht'] += $amountHt;
                $summary['pending_invoice_amount_ttc'] += $amountTtc;
            } else {
                ++$summary['invoice_count'];
                ++$summary['posted_invoice_count'];
                $summary['posted_revenue_ht'] += $amountHt;
                $summary['posted_revenue_ttc'] += $amountTtc;
            }

            if ($document['is_credit']) {
                ++$summary['credit_count'];
            }

            $statusGroup = $category === 'order' ? 'orders' : 'invoices';
            $statusLabel = $document['statut'] !== '' ? $document['statut'] : 'Non renseigné';
            $statusCounts[$statusGroup][$statusLabel] = ($statusCounts[$statusGroup][$statusLabel] ?? 0) + 1;

            $this->accumulateEntityMetric($commercialMetrics, $document['representant'], '', $category, $amountHt);
            $clientName = $document['company_name'] !== ''
                ? $document['company_name']
                : ($document['tiers'] !== '' ? $document['tiers'] : $document['piece']);
            $this->accumulateEntityMetric($clientMetrics, $clientName, $document['tiers'], $category, $amountHt);

            $bucket = $this->buildPeriodBucket($document['date'], $groupBy);
            $amountSeries[$bucket['key']]['label'] = $bucket['label'];
            $amountSeries[$bucket['key']][$category] = ($amountSeries[$bucket['key']][$category] ?? 0.0) + $amountHt;
            $volumeSeries[$bucket['key']]['label'] = $bucket['label'];
            $volumeSeries[$bucket['key']][$category] = ($volumeSeries[$bucket['key']][$category] ?? 0) + 1;
        }

        $summary['invoice_amount_ht'] = $summary['pending_invoice_amount_ht'] + $summary['posted_revenue_ht'];
        $summary['invoice_amount_ttc'] = $summary['pending_invoice_amount_ttc'] + $summary['posted_revenue_ttc'];
        $summary['average_order_ht'] = $summary['order_count'] > 0
            ? $summary['ordered_amount_ht'] / $summary['order_count']
            : 0.0;
        $summary['average_invoice_ht'] = $summary['invoice_count'] > 0
            ? $summary['invoice_amount_ht'] / $summary['invoice_count']
            : 0.0;
        $summary['to_prepare_share'] = $summary['order_count'] > 0
            ? ($summary['to_prepare_count'] / $summary['order_count']) * 100
            : 0.0;

        foreach ($summary as $key => $value) {
            if (is_float($value)) {
                $summary[$key] = round($value, $key === 'to_prepare_share' ? 1 : 2);
            }
        }

        arsort($statusCounts['orders']);
        arsort($statusCounts['invoices']);
        ksort($amountSeries);
        ksort($volumeSeries);

        $categories = $this->buildChartCategories($includedTypes);
        $topCommercials = $this->buildTopEntities($commercialMetrics, $categories, 8);
        $topClients = $this->buildTopEntities($clientMetrics, $categories, 8);

        return [
            'summary' => $summary,
            'charts' => [
                'amounts' => $this->buildPeriodChart($amountSeries, $categories, 'float'),
                'volumes' => $this->buildPeriodChart($volumeSeries, $categories, 'int'),
                'commercials' => $this->buildEntityChart($topCommercials, $categories),
                'clients' => $this->buildEntityChart($topClients, $categories),
                'statuses' => [
                    'orders' => [
                        'labels' => array_keys($statusCounts['orders']),
                        'data' => array_values($statusCounts['orders']),
                    ],
                    'invoices' => [
                        'labels' => array_keys($statusCounts['invoices']),
                        'data' => array_values($statusCounts['invoices']),
                    ],
                ],
            ],
            'topCommercials' => $topCommercials,
            'topClients' => $topClients,
            'representants' => $this->buildRepresentantOptions(),
            'recentDocuments' => array_slice($documents, 0, max(1, $tableLimit)),
            'allDocuments' => $documents,
        ];
    }

    /**
     * @param list<int> $documentTypes
     *
     * @return list<array<string, mixed>>
     */
    private function fetchHeaders(array $filters, array $documentTypes): array
    {
        $headers = [];

        foreach ($documentTypes as $type) {
            foreach ($this->sageClient->get('/Document/header', $this->buildHeaderQuery($filters, $type)) as $header) {
                if (!is_array($header)) {
                    continue;
                }

                $header['_document_type'] = $type;
                $headers[] = $header;
            }
        }

        return $headers;
    }

    /**
     * @return list<int>
     */
    private function resolveDocumentTypes(array $filters): array
    {
        $invoiceTypes = match ($filters['invoice_state']) {
            'pending' => [self::SALES_INVOICE_PENDING_TYPE],
            'posted' => [self::SALES_INVOICE_POSTED_TYPE],
            default => [self::SALES_INVOICE_PENDING_TYPE, self::SALES_INVOICE_POSTED_TYPE],
        };

        return match ($filters['document_scope']) {
            'orders' => [self::SALES_ORDER_TYPE],
            'invoices' => $invoiceTypes,
            default => [self::SALES_ORDER_TYPE, ...$invoiceTypes],
        };
    }

    /**
     * @return list<int>
     */
    private function getAllowedDocumentTypes(): array
    {
        return [
            self::SALES_ORDER_TYPE,
            self::SALES_INVOICE_PENDING_TYPE,
            self::SALES_INVOICE_POSTED_TYPE,
        ];
    }

    /**
     * @param list<int> $includedTypes
     *
     * @return list<array{key: string, label: string, volume_label: string, amount_field: string, count_field: string}>
     */
    private function buildChartCategories(array $includedTypes): array
    {
        $categories = [];

        if (in_array(self::SALES_ORDER_TYPE, $includedTypes, true)) {
            $categories[] = [
                'key' => 'order',
                'label' => 'Commandes BC',
                'volume_label' => 'Commandes BC',
                'amount_field' => 'order_amount_ht',
                'count_field' => 'order_count',
            ];
        }

        if (in_array(self::SALES_INVOICE_PENDING_TYPE, $includedTypes, true)) {
            $categories[] = [
                'key' => 'pending_invoice',
                'label' => 'Factures à comptabiliser',
                'volume_label' => 'Factures à comptabiliser',
                'amount_field' => 'pending_invoice_amount_ht',
                'count_field' => 'pending_invoice_count',
            ];
        }

        if (in_array(self::SALES_INVOICE_POSTED_TYPE, $includedTypes, true)) {
            $categories[] = [
                'key' => 'posted_invoice',
                'label' => 'CA comptabilisé net HT',
                'volume_label' => 'Factures comptabilisées',
                'amount_field' => 'posted_invoice_amount_ht',
                'count_field' => 'posted_invoice_count',
            ];
        }

        return $categories;
    }

    private function accumulateEntityMetric(
        array &$metrics,
        string $name,
        string $tiers,
        string $category,
        float $amountHt,
    ): void {
        $metrics[$name] ??= [
            'name' => $name,
            'tiers' => $tiers,
            'order_amount_ht' => 0.0,
            'pending_invoice_amount_ht' => 0.0,
            'posted_invoice_amount_ht' => 0.0,
            'order_count' => 0,
            'pending_invoice_count' => 0,
            'posted_invoice_count' => 0,
        ];

        $metrics[$name][$category.'_amount_ht'] += $amountHt;
        ++$metrics[$name][$category.'_count'];

        if ($metrics[$name]['tiers'] === '' && $tiers !== '') {
            $metrics[$name]['tiers'] = $tiers;
        }
    }

    /**
     * @param array<string, array<string, mixed>> $metrics
     * @param list<array{key: string, label: string, volume_label: string, amount_field: string, count_field: string}> $categories
     *
     * @return list<array<string, mixed>>
     */
    private function buildTopEntities(array $metrics, array $categories, int $limit): array
    {
        $includesInvoices = count(array_filter(
            $categories,
            static fn (array $category): bool => $category['key'] !== 'order'
        )) > 0;

        foreach ($metrics as &$item) {
            $item['invoice_amount_ht'] = $item['pending_invoice_amount_ht'] + $item['posted_invoice_amount_ht'];
            $item['invoice_count'] = $item['pending_invoice_count'] + $item['posted_invoice_count'];
            $item['ranking_amount_ht'] = $includesInvoices ? $item['invoice_amount_ht'] : $item['order_amount_ht'];

            foreach (['order_amount_ht', 'pending_invoice_amount_ht', 'posted_invoice_amount_ht', 'invoice_amount_ht', 'ranking_amount_ht'] as $key) {
                $item[$key] = round((float) $item[$key], 2);
            }
        }
        unset($item);

        uasort($metrics, static function (array $left, array $right): int {
            $comparison = $right['ranking_amount_ht'] <=> $left['ranking_amount_ht'];

            return $comparison !== 0 ? $comparison : strcmp((string) $left['name'], (string) $right['name']);
        });

        return array_values(array_slice($metrics, 0, $limit, true));
    }

    /**
     * @param array<string, array<string, mixed>> $series
     * @param list<array{key: string, label: string, volume_label: string, amount_field: string, count_field: string}> $categories
     */
    private function buildPeriodChart(array $series, array $categories, string $format): array
    {
        $datasets = [];

        foreach ($categories as $category) {
            $datasets[] = [
                'key' => $category['key'],
                'label' => $format === 'int' ? $category['volume_label'] : $category['label'],
                'data' => array_values(array_map(
                    static fn (array $item): float|int => $format === 'int'
                        ? (int) ($item[$category['key']] ?? 0)
                        : round((float) ($item[$category['key']] ?? 0), 2),
                    $series
                )),
            ];
        }

        return [
            'labels' => array_column($series, 'label'),
            'datasets' => $datasets,
        ];
    }

    /**
     * @param list<array<string, mixed>> $entities
     * @param list<array{key: string, label: string, volume_label: string, amount_field: string, count_field: string}> $categories
     */
    private function buildEntityChart(array $entities, array $categories): array
    {
        $datasets = [];

        foreach ($categories as $category) {
            $datasets[] = [
                'key' => $category['key'],
                'label' => $category['label'],
                'data' => array_map(
                    static fn (array $entity): float => (float) $entity[$category['amount_field']],
                    $entities
                ),
            ];
        }

        return [
            'labels' => array_column($entities, 'name'),
            'datasets' => $datasets,
        ];
    }

    public function resolveRepresentantValueByEmail(string $email): ?string
    {
        $normalizedEmail = mb_strtolower(trim($email));

        if ($normalizedEmail === '') {
            return null;
        }

        foreach (self::ALLOWED_COMMERCIALS as $commercial) {
            if (mb_strtolower($commercial['email']) === $normalizedEmail) {
                return $this->buildSageCommercialValue($commercial);
            }
        }

        return null;
    }

    private function normalizeFilters(array $filters): array
    {
        $defaultPeriod = $this->hasExplicitPeriodFilters($filters) ? 'custom' : 'last_3_months';
        $period = (string) ($filters['period'] ?? $defaultPeriod);
        $allowedPeriods = ['current_month', 'current_year', 'last_3_months', 'last_12_months', 'custom'];
        $period = in_array($period, $allowedPeriods, true) ? $period : $defaultPeriod;

        $groupBy = (string) ($filters['group_by'] ?? 'month');
        $groupBy = in_array($groupBy, ['month', 'year'], true) ? $groupBy : 'month';

        $documentScope = (string) ($filters['document_scope'] ?? 'all');
        $documentScope = in_array($documentScope, ['all', 'orders', 'invoices'], true) ? $documentScope : 'all';

        $invoiceState = (string) ($filters['invoice_state'] ?? 'all');
        $invoiceState = in_array($invoiceState, ['all', 'pending', 'posted'], true) ? $invoiceState : 'all';

        $dateDebut = trim((string) ($filters['date_debut'] ?? ''));
        $dateFin = trim((string) ($filters['date_fin'] ?? ''));

        if ($period === 'custom' && (!$this->isValidDate($dateDebut) || !$this->isValidDate($dateFin))) {
            [$dateDebut, $dateFin] = $this->resolvePeriodDates('last_3_months');
        } elseif ($period !== 'custom') {
            [$dateDebut, $dateFin] = $this->resolvePeriodDates($period);
        }

        if ($dateDebut > $dateFin) {
            [$dateDebut, $dateFin] = [$dateFin, $dateDebut];
        }

        $selectedType = filter_var($filters['selected_type'] ?? null, FILTER_VALIDATE_INT);
        $selectedType = in_array($selectedType, $this->getAllowedDocumentTypes(), true) ? $selectedType : null;

        return [
            'period' => $period,
            'group_by' => $groupBy,
            'document_scope' => $documentScope,
            'invoice_state' => $invoiceState,
            'table_limit' => 15,
            'date_debut' => $dateDebut,
            'date_fin' => $dateFin,
            'representant' => trim((string) ($filters['representant'] ?? '')),
            'piece' => trim((string) ($filters['piece'] ?? '')),
            'selected_piece' => trim((string) ($filters['selected_piece'] ?? '')),
            'selected_type' => $selectedType,
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
            'last_3_months' => [
                $today->modify('-2 months')->modify('first day of this month')->format('Y-m-d'),
                $today->format('Y-m-d'),
            ],
            'last_12_months' => [
                $today->modify('-11 months')->modify('first day of this month')->format('Y-m-d'),
                $today->format('Y-m-d'),
            ],
            default => [
                $today->setDate((int) $today->format('Y'), 1, 1)->format('Y-m-d'),
                $today->format('Y-m-d'),
            ],
        };
    }

    private function hasExplicitPeriodFilters(array $filters): bool
    {
        foreach (['date_debut', 'date_fin', 'representant', 'piece', 'selected_piece', 'selected_type'] as $key) {
            if (trim((string) ($filters[$key] ?? '')) !== '') {
                return true;
            }
        }

        return isset($filters['period']) && trim((string) $filters['period']) !== '';
    }

    private function buildHeaderQuery(array $filters, int $type): array
    {
        $query = [
            'domaine' => self::SALES_DOMAIN,
            'type' => $type,
        ];

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

    private function normalizeHeader(array $header, int $type): ?array
    {
        $piece = trim((string) ($header['piece'] ?? ''));

        if ($piece === '') {
            return null;
        }

        $rawRepresentant = trim((string) ($header['representant'] ?? ''));
        $representant = $this->resolveCommercialDisplayName($rawRepresentant);

        if ($representant === null) {
            return null;
        }

        $date = $this->createDate($header['date'] ?? null);
        $deliveryDate = $this->createDate($header['dateLivraison'] ?? null);
        $freeFields = isset($header['champsLibres']) && is_array($header['champsLibres']) ? $header['champsLibres'] : [];
        $amountHt = (float) ($header['montantHT'] ?? 0);
        $category = match ($type) {
            self::SALES_INVOICE_PENDING_TYPE => 'pending_invoice',
            self::SALES_INVOICE_POSTED_TYPE => 'posted_invoice',
            default => 'order',
        };
        $isCredit = $category !== 'order' && $amountHt < 0;
        $typeLabel = match ($category) {
            'pending_invoice' => $isCredit ? 'Avoir à comptabiliser' : 'Facture à comptabiliser',
            'posted_invoice' => $isCredit ? 'Avoir comptabilisé' : 'Facture comptabilisée',
            default => 'Commande BC',
        };

        return [
            'piece' => $piece,
            'document_type' => $type,
            'category' => $category,
            'family' => $category === 'order' ? 'order' : 'invoice',
            'type_label' => $typeLabel,
            'is_credit' => $isCredit,
            'reference' => trim((string) ($header['reference'] ?? '')),
            'date' => $date,
            'date_sort' => $date?->format(\DATE_ATOM) ?? '',
            'date_label' => $date?->format('d/m/Y') ?? '-',
            'date_delivery_label' => $deliveryDate?->format('d/m/Y') ?? '-',
            'tiers' => trim((string) ($header['tiers'] ?? '')),
            'company_name' => trim((string) ($freeFields['nomtiers'] ?? '')),
            'representant' => $representant,
            'expediteur' => trim((string) ($header['expediteur'] ?? '')),
            'condition_livraison' => trim((string) ($header['conditionLivraison'] ?? '')),
            'statut' => trim((string) ($header['statut'] ?? '')),
            'montantHT' => $amountHt,
            'montantTTC' => (float) ($header['montantTTC'] ?? 0),
            'paiement' => trim((string) ($header['paiement'] ?? '')),
            'order_reference_client' => trim((string) ($freeFields['Num_commande_ref_client'] ?? '')),
            'progress' => trim((string) ($freeFields['Avancement'] ?? '')),
            'additional_status' => trim((string) ($freeFields['Statut supplémentaire'] ?? '')),
            'delivery_instruction' => trim((string) ($freeFields['Instruction de livraison'] ?? '')),
        ];
    }

    private function fetchDocumentLines(string $piece, int $type): array
    {
        $lines = $this->sageClient->get('/Document/line', [
            'piece' => $piece,
            'domaine' => self::SALES_DOMAIN,
            'type' => $type,
        ]);

        return array_values(array_map(static function (array $line): array {
            return [
                'referenceArticle' => (string) ($line['referenceArticle'] ?? ''),
                'designationArticle' => (string) ($line['designationArticle'] ?? ''),
                'qteArticle' => (float) ($line['qteArticle'] ?? 0),
                'qtePreparee' => (float) ($line['qtePreparee'] ?? 0),
                'uniteArticle' => (string) ($line['uniteArticle'] ?? ''),
                'prixHTArticle' => (float) ($line['prixHTArticle'] ?? 0),
                'montantHT' => (float) ($line['montantArticle'] ?? 0),
                'montantTTC' => (float) ($line['montantTTC'] ?? 0),
            ];
        }, $lines));
    }

    private function findDocument(array $documents, string $piece, int $type): ?array
    {
        foreach ($documents as $document) {
            if ($document['piece'] === $piece && $document['document_type'] === $type) {
                return $document;
            }
        }

        return null;
    }

    private function buildComparison(array $currentSummary, array $previousSummary, array $currentFilters, array $previousFilters): array
    {
        $metrics = match ($currentFilters['document_scope']) {
            'orders' => [
                $this->buildComparisonMetric('Montant BC HT', 'currency', $currentSummary['ordered_amount_ht'], $previousSummary['ordered_amount_ht']),
                $this->buildComparisonMetric('Montant BC TTC', 'currency', $currentSummary['ordered_amount_ttc'], $previousSummary['ordered_amount_ttc']),
                $this->buildComparisonMetric('Commandes BC', 'number', $currentSummary['order_count'], $previousSummary['order_count']),
                $this->buildComparisonMetric('Panier moyen BC HT', 'currency', $currentSummary['average_order_ht'], $previousSummary['average_order_ht']),
            ],
            'invoices' => $this->buildInvoiceComparisonMetrics($currentSummary, $previousSummary, $currentFilters['invoice_state']),
            default => [
                $this->buildComparisonMetric('CA comptabilisé net HT', 'currency', $currentSummary['posted_revenue_ht'], $previousSummary['posted_revenue_ht']),
                $this->buildComparisonMetric('Carnet BC HT', 'currency', $currentSummary['ordered_amount_ht'], $previousSummary['ordered_amount_ht']),
                $this->buildComparisonMetric('À comptabiliser HT', 'currency', $currentSummary['pending_invoice_amount_ht'], $previousSummary['pending_invoice_amount_ht']),
                $this->buildComparisonMetric('Documents suivis', 'number', $currentSummary['document_count'], $previousSummary['document_count']),
            ],
        };

        return [
            'enabled' => true,
            'current_label' => $this->buildRangeLabel($currentFilters['date_debut'], $currentFilters['date_fin']),
            'previous_label' => $this->buildRangeLabel($previousFilters['date_debut'], $previousFilters['date_fin']),
            'metrics' => $metrics,
        ];
    }

    private function buildInvoiceComparisonMetrics(array $current, array $previous, string $invoiceState): array
    {
        if ($invoiceState === 'pending') {
            return [
                $this->buildComparisonMetric('À comptabiliser HT', 'currency', $current['pending_invoice_amount_ht'], $previous['pending_invoice_amount_ht']),
                $this->buildComparisonMetric('À comptabiliser TTC', 'currency', $current['pending_invoice_amount_ttc'], $previous['pending_invoice_amount_ttc']),
                $this->buildComparisonMetric('Documents à comptabiliser', 'number', $current['pending_invoice_count'], $previous['pending_invoice_count']),
                $this->buildComparisonMetric('Montant moyen HT', 'currency', $current['average_invoice_ht'], $previous['average_invoice_ht']),
            ];
        }

        if ($invoiceState === 'posted') {
            return [
                $this->buildComparisonMetric('CA comptabilisé net HT', 'currency', $current['posted_revenue_ht'], $previous['posted_revenue_ht']),
                $this->buildComparisonMetric('CA comptabilisé net TTC', 'currency', $current['posted_revenue_ttc'], $previous['posted_revenue_ttc']),
                $this->buildComparisonMetric('Documents comptabilisés', 'number', $current['posted_invoice_count'], $previous['posted_invoice_count']),
                $this->buildComparisonMetric('Montant moyen HT', 'currency', $current['average_invoice_ht'], $previous['average_invoice_ht']),
            ];
        }

        return [
            $this->buildComparisonMetric('CA comptabilisé net HT', 'currency', $current['posted_revenue_ht'], $previous['posted_revenue_ht']),
            $this->buildComparisonMetric('À comptabiliser HT', 'currency', $current['pending_invoice_amount_ht'], $previous['pending_invoice_amount_ht']),
            $this->buildComparisonMetric('Documents de facturation', 'number', $current['invoice_count'], $previous['invoice_count']),
            $this->buildComparisonMetric('Facturation nette HT', 'currency', $current['invoice_amount_ht'], $previous['invoice_amount_ht']),
        ];
    }

    private function buildComparisonMetric(string $label, string $format, float|int $current, float|int $previous): array
    {
        $delta = $current - $previous;
        $deltaPercent = null;

        if ((float) $previous !== 0.0) {
            $deltaPercent = round(($delta / $previous) * 100, 1);
        }

        return [
            'label' => $label,
            'format' => $format,
            'current' => $format === 'number' ? (int) $current : round((float) $current, 2),
            'previous' => $format === 'number' ? (int) $previous : round((float) $previous, 2),
            'delta' => $format === 'number' ? (int) $delta : round((float) $delta, 2),
            'delta_percent' => $deltaPercent,
            'trend' => $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat'),
        ];
    }

    private function buildComparisonFilters(array $filters): array
    {
        $currentStart = new \DateTimeImmutable($filters['date_debut'].' 00:00:00');
        $currentEnd = new \DateTimeImmutable($filters['date_fin'].' 00:00:00');
        $interval = $currentStart->diff($currentEnd);
        $previousEnd = $currentStart->modify('-1 day');
        $previousStart = $previousEnd->sub($interval);

        $comparisonFilters = $filters;
        $comparisonFilters['date_debut'] = $previousStart->format('Y-m-d');
        $comparisonFilters['date_fin'] = $previousEnd->format('Y-m-d');
        $comparisonFilters['selected_piece'] = '';
        $comparisonFilters['selected_type'] = null;

        return $comparisonFilters;
    }

    private function buildRangeLabel(string $dateStart, string $dateEnd): string
    {
        if ($dateStart === '' || $dateEnd === '') {
            return 'Periode indisponible';
        }

        return sprintf(
            '%s → %s',
            (new \DateTimeImmutable($dateStart))->format('d/m/Y'),
            (new \DateTimeImmutable($dateEnd))->format('d/m/Y')
        );
    }

    private function isValidDate(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
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

    private function buildRepresentantOptions(): array
    {
        $options = [];

        foreach (self::ALLOWED_COMMERCIALS as $commercial) {
            $label = $this->buildCommercialDisplayName($commercial);

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
