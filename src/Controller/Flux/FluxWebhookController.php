<?php

namespace App\Controller\Flux;

use App\Repository\HubspotCompanyRepository;
use App\Service\Erp\ErpCompanyExportService;
use App\Service\Erp\ErpOrderExportService;
use App\Service\HubSpot\HubspotCompanySyncService;
use App\Service\Log\SyncLogService;
use App\Service\Mailer\SimpleMailerService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/flux/webhook', name: 'flux_webhook_', methods: ['POST'])]
class FluxWebhookController extends AbstractController
{
    #[Route('', name: 'hubspot')]
    public function hubspot(
        Request $request,
        HubspotCompanySyncService $hubspotCompanySyncService,
        HubspotCompanyRepository $hubspotCompanyRepository,
        ErpCompanyExportService $erpCompanyExportService,
        ErpOrderExportService $erpOrderExportService,
        SyncLogService $syncLogService,
        SimpleMailerService $simpleMailerService,
    ): JsonResponse {
        try {
            $payload = $request->toArray();
        } catch (\Throwable $e) {
            $syncLogService->error(
                'webhook',
                'Webhook HubSpot invalide',
                'Le body JSON du webhook HubSpot est invalide.',
                ['error' => $e->getMessage()]
            );

            return $this->json([
                'processed' => 0,
                'skipped' => 0,
                'errors' => 1,
            ], 400);
        }

        $events = $this->normalizeEvents($payload);
        $companyIds = $this->extractEligibleCompanyIds($events);
        $dealIds = $this->extractEligibleDealIds($events);
        $processed = 0;
        $skipped = 0;
        $errors = [];

        $syncLogService->info(
            'webhook',
            'Webhook HubSpot recu',
            'Webhook HubSpot recu pour surveillance de sage_integration.',
            [
                'events' => count($events),
                'companiesDetected' => count($companyIds),
                'dealsDetected' => count($dealIds),
                'sample' => $events[0] ?? null,
            ]
        );

        foreach ($companyIds as $companyId) {
            try {
                $syncResult = $hubspotCompanySyncService->syncCompanyById($companyId);

                if (($syncResult['skipped'] ?? false) === true) {
                    ++$skipped;
                    continue;
                }

                $company = $hubspotCompanyRepository->findOneByHubspotId($companyId);

                if ($company === null) {
                    throw new \RuntimeException(sprintf('Company %s introuvable apres sync.', $companyId));
                }

                $erpResult = $erpCompanyExportService->sendCompanyToErp($company);

                if (($erpResult['skipped'] ?? false) === true) {
                    ++$skipped;

                    $syncLogService->warning(
                        'webhook',
                        'Webhook HubSpot ignore',
                        'La company a ete synchronisee mais aucun export ERP n a ete envoye.',
                        ['companyHubspotId' => $companyId]
                    );

                    continue;
                }

                ++$processed;

                $syncLogService->success(
                    'webhook',
                    'Webhook HubSpot traite',
                    'La company HubSpot a ete synchronisee puis envoyee vers Sage.',
                    [
                        'companyHubspotId' => $companyId,
                        'companyName' => $company->getName(),
                        'erpAction' => $erpResult['action'] ?? null,
                        'reference' => $erpResult['reference'] ?? null,
                    ]
                );
            } catch (\Throwable $e) {
                $errors[] = [
                    'companyHubspotId' => $companyId,
                    'message' => $e->getMessage(),
                ];

                $syncLogService->error(
                    'webhook',
                    'Erreur webhook HubSpot',
                    'Le traitement du webhook HubSpot a echoue pour une company.',
                    [
                        'companyHubspotId' => $companyId,
                        'error' => $e->getMessage(),
                    ]
                );
            }
        }

        foreach ($dealIds as $dealId) {
            try {
                $erpResult = $erpOrderExportService->sendDealToErp($dealId);

                if (($erpResult['skipped'] ?? false) === true) {
                    ++$skipped;
                    continue;
                }

                ++$processed;

                $syncLogService->success(
                    'webhook',
                    'Webhook HubSpot deal traite',
                    'Le deal HubSpot a ete prepare pour envoi ERP.',
                    [
                        'dealHubspotId' => $dealId,
                        'action' => $erpResult['action'] ?? null,
                        'referenceCommande' => $erpResult['payload']['referenceCommande'] ?? null,
                        'numClient' => $erpResult['payload']['numClient'] ?? null,
                    ]
                );

                $this->sendDealWebhookMail(
                    $simpleMailerService,
                    true,
                    $dealId,
                    $erpResult['payload'] ?? [],
                    null,
                    (string) ($erpResult['action'] ?? 'create')
                );
            } catch (\Throwable $e) {
                $errors[] = [
                    'dealHubspotId' => $dealId,
                    'message' => $e->getMessage(),
                ];

                $syncLogService->error(
                    'webhook',
                    'Erreur webhook HubSpot deal',
                    'Le traitement du webhook HubSpot a echoue pour un deal.',
                    [
                        'dealHubspotId' => $dealId,
                        'error' => $e->getMessage(),
                    ]
                );

                $this->sendDealWebhookMail(
                    $simpleMailerService,
                    false,
                    $dealId,
                    [],
                    $e->getMessage(),
                    'create'
                );
            }
        }

        if ($events !== []) {
            $syncLogService->info(
                'webhook',
                'Webhook HubSpot termine',
                'Webhook HubSpot traite.',
                [
                    'events' => count($events),
                    'companiesDetected' => count($companyIds),
                    'dealsDetected' => count($dealIds),
                    'processed' => $processed,
                    'skipped' => $skipped,
                    'errors' => count($errors),
                ]
            );
        }

        return $this->json([
            'processed' => $processed,
            'skipped' => $skipped,
            'errors' => count($errors),
            'details' => $errors,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeEvents(mixed $payload): array
    {
        if (\is_array($payload) && array_is_list($payload)) {
            return array_values(array_filter($payload, 'is_array'));
        }

        if (\is_array($payload) && isset($payload['events']) && \is_array($payload['events'])) {
            return array_values(array_filter($payload['events'], 'is_array'));
        }

        if (\is_array($payload) && isset($payload['eventId'])) {
            return [$payload];
        }

        return [];
    }

    /**
     * @param array<int, array<string, mixed>> $events
     *
     * @return array<int, string>
     */
    private function extractEligibleCompanyIds(array $events): array
    {
        $companyIds = [];

        foreach ($events as $event) {
            $propertyName = mb_strtolower(trim((string) ($event['propertyName'] ?? '')));
            $propertyValue = mb_strtolower(trim((string) ($event['propertyValue'] ?? '')));
            $objectId = trim((string) ($event['objectId'] ?? ''));

            if ($propertyName !== 'sage_integration') {
                continue;
            }

            if (!$this->isTruthyWebhookValue($propertyValue)) {
                continue;
            }

            if ($objectId === '') {
                continue;
            }

            $looksLikeCompanyEvent = $objectType === 'company'
                || str_contains($subscriptionType, 'company.')
                || str_contains($subscriptionType, 'companies.');

            if (!$looksLikeCompanyEvent) {
                continue;
            }

            $companyIds[$objectId] = $objectId;
        }

        return array_values($companyIds);
    }

    /**
     * @param array<int, array<string, mixed>> $events
     *
     * @return array<int, string>
     */
    private function extractEligibleDealIds(array $events): array
    {
        $dealIds = [];

        foreach ($events as $event) {
            $propertyName = mb_strtolower(trim((string) ($event['propertyName'] ?? '')));
            $propertyValue = mb_strtolower(trim((string) ($event['propertyValue'] ?? '')));
            $objectType = mb_strtolower(trim((string) ($event['objectType'] ?? '')));
            $subscriptionType = mb_strtolower(trim((string) ($event['subscriptionType'] ?? '')));
            $objectId = trim((string) ($event['objectId'] ?? ''));

            if ($propertyName !== 'integrate_into_sage') {
                continue;
            }

            if (!$this->isTruthyWebhookValue($propertyValue)) {
                continue;
            }

            if ($objectId === '') {
                continue;
            }

            $dealIds[$objectId] = $objectId;
        }

        return array_values($dealIds);
    }

    private function isTruthyWebhookValue(string $propertyValue): bool
    {
        return in_array($propertyValue, ['yes', 'true', '1', 'on'], true);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sendDealWebhookMail(
        SimpleMailerService $simpleMailerService,
        bool $success,
        string $dealId,
        array $payload,
        ?string $errorMessage,
        string $action = 'create',
    ): void {
        $successVerb = $action === 'update' ? 'mis a jour' : 'cree';
        $subject = $success
            ? sprintf('[LNS Connecteur] Bon de commande %s pour le deal %s', $successVerb, $dealId)
            : sprintf('[LNS Connecteur] Echec creation bon de commande pour le deal %s', $dealId);

        $lines = [
            $success
                ? sprintf('Le webhook deal HubSpot a %s le bon de commande ERP avec succes.', $successVerb)
                : 'Le webhook deal HubSpot a echoue lors de la creation du bon de commande ERP.',
            '',
            sprintf('Deal HubSpot: %s', $dealId),
        ];

        if ($success && $payload !== []) {
            $lines[] = sprintf('Reference commande: %s', (string) ($payload['referenceCommande'] ?? ''));
            $lines[] = sprintf('Client ERP: %s', (string) ($payload['numClient'] ?? ''));
            $lines[] = sprintf('Nombre de lignes: %d', is_array($payload['orderLines'] ?? null) ? count($payload['orderLines']) : 0);
            $lines[] = '';
            $lines[] = 'Payload ERP:';
            $lines[] = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        }

        if (!$success) {
            $lines[] = '';
            $lines[] = 'Erreur:';
            $lines[] = $errorMessage ?? 'Erreur inconnue.';
        }

        try {
            if ($success) {
                $simpleMailerService->sendTemplateMessage(
                    $subject,
                    'mailer/order_webhook_success.html.twig',
                    [
                        'subject' => $subject,
                        'dealId' => $dealId,
                        'payload' => $payload,
                    ],
                    implode("\n", $lines)
                );

                return;
            }

            $simpleMailerService->sendTemplateMessage(
                $subject,
                'mailer/order_webhook_failure.html.twig',
                [
                    'subject' => $subject,
                    'dealId' => $dealId,
                    'errorMessage' => $errorMessage ?? 'Erreur inconnue.',
                ],
                implode("\n", $lines)
            );
        } catch (\Throwable) {
            // Le mail informatif ne doit pas faire echouer le webhook lui-meme.
        }
    }
}
