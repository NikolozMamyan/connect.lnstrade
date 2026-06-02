<?php

namespace App\Service\HubSpot;

use App\Entity\Commercial;
use App\Entity\OrderForm;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class HubspotOrderFormDealSyncService
{
    private const DEAL_OBJECT_TYPE = 'deals';
    private const LINE_ITEM_OBJECT_TYPE = 'line_items';
    private const DEAL_TO_COMPANY_PRIMARY_ASSOCIATION_TYPE_ID = 5;
    private const LINE_ITEM_TO_DEAL_ASSOCIATION_TYPE_ID = 20;
    private const COMPANY_PROPERTIES = ['name', 'id_erp', 'company_country_en'];

    public function __construct(
        private readonly HubSpotClient $hubSpotClient,
        #[Autowire('%hubspot_order_form_pipeline_label%')]
        private readonly string $configuredPipelineLabel,
        #[Autowire('%hubspot_order_form_pipeline_value%')]
        private readonly string $configuredPipelineValue,
        #[Autowire('%hubspot_order_form_stage_label%')]
        private readonly string $configuredStageLabel,
        #[Autowire('%hubspot_order_form_tax_rate_group_ids%')]
        private readonly array $taxRateGroupIds,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $lineItems
     *
     * @return array{
     *   success: bool,
     *   hubspotDealId: ?string,
     *   errors: array<int, array<string, mixed>>,
     *   warnings: array<int, array<string, mixed>>
     * }
     */
    public function sync(OrderForm $orderForm, array $lineItems): array
    {
        $validation = $this->validateContext($orderForm, $lineItems);

        if (($validation['success'] ?? false) !== true) {
            return [
                'success' => false,
                'hubspotDealId' => null,
                'errors' => $validation['errors'] ?? [],
                'warnings' => [],
            ];
        }

        /** @var Commercial $commercial */
        $commercial = $validation['commercial'];
        $hubspotOwnerId = (string) ($validation['hubspotOwnerId'] ?? '');
        $companyName = (string) ($validation['companyName'] ?? '');
        $companyCountry = (string) ($validation['companyCountry'] ?? '');
        /** @var array<string, array<string, mixed>> $productsByReference */
        $productsByReference = $validation['productsByReference'];
        $hubspotDealId = $validation['hubspotDealId'];
        $applyTaxRate = $this->shouldApplyTaxRate($companyCountry);
        $dealAmount = $this->calculateDealAmount($lineItems);
        $warnings = [];

        if ($orderForm->getDealType() === OrderForm::DEAL_TYPE_NOUVEAU) {
            $hubspotDealId = $this->createNewDeal($orderForm, $commercial, $hubspotOwnerId, $companyName, $dealAmount);
        }

        if ($orderForm->getDealType() === OrderForm::DEAL_TYPE_EXISTANT) {
            $replacement = $this->clearExistingLineItems((string) $hubspotDealId);

            if (($replacement['success'] ?? false) !== true) {
                return [
                    'success' => false,
                    'hubspotDealId' => (string) $hubspotDealId,
                    'errors' => $replacement['errors'] ?? [],
                    'warnings' => $warnings,
                ];
            }
        }

        foreach ($lineItems as $lineItem) {
            $reference = (string) ($lineItem['articleRef'] ?? '');
            $product = $productsByReference[$reference] ?? null;

            if (!is_array($product)) {
                throw new \RuntimeException(sprintf('Produit %s introuvable dans le contexte de synchronisation.', $reference));
            }

            $taxRateGroupId = null;

            if ($applyTaxRate) {
                $taxRateResolution = $this->resolveTaxRateGroupIdForProduct($product, $reference);

                if (($taxRateResolution['success'] ?? false) !== true) {
                    return [
                        'success' => false,
                        'hubspotDealId' => null,
                        'errors' => $taxRateResolution['errors'] ?? [],
                        'warnings' => $warnings,
                    ];
                }

                $warnings = array_merge($warnings, $taxRateResolution['warnings'] ?? []);
                $resolvedTaxRateGroupId = $taxRateResolution['taxRateGroupId'] ?? null;
                $taxRateGroupId = is_string($resolvedTaxRateGroupId) ? $resolvedTaxRateGroupId : null;
            }

            $this->createLineItem(
                $hubspotDealId,
                $product,
                $lineItem,
                $taxRateGroupId
            );
        }

        if ($orderForm->getDealType() === OrderForm::DEAL_TYPE_EXISTANT) {
            $this->updateDealAmount((string) $hubspotDealId, $dealAmount);
        }

        return [
            'success' => true,
            'hubspotDealId' => $hubspotDealId,
            'errors' => [],
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $lineItems
     *
     * @return array<string, mixed>
     */
    private function validateContext(OrderForm $orderForm, array $lineItems): array
    {
        $errors = [];
        $commercial = $orderForm->getCommercial();
        $hubspotOwnerId = null;
        $companyName = null;
        $companyCountry = null;

        if (!$commercial instanceof Commercial) {
            $errors[] = $this->error('commercial', 'Aucun commercial valide n est associe a la soumission.');
        } else {
            try {
                $hubspotOwnerId = $this->resolveHubspotOwnerId($commercial);
            } catch (\Throwable $exception) {
                $errors[] = $this->error(
                    'commercial',
                    sprintf('Le commercial %s n a pas de owner HubSpot exploitable.', $commercial->getFullName()),
                    ['details' => $exception->getMessage()]
                );
            }
        }

        $hubspotDealId = null;

        if ($orderForm->getDealType() === OrderForm::DEAL_TYPE_NOUVEAU) {
            $enterpriseId = trim((string) $orderForm->getEnterpriseId());

            if (!$this->looksLikeHubspotId($enterpriseId)) {
                $errors[] = $this->error('enterpriseId', 'L identifiant entreprise HubSpot est invalide.');
            } else {
                try {
                    $company = $this->hubSpotClient->getObject('companies', $enterpriseId, ['properties' => self::COMPANY_PROPERTIES]);
                    $companyName = trim((string) (($company['properties']['name'] ?? null) ?: ''));
                    $companyCountry = $this->extractCompanyCountry($company);
                    $companyErpId = trim((string) (($company['properties']['id_erp'] ?? null) ?: ''));

                    if ($companyErpId === '') {
                        $errors[] = $this->error(
                            'enterpriseId',
                            'Cette entreprise HubSpot n a pas de id_erp. La soumission du formulaire est refusee.'
                        );
                    }
                } catch (\Throwable $exception) {
                    $errors[] = $this->error(
                        'enterpriseId',
                        sprintf('L entreprise HubSpot %s est introuvable ou inaccessible.', $enterpriseId),
                        ['details' => $exception->getMessage()]
                    );
                }
            }
        }

        if ($orderForm->getDealType() === OrderForm::DEAL_TYPE_EXISTANT) {
            $dealId = trim((string) $orderForm->getDealId());

            if (!$this->looksLikeHubspotId($dealId)) {
                $errors[] = $this->error('dealId', 'Le deal HubSpot est invalide.');
            } else {
                try {
                    $response = $this->hubSpotClient->getObject('deals', $dealId, [
                        'properties' => ['dealname'],
                        'associations' => ['companies'],
                    ]);
                    $hubspotDealId = (string) ($response['id'] ?? $dealId);

                    $companyResults = $response['associations']['companies']['results'] ?? null;

                    if (!is_array($companyResults) || $companyResults === []) {
                        $errors[] = $this->error(
                            'dealId',
                            'Le deal HubSpot n est associe a aucune entreprise. La soumission du formulaire est refusee.'
                        );
                    } else {
                        $companyId = trim((string) (($companyResults[0]['id'] ?? null) ?: ''));

                        if ($companyId === '') {
                            $errors[] = $this->error(
                                'dealId',
                                'Le deal HubSpot n est associe a aucune entreprise exploitable. La soumission du formulaire est refusee.'
                            );
                        } else {
                            $company = $this->hubSpotClient->getObject('companies', $companyId, ['properties' => self::COMPANY_PROPERTIES]);
                            $companyCountry = $this->extractCompanyCountry($company);
                            $companyErpId = trim((string) (($company['properties']['id_erp'] ?? null) ?: ''));

                            if ($companyErpId === '') {
                                $errors[] = $this->error(
                                    'dealId',
                                    'L entreprise associee a ce deal HubSpot n a pas de id_erp. La soumission du formulaire est refusee.'
                                );
                            }
                        }
                    }
                } catch (\Throwable $exception) {
                    $errors[] = $this->error(
                        'dealId',
                        sprintf('Le deal HubSpot %s est introuvable ou inaccessible.', $dealId),
                        ['details' => $exception->getMessage()]
                    );
                }
            }
        }

        $productsByReference = [];

        foreach ($lineItems as $lineItem) {
            $reference = trim((string) ($lineItem['articleRef'] ?? ''));

            if ($reference === '' || isset($productsByReference[$reference])) {
                continue;
            }

            try {
                $product = $this->findHubspotProductByReference($reference);
            } catch (\Throwable $exception) {
                $errors[] = $this->error(
                    'articleRef',
                    sprintf('La verification HubSpot du produit %s a echoue.', $reference),
                    [
                        'reference' => $reference,
                        'details' => $exception->getMessage(),
                    ]
                );
                continue;
            }

            if ($product === null) {
                $errors[] = $this->error('articleRef', sprintf('La reference produit %s est introuvable dans HubSpot.', $reference), ['reference' => $reference]);
                continue;
            }

            $productsByReference[$reference] = $product;
        }

        if ($errors !== []) {
            return [
                'success' => false,
                'errors' => $errors,
            ];
        }

        return [
            'success' => true,
            'commercial' => $commercial,
            'hubspotOwnerId' => $hubspotOwnerId,
            'companyName' => $companyName,
            'companyCountry' => $companyCountry,
            'hubspotDealId' => $hubspotDealId,
            'productsByReference' => $productsByReference,
        ];
    }

    private function createNewDeal(OrderForm $orderForm, Commercial $commercial, string $hubspotOwnerId, string $companyName, float $dealAmount): string
    {
        $enterpriseId = (string) $orderForm->getEnterpriseId();
        $pipelineStage = $this->resolveDealPipelineStage();
        $properties = [
            'dealname' => $this->buildDealName($orderForm, $companyName),
            'hubspot_owner_id' => $hubspotOwnerId,
            'pipeline' => $pipelineStage['pipelineId'],
            'dealstage' => $pipelineStage['stageId'],
            'amount' => $this->formatHubspotAmount($dealAmount),
        ];

        $response = $this->hubSpotClient->createObject(self::DEAL_OBJECT_TYPE, $properties, [
            [
                'to' => [
                    'id' => $enterpriseId,
                ],
                'types' => [
                    [
                        'associationCategory' => 'HUBSPOT_DEFINED',
                        'associationTypeId' => self::DEAL_TO_COMPANY_PRIMARY_ASSOCIATION_TYPE_ID,
                    ],
                ],
            ],
        ]);

        $dealId = (string) ($response['id'] ?? '');

        if ($dealId === '') {
            throw new \RuntimeException($this->extractHubspotErrorMessage(
                $response,
                'HubSpot a repondu sans identifiant de deal.'
            ));
        }

        return $dealId;
    }

    /**
     * @param array<int, array<string, mixed>> $lineItems
     */
    private function calculateDealAmount(array $lineItems): float
    {
        $totalAmount = 0.0;

        foreach ($lineItems as $lineItem) {
            $quantity = (float) ($lineItem['quantity'] ?? 0);
            $unitPrice = (float) ($lineItem['unitPrice'] ?? 0);

            if ($quantity !== 0.0 || $unitPrice !== 0.0) {
                $totalAmount += $quantity * $unitPrice;
                continue;
            }

            $totalAmount += (float) ($lineItem['lineTotal'] ?? 0);
        }

        return round($totalAmount, 2);
    }

    private function updateDealAmount(string $hubspotDealId, float $dealAmount): void
    {
        $this->hubSpotClient->updateObject(self::DEAL_OBJECT_TYPE, $hubspotDealId, [
            'amount' => $this->formatHubspotAmount($dealAmount),
        ]);
    }

    private function formatHubspotAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /**
     * @param array<string, mixed> $product
     * @param array<string, mixed> $lineItem
     */
    private function createLineItem(string $hubspotDealId, array $product, array $lineItem, ?string $taxRateGroupId): void
    {
        $productId = (string) ($product['id'] ?? '');
        $productProperties = isset($product['properties']) && is_array($product['properties']) ? $product['properties'] : [];
        $productName = trim((string) ($productProperties['name'] ?? ''));
        $productSku = trim((string) ($productProperties['hs_sku'] ?? ''));

        if ($productId === '') {
            throw new \RuntimeException('HubSpot a retourne un produit sans identifiant.');
        }

        $properties = [
            'hs_product_id' => $productId,
            'name' => $productName !== '' ? $productName : (string) ($lineItem['articleRef'] ?? 'Line item'),
            'quantity' => (float) ($lineItem['quantity'] ?? 0),
            'price' => (float) ($lineItem['unitPrice'] ?? 0),
        ];

        if ($taxRateGroupId !== null && trim($taxRateGroupId) !== '') {
            $properties['hs_tax_rate_group_id'] = $taxRateGroupId;
        }

        if (!empty($lineItem['description'])) {
            $properties['description'] = (string) $lineItem['description'];
        }

        if (!empty($lineItem['eanUnit'])) {
            $properties['hs_sku'] = (string) $lineItem['eanUnit'];
        } elseif ($productSku !== '') {
            $properties['hs_sku'] = $productSku;
        } elseif (!empty($lineItem['articleRef'])) {
            $properties['hs_sku'] = (string) $lineItem['articleRef'];
        }

        $this->hubSpotClient->createObject(self::LINE_ITEM_OBJECT_TYPE, $properties, [
            [
                'to' => [
                    'id' => $hubspotDealId,
                ],
                'types' => [
                    [
                        'associationCategory' => 'HUBSPOT_DEFINED',
                        'associationTypeId' => self::LINE_ITEM_TO_DEAL_ASSOCIATION_TYPE_ID,
                    ],
                ],
            ],
        ]);
    }

    /**
     * @return array{success: bool, errors?: array<int, array<string, mixed>>}
     */
    private function clearExistingLineItems(string $hubspotDealId): array
    {
        try {
            $lineItemIds = $this->fetchExistingLineItemIds($hubspotDealId);
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'errors' => [$this->error(
                    'lineItems',
                    sprintf('La recuperation des line items existants du deal HubSpot %s a echoue.', $hubspotDealId),
                    ['details' => $exception->getMessage()]
                )],
            ];
        }

        $errors = [];

        foreach ($lineItemIds as $lineItemId) {
            try {
                $this->hubSpotClient->deleteObject(self::LINE_ITEM_OBJECT_TYPE, $lineItemId);
            } catch (\Throwable $exception) {
                $errors[] = $this->error(
                    'lineItems',
                    sprintf('La suppression du line item HubSpot %s a echoue.', $lineItemId),
                    [
                        'lineItemId' => $lineItemId,
                        'details' => $exception->getMessage(),
                    ]
                );
            }
        }

        if ($errors !== []) {
            return [
                'success' => false,
                'errors' => $errors,
            ];
        }

        return [
            'success' => true,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function fetchExistingLineItemIds(string $hubspotDealId): array
    {
        if (trim($hubspotDealId) === '') {
            return [];
        }

        $paths = [
            sprintf('/crm/v3/objects/deals/%s/associations/line_items', $hubspotDealId),
            sprintf('/crm/v3/objects/deals/%s/associations/line_item', $hubspotDealId),
        ];
        $lineItemIds = [];
        $successfulLookup = false;
        $lastException = null;

        foreach ($paths as $path) {
            try {
                $response = $this->hubSpotClient->get($path);
                $successfulLookup = true;
            } catch (\Throwable $exception) {
                $lastException = $exception;
                continue;
            }

            $results = $response['results'] ?? [];

            if (!is_array($results)) {
                continue;
            }

            foreach ($results as $result) {
                if (!is_array($result)) {
                    continue;
                }

                $lineItemId = trim((string) (($result['id'] ?? null) ?: ($result['toObjectId'] ?? '')));

                if ($lineItemId !== '') {
                    $lineItemIds[] = $lineItemId;
                }
            }

            if ($lineItemIds !== []) {
                break;
            }
        }

        if (!$successfulLookup && $lastException instanceof \Throwable) {
            throw $lastException;
        }

        return array_values(array_unique($lineItemIds));
    }

    private function buildDealName(OrderForm $orderForm, string $companyName): string
    {
        $companyName = trim($companyName);

        if ($companyName !== '') {
            return sprintf('%s | via LNS Connecteur', $companyName);
        }

        return sprintf(
            'Entreprise %s | via LNS Connecteur',
            $orderForm->getEnterpriseId() ?? 'inconnue'
        );
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function error(string $field, string $message, array $context = []): array
    {
        return array_merge([
            'field' => $field,
            'message' => $message,
        ], $context);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function warning(string $field, string $message, array $context = []): array
    {
        return array_merge([
            'field' => $field,
            'message' => $message,
        ], $context);
    }

    private function looksLikeHubspotId(?string $value): bool
    {
        return $value !== null && preg_match('/^\d+$/', trim($value)) === 1;
    }

    private function resolveHubspotOwnerId(Commercial $commercial): string
    {
        $email = trim((string) $commercial->getEmail());

        if ($email !== '') {
            $response = $this->hubSpotClient->get('/crm/owners/2026-03', [
                'email' => $email,
                'limit' => 1,
            ]);

            $owner = $response['results'][0] ?? null;

            if (is_array($owner)) {
                $ownerId = trim((string) ($owner['id'] ?? ''));

                if ($this->looksLikeHubspotId($ownerId)) {
                    return $ownerId;
                }
            }
        }

        $candidate = trim((string) $commercial->getHubspotId());

        if ($this->looksLikeHubspotId($candidate)) {
            $owner = $this->hubSpotClient->get(sprintf('/crm/v3/owners/%s', $candidate), [
                'idProperty' => 'userId',
            ]);
            $ownerId = trim((string) ($owner['id'] ?? ''));

            if ($this->looksLikeHubspotId($ownerId)) {
                return $ownerId;
            }
        }

        throw new \RuntimeException(sprintf(
            'Aucun owner HubSpot valide trouve pour %s.',
            $email !== '' ? $email : $commercial->getFullName()
        ));
    }

    /**
     * @return array{success: bool, taxRateGroupId?: string, errors?: array<int, array<string, mixed>>, warnings?: array<int, array<string, mixed>>}
     */
    private function resolveTaxRateGroupIdForProduct(array $product, string $reference): array
    {
        $vatRate = $this->resolveVatRateFromHubspotProduct($product);

        if ($vatRate === null) {
            return [
                'success' => true,
                'warnings' => [$this->warning(
                    'taxRate',
                    sprintf('Aucune TVA exploitable n est renseignee sur le produit HubSpot %s. Le line item sera envoye sans TVA.', $reference),
                    [
                        'reference' => $reference,
                    ]
                )],
            ];
        }

        $mappedTaxRateId = $this->taxRateGroupIds[$vatRate] ?? null;

        if (is_string($mappedTaxRateId) && $this->looksLikeHubspotId($mappedTaxRateId)) {
            return [
                'success' => true,
                'taxRateGroupId' => $mappedTaxRateId,
            ];
        }

        return [
            'success' => false,
            'errors' => [$this->error(
                'taxRate',
                sprintf('Aucun hs_tax_rate_group_id n est configure pour la TVA %s sur la reference %s.', $vatRate, $reference)
            )],
        ];
    }

    /**
     * @param array<string, mixed> $company
     */
    private function extractCompanyCountry(array $company): ?string
    {
        $properties = isset($company['properties']) && is_array($company['properties']) ? $company['properties'] : [];
        $country = trim((string) (($properties['company_country_en'] ?? null) ?: ''));

        return $country !== '' ? $country : null;
    }

    private function shouldApplyTaxRate(?string $companyCountry): bool
    {
        $normalizedCountry = preg_replace('/[^a-z]/', '', mb_strtolower(trim((string) $companyCountry))) ?? '';

        return $normalizedCountry === 'france' || $normalizedCountry === 'fr';
    }

    /**
     * @param array<string, mixed> $product
     */
    private function resolveVatRateFromHubspotProduct(array $product): ?string
    {
        $properties = isset($product['properties']) && is_array($product['properties']) ? $product['properties'] : [];
        $vat = trim((string) (($properties['vat'] ?? null) ?: ''));

        if ($vat === '') {
            return null;
        }

        $normalizedVat = html_entity_decode($vat, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalizedVat = str_replace(["\xc2\xa0", ',', '_'], [' ', '.', '.'], $normalizedVat);
        $normalizedVat = preg_replace('/(?<=\d)\s+(?=\d)/', '.', $normalizedVat) ?? $normalizedVat;
        $normalizedVat = preg_replace('/\.+/', '.', $normalizedVat) ?? $normalizedVat;

        if (preg_match('/\d+(?:\.\d+)?/', $normalizedVat, $matches) !== 1) {
            return null;
        }

        $rate = (float) $matches[0];

        if ($rate > 0.0 && $rate < 1.0) {
            $rate *= 100.0;
        }

        return match (true) {
            abs($rate - 5.5) < 0.0001 => '5.5',
            abs($rate - 20.0) < 0.0001 => '20',
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $response
     */
    private function extractHubspotErrorMessage(array $response, string $fallback): string
    {
        $parts = [];

        if (!empty($response['message']) && is_string($response['message'])) {
            $parts[] = trim($response['message']);
        }

        if (!empty($response['category']) && is_string($response['category'])) {
            $parts[] = sprintf('Categorie: %s.', trim($response['category']));
        }

        if (!empty($response['errors']) && is_array($response['errors'])) {
            foreach ($response['errors'] as $error) {
                if (!is_array($error)) {
                    continue;
                }

                $errorMessage = trim((string) ($error['message'] ?? ''));
                $errorCode = trim((string) ($error['code'] ?? ''));

                if ($errorMessage !== '' && $errorCode !== '') {
                    $parts[] = sprintf('%s (%s).', $errorMessage, $errorCode);
                    continue;
                }

                if ($errorMessage !== '') {
                    $parts[] = $errorMessage;
                }
            }
        }

        $message = trim(implode(' ', $parts));

        return $message !== '' ? $message : $fallback;
    }

    /**
     * @return array{pipelineId: string, stageId: string}
     */
    private function resolveDealPipelineStage(): array
    {
        $targetPipelineLabel = trim($this->configuredPipelineLabel);
        $targetPipelineValue = trim($this->configuredPipelineValue);
        $targetStageLabel = trim($this->configuredStageLabel);

        if ($targetPipelineValue !== '') {
            $pipeline = $this->hubSpotClient->get(sprintf('/crm/v3/pipelines/deals/%s', $targetPipelineValue));
            $stageId = $this->findStageIdInPipeline($pipeline, $targetStageLabel);

            if ($stageId !== null) {
                return [
                    'pipelineId' => $targetPipelineValue,
                    'stageId' => $stageId,
                ];
            }

            throw new \RuntimeException(sprintf(
                'Le stage HubSpot "%s" est introuvable dans le pipeline "%s" (%s).',
                $targetStageLabel,
                $targetPipelineLabel,
                $targetPipelineValue
            ));
        }

        throw new \RuntimeException(sprintf(
            'Le pipeline HubSpot "%s" (%s) est introuvable.',
            $targetPipelineLabel,
            $targetPipelineValue !== '' ? $targetPipelineValue : 'aucune valeur'
        ));
    }

    /**
     * @param array<string, mixed> $pipeline
     */
    private function findStageIdInPipeline(array $pipeline, string $targetStageLabel): ?string
    {
        foreach (($pipeline['stages'] ?? []) as $stage) {
            if (!is_array($stage)) {
                continue;
            }

            $stageLabel = trim((string) ($stage['label'] ?? ''));
            $stageId = trim((string) (($stage['stageId'] ?? null) ?: ($stage['id'] ?? '')));

            if ($stageLabel !== '' && mb_strtolower($stageLabel) === mb_strtolower($targetStageLabel) && $stageId !== '') {
                return $stageId;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findHubspotProductByReference(string $reference): ?array
    {
        $response = $this->hubSpotClient->searchObjects('products', [
            'limit' => 1,
            'properties' => ['name', 'hs_sku', 'vat'],
            'filterGroups' => [
                [
                    'filters' => [
                        [
                            'propertyName' => 'hs_sku',
                            'operator' => 'EQ',
                            'value' => $reference,
                        ],
                    ],
                ],
            ],
        ]);

        $result = $response['results'][0] ?? null;

        return is_array($result) ? $result : null;
    }
}
