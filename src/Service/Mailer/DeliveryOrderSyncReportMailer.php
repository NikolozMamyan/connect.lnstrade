<?php

namespace App\Service\Mailer;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class DeliveryOrderSyncReportMailer
{
    public function __construct(
        private readonly SimpleMailerService $mailer,
        #[Autowire('%delivery_order_report_recipient%')]
        private readonly string $recipient,
        #[Autowire('%hubspot_portal_id%')]
        private readonly string $hubspotPortalId,
    ) {
    }

    /**
     * @param array<string, mixed> $result
     */
    public function sendCompleted(\DateTimeImmutable $dateFrom, \DateTimeImmutable $dateTo, array $result): void
    {
        $errors = $this->prepareIssues($result['errors'] ?? [], 'error');
        $warnings = $this->prepareIssues($result['warnings'] ?? [], 'warning');
        $hasErrors = $errors !== [];
        $subject = match (true) {
            $hasErrors => sprintf('[LNS Connecteur] Orders HubSpot : %d erreur(s) a corriger', count($errors)),
            $warnings !== [] => sprintf('[LNS Connecteur] Orders HubSpot : reussie, %d point(s) a verifier', count($warnings)),
            default => '[LNS Connecteur] Orders HubSpot : synchronisation reussie',
        };

        $this->mailer->sendTemplateMessage(
            $subject,
            'mailer/delivery_order_sync_report.html.twig',
            [
                'subject' => $subject,
                'completed' => true,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'result' => $result,
                'errors' => $errors,
                'warnings' => $warnings,
                'fatalError' => null,
                'hubspotOrdersUrl' => $this->hubspotObjectListUrl('0-123'),
            ],
            $this->buildTextReport($dateFrom, $dateTo, $result, $errors, $warnings),
            [$this->recipient],
        );
    }

    public function sendFailed(\DateTimeImmutable $dateFrom, \DateTimeImmutable $dateTo, \Throwable $exception): void
    {
        $subject = '[LNS Connecteur] Orders HubSpot : synchronisation interrompue';

        $this->mailer->sendTemplateMessage(
            $subject,
            'mailer/delivery_order_sync_report.html.twig',
            [
                'subject' => $subject,
                'completed' => false,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'result' => [],
                'errors' => [],
                'warnings' => [],
                'fatalError' => $exception->getMessage(),
                'hubspotOrdersUrl' => $this->hubspotObjectListUrl('0-123'),
            ],
            implode("\n", [
                'La synchronisation des Orders HubSpot a ete interrompue.',
                sprintf('Periode : %s au %s', $dateFrom->format('d/m/Y'), $dateTo->format('d/m/Y')),
                sprintf('Erreur : %s', $exception->getMessage()),
            ]),
            [$this->recipient],
        );
    }

    /**
     * @param array<int, array<string, mixed>> $issues
     *
     * @return array<int, array<string, mixed>>
     */
    private function prepareIssues(array $issues, string $severity): array
    {
        return array_values(array_map(function (array $issue) use ($severity): array {
            $message = (string) ($issue['message'] ?? 'Probleme non detaille.');
            [$actionLabel, $actionUrl, $actionHint] = $this->resolveAction($message, (string) ($issue['clientId'] ?? ''));

            return $issue + [
                'severity' => $severity,
                'actionLabel' => $actionLabel,
                'actionUrl' => $actionUrl,
                'actionHint' => $actionHint,
            ];
        }, $issues));
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    private function resolveAction(string $message, string $clientId): array
    {
        $normalized = mb_strtolower($message);

        if (str_contains($normalized, 'societe hubspot')) {
            return [
                'Ouvrir les entreprises HubSpot',
                $this->hubspotObjectListUrl('0-2'),
                $clientId !== ''
                    ? sprintf('Recherchez le client %s, puis renseignez sa propriete id_erp avec cette valeur.', $clientId)
                    : 'Retrouvez l entreprise, puis completez sa propriete id_erp.',
            ];
        }

        if (str_contains($normalized, 'contact hubspot') || str_contains($normalized, 'contact sage')) {
            return [
                'Ouvrir les contacts HubSpot',
                $this->hubspotObjectListUrl('0-1'),
                'Verifiez l adresse email et l identifiant HubSpot du contact.',
            ];
        }

        if (str_contains($normalized, 'produit hubspot') || str_contains($normalized, 'sku')) {
            return [
                'Ouvrir les produits HubSpot',
                $this->hubspotObjectListUrl('0-7'),
                'Recherchez le SKU indique et completez ou creez le produit manquant.',
            ];
        }

        if (str_contains($normalized, 'owner hubspot') || str_contains($normalized, 'commercial hubspot')) {
            return [
                'Ouvrir les utilisateurs HubSpot',
                $this->hubspotUsersUrl(),
                'Verifiez que le commercial Sage possede le meme email dans HubSpot.',
            ];
        }

        return [null, null, null];
    }

    /**
     * @param array<string, mixed> $result
     * @param array<int, array<string, mixed>> $errors
     * @param array<int, array<string, mixed>> $warnings
     */
    private function buildTextReport(
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        array $result,
        array $errors,
        array $warnings,
    ): string {
        $lines = [
            'Synchronisation des Orders HubSpot terminee.',
            sprintf('Periode : %s au %s', $dateFrom->format('d/m/Y'), $dateTo->format('d/m/Y')),
            '',
            sprintf('BL analyses : %d', (int) ($result['deliveryNotesAnalyzed'] ?? 0)),
            sprintf('Factures analysees : %d', (int) ($result['invoicesAnalyzed'] ?? 0)),
            sprintf('Orders crees : %d', (int) ($result['sent'] ?? 0)),
            sprintf('Orders actualises : %d', (int) ($result['updated'] ?? 0)),
            sprintf('Documents ignores : %d', (int) ($result['skipped'] ?? 0)),
            sprintf('Erreurs : %d', count($errors)),
            sprintf('Points de vigilance : %d', count($warnings)),
        ];

        foreach (array_merge($errors, $warnings) as $issue) {
            $lines[] = '';
            $lines[] = sprintf(
                '- %s %s | %s (%s) : %s',
                (string) ($issue['documentLabel'] ?? 'Document'),
                (string) ($issue['piece'] ?? 'N/A'),
                (string) ($issue['clientName'] ?? 'Entreprise inconnue'),
                (string) ($issue['clientId'] ?? 'N/A'),
                (string) ($issue['message'] ?? 'Probleme non detaille.'),
            );

            if (!empty($issue['actionUrl'])) {
                $lines[] = sprintf('  Action : %s', (string) $issue['actionUrl']);
            }
        }

        return implode("\n", $lines);
    }

    private function hubspotObjectListUrl(string $objectTypeId): string
    {
        return sprintf(
            'https://app-eu1.hubspot.com/contacts/%s/objects/%s/views/all/list',
            rawurlencode($this->hubspotPortalId),
            rawurlencode($objectTypeId),
        );
    }

    private function hubspotUsersUrl(): string
    {
        return sprintf('https://app-eu1.hubspot.com/settings/%s/users', rawurlencode($this->hubspotPortalId));
    }
}
