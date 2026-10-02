<?php

namespace App\Service\Report;

use App\Repository\ErpDeliveryNoteRepository;
use App\Service\Spreadsheet\SimpleXlsxWriter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class DeliveryOrderCorrectionReportExporter
{
    public function __construct(
        private readonly ErpDeliveryNoteRepository $deliveryNoteRepository,
        private readonly SimpleXlsxWriter $xlsxWriter,
        #[Autowire('%hubspot_portal_id%')]
        private readonly string $hubspotPortalId,
    ) {
    }

    /**
     * @return array{content: string, filename: string, failedDocuments: int, companies: int}
     */
    public function generate(): array
    {
        $documents = $this->deliveryNoteRepository->findFailedForCorrectionReport();
        $preparedDocuments = [];
        $companies = [];
        $categoryCounts = [];
        $unassignedDocuments = 0;

        foreach ($documents as $document) {
            $clientId = trim((string) ($document['clientId'] ?? ''));
            $clientName = trim((string) ($document['clientName'] ?? ''));
            $message = trim((string) ($document['errorMessage'] ?? 'Erreur non detaillee.'));
            $correction = $this->resolveCorrection($message, $clientId);
            $preparedDocument = $document + $correction;
            $preparedDocument['clientId'] = $clientId;
            $preparedDocument['clientName'] = $clientName;
            $preparedDocuments[] = $preparedDocument;
            $categoryCounts[$correction['category']] = ($categoryCounts[$correction['category']] ?? 0) + 1;

            if ($clientId === '') {
                ++$unassignedDocuments;
                continue;
            }

            $key = mb_strtoupper($clientId);

            if (!isset($companies[$key])) {
                $companies[$key] = [
                    'clientId' => $clientId,
                    'clientName' => $clientName !== '' ? $clientName : $clientId,
                    'documents' => [],
                    'categories' => [],
                    'recommendations' => [],
                    'messages' => [],
                    'links' => [],
                    'amount' => 0.0,
                    'firstDate' => null,
                    'lastDate' => null,
                    'latestAttempt' => null,
                    'priorityRank' => 99,
                    'priority' => 'A verifier',
                ];
            }

            $documentLabel = $this->documentLabel($document);
            $companies[$key]['documents'][$documentLabel] = true;
            $companies[$key]['categories'][$correction['category']] = true;
            $companies[$key]['recommendations'][$correction['recommendation']] = true;
            $companies[$key]['messages'][$message] = true;
            $companies[$key]['amount'] += (float) ($document['amountIncludingTax'] ?? 0);

            if ($correction['link'] !== null) {
                $companies[$key]['links'][$correction['link']] = true;
            }

            if ($correction['priorityRank'] < $companies[$key]['priorityRank']) {
                $companies[$key]['priorityRank'] = $correction['priorityRank'];
                $companies[$key]['priority'] = $correction['priority'];
            }

            $documentDate = $this->dateValue($document['documentDate'] ?? null);
            $latestAttempt = $this->dateValue($document['analyzedAt'] ?? ($document['updatedAt'] ?? null));
            $companies[$key]['firstDate'] = $this->earliest($companies[$key]['firstDate'], $documentDate);
            $companies[$key]['lastDate'] = $this->latest($companies[$key]['lastDate'], $documentDate);
            $companies[$key]['latestAttempt'] = $this->latest($companies[$key]['latestAttempt'], $latestAttempt);
        }

        uasort($companies, static function (array $left, array $right): int {
            return [$left['priorityRank'], -count($left['documents']), $left['clientName']]
                <=> [$right['priorityRank'], -count($right['documents']), $right['clientName']];
        });
        arsort($categoryCounts);
        $generatedAt = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
        $content = $this->xlsxWriter->build([
            $this->summarySheet($documents, $companies, $categoryCounts, $unassignedDocuments, $generatedAt),
            $this->companiesSheet($companies),
            $this->documentsSheet($preparedDocuments),
        ]);

        return [
            'content' => $content,
            'filename' => sprintf('corrections-orders-hubspot-%s.xlsx', $generatedAt->format('Y-m-d-His')),
            'failedDocuments' => count($documents),
            'companies' => count($companies),
        ];
    }

    /**
     * @param list<array<string, mixed>> $documents
     * @param array<string, array<string, mixed>> $companies
     * @param array<string, int> $categoryCounts
     *
     * @return array<string, mixed>
     */
    private function summarySheet(
        array $documents,
        array $companies,
        array $categoryCounts,
        int $unassignedDocuments,
        \DateTimeImmutable $generatedAt,
    ): array {
        $rows = [
            [
                $this->cell('Rapport de correction - Orders HubSpot', 'title'),
                '', '', '', '', '',
            ],
            [$this->cell('Genere le', 'label'), $generatedAt->format('d/m/Y H:i'), '', '', '', ''],
            [$this->cell('Objectif', 'label'), 'Corriger les entreprises et documents bloques avant la prochaine synchronisation.', '', '', '', ''],
            [],
            [$this->cell('Indicateur', 'header'), $this->cell('Valeur', 'header'), $this->cell('Lecture', 'header')],
            ['Documents en erreur', $this->number(count($documents)), 'Chaque document reste a retenter apres correction.'],
            ['Entreprises concernees', $this->number(count($companies)), 'Une ligne consolidee par reference client dans la feuille Entreprises.'],
            ['Documents sans entreprise identifiee', $this->number($unassignedDocuments), 'Incidents techniques survenus avant l enregistrement du client. Voir la feuille Documents.'],
            [],
            [$this->cell('Categorie', 'header'), $this->cell('Documents', 'header'), $this->cell('Type d action', 'header')],
        ];

        foreach ($categoryCounts as $category => $count) {
            $rows[] = [
                $category,
                $this->number($count),
                $this->isTechnicalCategory($category) ? 'Relancer apres stabilisation des API' : 'Correction manuelle recommandee',
            ];
        }

        $rows[] = [];
        $rows[] = [$this->cell('Mode d emploi', 'label'), '1. Commencer par les lignes "A corriger". 2. Utiliser le lien HubSpot. 3. Appliquer la recommandation. 4. Relancer la synchronisation historique pour les documents de plus de 90 jours.', '', '', '', ''];

        return [
            'name' => 'Synthese',
            'rows' => $rows,
            'widths' => [34, 18, 78, 14, 14, 14],
            'merges' => ['A1:F1', 'B2:F2', 'B3:F3', sprintf('B%d:F%d', count($rows), count($rows))],
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $companies
     *
     * @return array<string, mixed>
     */
    private function companiesSheet(array $companies): array
    {
        $rows = [[
            $this->cell('Priorite', 'header'),
            $this->cell('Reference client', 'header'),
            $this->cell('Entreprise', 'header'),
            $this->cell('Documents', 'header'),
            $this->cell('Montant TTC impacte', 'header'),
            $this->cell('Pieces concernees', 'header'),
            $this->cell('Premiere date', 'header'),
            $this->cell('Derniere date', 'header'),
            $this->cell('Anomalies detectees', 'header'),
            $this->cell('Actions recommandees', 'header'),
            $this->cell('Derniere tentative', 'header'),
            $this->cell('Acces HubSpot', 'header'),
        ]];

        foreach ($companies as $company) {
            $documents = array_keys($company['documents']);
            $categories = array_keys($company['categories']);
            $recommendations = array_keys($company['recommendations']);
            $links = array_keys($company['links']);
            $rowStyle = $company['priorityRank'] === 1 ? 'error' : ($company['priorityRank'] === 2 ? 'warning' : 'wrap');
            $rows[] = [
                $this->cell($company['priority'], $rowStyle),
                $this->cell($company['clientId'], $rowStyle),
                $this->cell($company['clientName'], $rowStyle),
                $this->cell((string) count($documents), $rowStyle, 'number'),
                $this->cell(round((float) $company['amount'], 2), 'number', 'number'),
                $this->cell(implode(', ', $documents), $rowStyle),
                $this->cell($this->formatDate($company['firstDate']), $rowStyle),
                $this->cell($this->formatDate($company['lastDate']), $rowStyle),
                $this->cell(implode("\n", $categories), $rowStyle),
                $this->cell(implode("\n", $recommendations), $rowStyle),
                $this->cell($this->formatDateTime($company['latestAttempt']), $rowStyle),
                $links !== [] ? $this->linkCell('Ouvrir dans HubSpot', $links[0]) : '',
            ];
        }

        return [
            'name' => 'Entreprises',
            'rows' => $rows,
            'widths' => [14, 18, 34, 12, 20, 55, 14, 14, 34, 72, 20, 24],
            'freezeRows' => 1,
            'filterRow' => 1,
        ];
    }

    /**
     * @param list<array<string, mixed>> $documents
     *
     * @return array<string, mixed>
     */
    private function documentsSheet(array $documents): array
    {
        $rows = [[
            $this->cell('Priorite', 'header'),
            $this->cell('Reference client', 'header'),
            $this->cell('Entreprise', 'header'),
            $this->cell('Piece initiale', 'header'),
            $this->cell('Facture finale', 'header'),
            $this->cell('Type', 'header'),
            $this->cell('Date document', 'header'),
            $this->cell('Total TTC', 'header'),
            $this->cell('Order HubSpot', 'header'),
            $this->cell('Categorie', 'header'),
            $this->cell('Erreur enregistree', 'header'),
            $this->cell('Correction recommandee', 'header'),
            $this->cell('Derniere tentative', 'header'),
            $this->cell('Acces HubSpot', 'header'),
        ]];

        usort($documents, static function (array $left, array $right): int {
            return [$left['priorityRank'], $left['clientName'], $left['piece']]
                <=> [$right['priorityRank'], $right['clientName'], $right['piece']];
        });

        foreach ($documents as $document) {
            $rowStyle = $document['priorityRank'] === 1 ? 'error' : ($document['priorityRank'] === 2 ? 'warning' : 'wrap');
            $rows[] = [
                $this->cell($document['priority'], $rowStyle),
                $this->cell($document['clientId'] !== '' ? $document['clientId'] : 'Non identifiee', $rowStyle),
                $this->cell($document['clientName'] !== '' ? $document['clientName'] : 'Entreprise non enregistree', $rowStyle),
                $this->cell((string) ($document['piece'] ?? ''), $rowStyle),
                $this->cell((string) ($document['invoicePiece'] ?? ''), $rowStyle),
                $this->cell(in_array((int) ($document['sourceDocumentType'] ?? 3), [6, 7], true) ? 'Facture' : 'BL', $rowStyle),
                $this->cell($this->formatDate($this->dateValue($document['documentDate'] ?? null)), $rowStyle),
                $this->cell(round((float) ($document['amountIncludingTax'] ?? 0), 2), 'number', 'number'),
                $this->cell((string) ($document['hubspotOrderId'] ?? ''), $rowStyle),
                $this->cell($document['category'], $rowStyle),
                $this->cell((string) ($document['errorMessage'] ?? 'Erreur non detaillee.'), $rowStyle),
                $this->cell($document['recommendation'], $rowStyle),
                $this->cell($this->formatDateTime($this->dateValue($document['analyzedAt'] ?? ($document['updatedAt'] ?? null))), $rowStyle),
                $document['link'] !== null ? $this->linkCell('Ouvrir dans HubSpot', $document['link']) : '',
            ];
        }

        return [
            'name' => 'Documents',
            'rows' => $rows,
            'widths' => [14, 18, 34, 18, 18, 12, 15, 14, 18, 30, 65, 75, 20, 24],
            'freezeRows' => 1,
            'filterRow' => 1,
        ];
    }

    /**
     * @return array{category: string, recommendation: string, priority: string, priorityRank: int, link: string|null}
     */
    private function resolveCorrection(string $message, string $clientId): array
    {
        $normalized = $this->normalize($message);

        if (str_contains($normalized, 'aucune societe hubspot')) {
            return $this->correction(
                'Entreprise HubSpot introuvable',
                $clientId !== ''
                    ? sprintf('Creer ou retrouver l entreprise dans HubSpot, puis renseigner la propriete id_erp avec "%s".', $clientId)
                    : 'Creer ou retrouver l entreprise dans HubSpot, puis renseigner sa propriete id_erp.',
                'A corriger',
                1,
                $this->hubspotObjectListUrl('0-2'),
            );
        }

        if (str_contains($normalized, 'plusieurs societes hubspot')) {
            return $this->correction(
                'Reference ERP dupliquee dans HubSpot',
                $clientId !== ''
                    ? sprintf('Rechercher "%s" dans les entreprises HubSpot et ne conserver cette valeur id_erp que sur la bonne entreprise.', $clientId)
                    : 'Identifier les entreprises en doublon et ne conserver la reference id_erp que sur la bonne entreprise.',
                'A corriger',
                1,
                $this->hubspotObjectListUrl('0-2'),
            );
        }

        if (str_contains($normalized, 'api sage') && (str_contains($normalized, '429') || str_contains($normalized, 'quota'))) {
            return $this->correction(
                'Quota API Sage atteint',
                'Aucune donnee entreprise a modifier. Attendre le renouvellement du quota Sage puis relancer la synchronisation.',
                'A relancer',
                2,
                null,
            );
        }

        if (str_contains($normalized, 'api hubspot') && (str_contains($normalized, '429') || str_contains($normalized, 'limit'))) {
            return $this->correction(
                'Limite API HubSpot atteinte',
                'Aucune donnee entreprise a modifier. Attendre la reouverture du quota HubSpot puis relancer la synchronisation.',
                'A relancer',
                2,
                null,
            );
        }

        if (str_contains($normalized, 'plusieurs orders hubspot')) {
            return $this->correction(
                'Orders HubSpot en doublon',
                'Ouvrir les Orders, identifier le doublon et conserver une seule cle externe ou reference pour ce document.',
                'A corriger',
                1,
                $this->hubspotObjectListUrl('0-123'),
            );
        }

        if (str_contains($normalized, 'aucune ligne') || str_contains($normalized, 'quantite invalide')) {
            return $this->correction(
                'Document Sage incomplet',
                'Verifier les lignes, quantites et references article directement dans le document Sage, puis relancer.',
                'A corriger',
                1,
                null,
            );
        }

        if (str_contains($normalized, 'identifiant client sage manquant')) {
            return $this->correction(
                'Client Sage manquant',
                'Completer le tiers/client sur le document Sage, puis relancer la synchronisation.',
                'A corriger',
                1,
                null,
            );
        }

        if (str_contains($normalized, 'discount') && str_contains($normalized, 'invalid_integer')) {
            return $this->correction(
                'Micro-remise incompatible avec HubSpot',
                'Verifier la ligne Sage concernee et supprimer ou arrondir la micro-remise residuelle avant de relancer.',
                'A corriger',
                1,
                null,
            );
        }

        if (str_contains($normalized, 'price') && str_contains($normalized, 'must not be negative')) {
            return $this->correction(
                'Prix negatif refuse par HubSpot',
                'Verifier la ligne negative dans Sage et la transformer en remise ou en avoir compatible avant de relancer.',
                'A corriger',
                1,
                null,
            );
        }

        if (str_contains($normalized, 'api hubspot')) {
            return $this->correction(
                'Erreur de donnees HubSpot',
                'Verifier le message technique, corriger la propriete ou la donnee concernee dans HubSpot, puis relancer.',
                'A verifier',
                3,
                $this->hubspotObjectListUrl('0-123'),
            );
        }

        if (str_contains($normalized, 'api sage')) {
            return $this->correction(
                'Erreur API Sage',
                'Verifier que le document est encore accessible et complet dans Sage, puis relancer la synchronisation.',
                'A verifier',
                3,
                null,
            );
        }

        return $this->correction(
            'Erreur a analyser',
            'Lire le message technique, verifier les donnees de l entreprise et du document dans Sage et HubSpot, puis relancer.',
            'A verifier',
            3,
            $this->hubspotObjectListUrl('0-123'),
        );
    }

    /**
     * @return array{category: string, recommendation: string, priority: string, priorityRank: int, link: string|null}
     */
    private function correction(string $category, string $recommendation, string $priority, int $priorityRank, ?string $link): array
    {
        return compact('category', 'recommendation', 'priority', 'priorityRank', 'link');
    }

    /**
     * @return array<string, mixed>
     */
    private function cell(mixed $value, string $style = 'wrap', ?string $type = null): array
    {
        return array_filter([
            'value' => $value,
            'style' => $style,
            'type' => $type,
        ], static fn (mixed $item): bool => $item !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function number(int|float $value): array
    {
        return $this->cell($value, 'number', 'number');
    }

    /**
     * @return array<string, mixed>
     */
    private function linkCell(string $label, string $url): array
    {
        return ['value' => $label, 'style' => 'link', 'url' => $url];
    }

    private function documentLabel(array $document): string
    {
        $piece = trim((string) ($document['piece'] ?? 'Document inconnu'));
        $invoicePiece = trim((string) ($document['invoicePiece'] ?? ''));

        return $invoicePiece !== '' && $invoicePiece !== $piece ? $piece.' -> '.$invoicePiece : $piece;
    }

    private function formatDate(?\DateTimeInterface $date): string
    {
        return $date?->format('d/m/Y') ?? '';
    }

    private function formatDateTime(?\DateTimeInterface $date): string
    {
        return $date?->format('d/m/Y H:i') ?? '';
    }

    private function dateValue(mixed $value): ?\DateTimeInterface
    {
        return $value instanceof \DateTimeInterface ? $value : null;
    }

    private function earliest(?\DateTimeInterface $current, ?\DateTimeInterface $candidate): ?\DateTimeInterface
    {
        return $candidate !== null && ($current === null || $candidate < $current) ? $candidate : $current;
    }

    private function latest(?\DateTimeInterface $current, ?\DateTimeInterface $candidate): ?\DateTimeInterface
    {
        return $candidate !== null && ($current === null || $candidate > $current) ? $candidate : $current;
    }

    private function isTechnicalCategory(string $category): bool
    {
        return in_array($category, ['Quota API Sage atteint', 'Limite API HubSpot atteinte'], true);
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return is_string($transliterated) ? $transliterated : $value;
    }

    private function hubspotObjectListUrl(string $objectTypeId): string
    {
        return sprintf(
            'https://app-eu1.hubspot.com/contacts/%s/objects/%s/views/all/list',
            rawurlencode($this->hubspotPortalId),
            rawurlencode($objectTypeId),
        );
    }
}
