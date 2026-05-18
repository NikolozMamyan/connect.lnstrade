<?php

namespace App\Service\HubSpot;

use App\Entity\Commercial;
use App\Entity\OrderForm;
use App\Repository\ErpProductRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class HubspotOrderFormDealSyncService
{
    private const DEAL_OBJECT_TYPE = 'deals';
    private const DEAL_TO_COMPANY_PRIMARY_ASSOCIATION_TYPE_ID = 5;
    private const LINE_ITEM_TO_DEAL_ASSOCIATION_TYPE_ID = 20;

    public function __construct(
        private readonly HubSpotClient $hubSpotClient,
        private readonly ErpProductRepository $erpProductRepository,
        #[Autowire('%hubspot_order_form_pipeline_label%')]
        private readonly string $configuredPipelineLabel,
        #[Autowire('%hubspot_order_form_pipeline_value%')]
        private readonly string $configuredPipelineValue,
        #[Autowire('%hubspot_order_form_stage_label%')]
        private readonly string $configuredStageLabel,
        #[Autowire('%hubspot_order_form_tax_rate_id%')]
        private readonly string $configuredTaxRateId,
        #[Autowire('%hubspot_order_form_tax_rate_percentage%')]
        private readonly float $configuredTaxRatePercentage,
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
     *   errors: array<int, array<string, mixed>>
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
            ];
        }

        /** @var Commercial $commercial */
        $commercial = $validation['commercial'];
        $hubspotOwnerId = (string) ($validation['hubspotOwnerId'] ?? '');
        $companyName = (string) ($validation['companyName'] ?? '');
        /** @var array<string, array<string, mixed>> $productsByReference */
        $productsByReference = $validation['productsByReference'];
        $hubspotDealId = $validation['hubspotDealId'];
        if ($orderForm->getDealType() === OrderForm::DEAL_TYPE_NOUVEAU) {
            $hubspotDealId = $this->createNewDeal($orderForm, $commercial, $hubspotOwnerId, $companyName);
        }

        foreach ($lineItems as $lineItem) {
            $reference = (string) ($lineItem['articleRef'] ?? '');
            $product = $productsByReference[$reference] ?? null;

            if (!is_array($product)) {
                throw new \RuntimeException(sprintf('Produit %s introuvable dans le contexte de synchronisation.', $reference));
            }

            $taxRateResolution = $this->resolveTaxRateGroupIdForReference($reference);

            if (($taxRateResolution['success'] ?? false) !== true) {
                return [
                    'success' => false,
                    'hubspotDealId' => null,
                    'errors' => $taxRateResolution['errors'] ?? [],
                ];
            }

            $this->createLineItem(
                $hubspotDealId,
                $product,
                $lineItem,
                (string) ($taxRateResolution['taxRateGroupId'] ?? '')
            );
        }

        return [
            'success' => true,
            'hubspotDealId' => $hubspotDealId,
            'errors' => [],
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
                    $company = $this->hubSpotClient->getObject('companies', $enterpriseId, ['properties' => ['name']]);
                    $companyName = trim((string) (($company['properties']['name'] ?? null) ?: ''));
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
                    $response = $this->hubSpotClient->getObject('deals', $dealId, ['properties' => ['dealname']]);
                    $hubspotDealId = (string) ($response['id'] ?? $dealId);
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
            'hubspotDealId' => $hubspotDealId,
            'productsByReference' => $productsByReference,
        ];
    }

    private function createNewDeal(OrderForm $orderForm, Commercial $commercial, string $hubspotOwnerId, string $companyName): string
    {
        $enterpriseId = (string) $orderForm->getEnterpriseId();
        $pipelineStage = $this->resolveDealPipelineStage();
        $properties = [
            'dealname' => $this->buildDealName($orderForm, $companyName),
            'hubspot_owner_id' => $hubspotOwnerId,
            'pipeline' => $pipelineStage['pipelineId'],
            'dealstage' => $pipelineStage['stageId'],
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
     * @param array<string, mixed> $product
     * @param array<string, mixed> $lineItem
     */
    private function createLineItem(string $hubspotDealId, array $product, array $lineItem, string $taxRateGroupId): void
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
            'hs_tax_rate_group_id' => $taxRateGroupId,
        ];

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

        $this->hubSpotClient->createObject('line_items', $properties, [
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
     * @return array{success: bool, taxRateGroupId?: string, errors?: array<int, array<string, mixed>>}
     */
    private function resolveTaxRateGroupIdForReference(string $reference): array
    {
        $vatRate = $this->resolveVatRateFromLocalProduct($reference);

        if ($vatRate !== null) {
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

        return $this->resolveDefaultTaxRateGroupId();
    }

    /**
     * @return array{success: bool, taxRateGroupId?: string, errors?: array<int, array<string, mixed>>}
     */
    private function resolveDefaultTaxRateGroupId(): array
    {
        $configuredTaxRateId = trim($this->configuredTaxRateId);

        if ($this->looksLikeHubspotId($configuredTaxRateId)) {
            try {
                $taxRate = $this->hubSpotClient->get(sprintf('/tax-rates/v1/tax-rates/%s', $configuredTaxRateId));

                if (($taxRate['active'] ?? false) !== true) {
                    return [
                        'success' => false,
                        'errors' => [$this->error('taxRate', sprintf('Le tax rate HubSpot %s est inactif.', $configuredTaxRateId))],
                    ];
                }

                return [
                    'success' => true,
                    'taxRateGroupId' => $configuredTaxRateId,
                ];
            } catch (\Throwable $exception) {
                return [
                    'success' => false,
                    'errors' => [$this->error(
                        'taxRate',
                        sprintf('Le tax rate HubSpot %s est introuvable ou inaccessible.', $configuredTaxRateId),
                        ['details' => $exception->getMessage()]
                    )],
                ];
            }
        }

        try {
            $response = $this->hubSpotClient->get('/tax-rates/v1/tax-rates');
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'errors' => [$this->error(
                    'taxRate',
                    'La recuperation des tax rates HubSpot a echoue.',
                    ['details' => $exception->getMessage()]
                )],
            ];
        }

        $taxRates = $this->normalizeTaxRatesResponse($response);
        $matchingTaxRate = $this->findMatchingTaxRate($taxRates, $this->configuredTaxRatePercentage);

        if ($matchingTaxRate === null) {
            return [
                'success' => false,
                'errors' => [$this->error(
                    'taxRate',
                    sprintf('Aucun tax rate HubSpot actif ne correspond a %.2f%%.', $this->configuredTaxRatePercentage)
                )],
            ];
        }

        return [
            'success' => true,
            'taxRateGroupId' => (string) $matchingTaxRate['id'],
        ];
    }

    private function resolveVatRateFromLocalProduct(string $reference): ?string
    {
        $product = $this->erpProductRepository->findOneByReference($reference);

        if ($product === null) {
            return null;
        }

        $codeFiscal = trim((string) $product->getCodeFiscal());

        if ($codeFiscal === '') {
            return null;
        }

        if (preg_match('/(?:^|[^0-9])5[.,]5(?:[^0-9]|$)/', $codeFiscal) === 1) {
            return '5.5';
        }

        if (preg_match('/(?:^|[^0-9])20(?:[.,]0+)?(?:[^0-9]|$)/', $codeFiscal) === 1) {
            return '20';
        }

        return null;
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
            'properties' => ['name', 'hs_sku'],
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

    /**
     * @param mixed $response
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalizeTaxRatesResponse(mixed $response): array
    {
        if (!is_array($response)) {
            return [];
        }

        if (array_is_list($response)) {
            return array_values(array_filter($response, 'is_array'));
        }

        if (isset($response['results']) && is_array($response['results'])) {
            return array_values(array_filter($response['results'], 'is_array'));
        }

        return [];
    }

    /**
     * @param array<int, array<string, mixed>> $taxRates
     *
     * @return array<string, mixed>|null
     */
    private function findMatchingTaxRate(array $taxRates, float $percentage): ?array
    {
        foreach ($taxRates as $taxRate) {
            $rate = isset($taxRate['percentageRate']) ? (float) $taxRate['percentageRate'] : null;
            $isActive = ($taxRate['active'] ?? false) === true;
            $taxRateId = isset($taxRate['id']) ? trim((string) $taxRate['id']) : '';

            if ($rate === null || !$isActive || !$this->looksLikeHubspotId($taxRateId)) {
                continue;
            }

            if (abs($rate - $percentage) < 0.0001) {
                return $taxRate;
            }
        }

        return null;
    }
}
