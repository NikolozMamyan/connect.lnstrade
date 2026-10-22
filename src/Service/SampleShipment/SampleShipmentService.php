<?php

namespace App\Service\SampleShipment;

use App\Entity\ErpProduct;
use App\Entity\SampleShipment;
use App\Repository\ErpProductRepository;
use App\Repository\SampleShipmentRepository;
use App\Service\Erp\SageClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

final class SampleShipmentService
{
    private const CLIENT_ID = '9ECH';
    private const CLIENT_NAME = 'CLIENT ECHANTILLONS LNS TRADE';
    private const OWNER_FIRST_NAME = 'Corentin';
    private const OWNER_NAME = 'BURY';
    private const DELIVERY_INSTRUCTION_MAX_LENGTH = 69;

    public function __construct(
        private readonly ErpProductRepository $productRepository,
        private readonly SampleShipmentRepository $shipmentRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly SageClient $sageClient,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{success: true, idempotent: bool, reference: string, sageDocument: ?string}
     */
    public function submit(array $input): array
    {
        $requestToken = trim((string) ($input['requestToken'] ?? ''));

        if (!preg_match('/^[A-Za-z0-9-]{16,64}$/', $requestToken)) {
            throw new SampleShipmentValidationException(['requestToken' => 'La référence de la demande est invalide.']);
        }

        $requestLock = $this->lockFactory->createLock('sample-shipment-'.hash('sha256', $requestToken), 180);

        if (!$requestLock->acquire()) {
            throw new SampleShipmentBusyException('Cet envoi est déjà en cours de traitement.');
        }

        try {
            $shipment = $this->shipmentRepository->findOneByRequestToken($requestToken);

            if ($shipment instanceof SampleShipment && $shipment->isSent()) {
                return $this->successResult($shipment, true);
            }

            $normalized = $this->normalizeInput($input);

            if (!$shipment instanceof SampleShipment) {
                $shipment = new SampleShipment($requestToken);
                $this->entityManager->persist($shipment);
            }

            $shipment
                ->setRecipient($normalized['recipient'])
                ->setLines($normalized['lines'])
                ->markProcessing();
            $this->entityManager->flush();

            $orderReference = $shipment->getOrderReference()
                ?? sprintf('ECH-%s-%06d', (new \DateTimeImmutable())->format('Ymd'), $shipment->getId());
            $deliveryLabel = $shipment->getDeliveryLabel()
                ?? $this->buildDeliveryLabel($normalized['recipient']['company'], (int) $shipment->getId());
            $shipment->prepareExport($orderReference, $deliveryLabel);
            $this->entityManager->flush();

            $exportLock = $this->lockFactory->createLock('sample-shipment-sage-export', 180);

            if (!$exportLock->acquire()) {
                throw new SampleShipmentBusyException('Un autre envoi d’échantillons est en cours. Réessayez dans quelques instants.');
            }

            try {
                $this->ensureSampleClientExists();
                $this->upsertDeliveryAddress($deliveryLabel, $normalized['recipient']);
                $orderPayload = $this->buildOrderPayload($orderReference, $normalized);
                $sageResponse = $this->sageClient->post('/order', $orderPayload);
            } finally {
                $exportLock->release();
            }

            $shipment->markSent($orderReference, $deliveryLabel, $orderPayload, $sageResponse);
            $this->entityManager->flush();

            return $this->successResult($shipment, false);
        } catch (SampleShipmentValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            if (isset($shipment) && $shipment instanceof SampleShipment && !$shipment->isSent()) {
                $shipment->markFailed($exception->getMessage());
                $this->entityManager->flush();
            }

            $this->logger->error('Sample shipment export failed', [
                'requestToken' => $requestToken,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        } finally {
            $requestLock->release();
        }
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{recipient: array<string, ?string>, lines: list<array{reference: string, designation: string, quantity: int}>}
     */
    private function normalizeInput(array $input): array
    {
        $errors = [];
        $recipient = [
            'company' => $this->requiredString($input, 'company', 150, 'Entreprise', $errors),
            'contactName' => $this->optionalString($input, 'contactName', 150, 'Contact', $errors),
            'address' => $this->requiredString($input, 'address', 150, 'Adresse', $errors),
            'addressComplement' => $this->optionalString($input, 'addressComplement', 150, 'Complément d’adresse', $errors),
            'postalCode' => $this->requiredString($input, 'postalCode', 32, 'Code postal', $errors),
            'city' => $this->requiredString($input, 'city', 100, 'Ville', $errors),
            'country' => $this->requiredString($input, 'country', 100, 'Pays', $errors),
            'phone' => $this->optionalString($input, 'phone', 50, 'Téléphone', $errors),
            'email' => $this->optionalString($input, 'email', 180, 'E-mail', $errors),
            'message' => $this->optionalString($input, 'message', 1000, 'Message', $errors),
        ];

        if ($recipient['email'] !== null && filter_var($recipient['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'L’adresse e-mail est invalide.';
        }

        $rawLines = $input['lines'] ?? null;

        if (!is_array($rawLines) || $rawLines === []) {
            $errors['lines'] = 'Scannez au moins un produit.';
            $rawLines = [];
        } elseif (count($rawLines) > 100) {
            $errors['lines'] = 'Un envoi ne peut pas contenir plus de 100 produits différents.';
        }

        $quantities = [];

        foreach ($rawLines as $rawLine) {
            if (!is_array($rawLine)) {
                $errors['lines'] = 'Une ligne produit est invalide.';
                continue;
            }

            $reference = trim((string) ($rawLine['reference'] ?? ''));
            $quantity = filter_var($rawLine['quantity'] ?? null, FILTER_VALIDATE_INT);

            if ($reference === '' || mb_strlen($reference) > 64 || $quantity === false || $quantity < 1 || $quantity > 999) {
                $errors['lines'] = 'Une référence ou une quantité produit est invalide.';
                continue;
            }

            $quantities[$reference] = min(999, ($quantities[$reference] ?? 0) + $quantity);
        }

        $products = $this->productRepository->findIndexedByReferences(array_keys($quantities));
        $missingReferences = array_diff(array_keys($quantities), array_keys($products));

        if ($missingReferences !== []) {
            $errors['lines'] = sprintf('Produit introuvable : %s.', implode(', ', $missingReferences));
        }

        if ($errors !== []) {
            throw new SampleShipmentValidationException($errors);
        }

        $lines = [];

        foreach ($quantities as $reference => $quantity) {
            /** @var ErpProduct $product */
            $product = $products[$reference];
            $lines[] = [
                'reference' => $reference,
                'designation' => trim((string) $product->getDesignation()),
                'quantity' => $quantity,
            ];
        }

        return ['recipient' => $recipient, 'lines' => $lines];
    }

    private function ensureSampleClientExists(): void
    {
        $clients = $this->extractList($this->sageClient->get('/Clients', ['reference' => self::CLIENT_ID]));

        foreach ($clients as $client) {
            if (strcasecmp(trim((string) ($client['reference'] ?? '')), self::CLIENT_ID) === 0) {
                return;
            }
        }

        $this->sageClient->post('/Clients', [
            'reference' => self::CLIENT_ID,
            'intitule' => self::CLIENT_NAME,
            'abrege' => 'ECHANTILLONS',
            'tauxRemise' => 0,
            'tauxEscompte' => 0,
            'tauxReleve' => 0,
            'tauxRfa' => 0,
            'estEnSommeil' => false,
        ]);
    }

    /**
     * @param array<string, ?string> $recipient
     */
    private function upsertDeliveryAddress(string $deliveryLabel, array $recipient): void
    {
        $delivery = [
            'client' => self::CLIENT_ID,
            'intitule' => $deliveryLabel,
            'adresse' => $recipient['address'],
            'complement' => $recipient['addressComplement'],
            'codePostal' => $recipient['postalCode'],
            'ville' => $recipient['city'],
            'pays' => $recipient['country'],
            'contact' => $recipient['contactName'],
            'telephone' => $recipient['phone'],
            'email' => $recipient['email'],
            'commentaire' => $recipient['message'],
            'livraisonPrincipal' => true,
        ];
        $existingDeliveries = $this->findExistingDeliveries();
        $targetExists = false;

        foreach ($existingDeliveries as $existingDelivery) {
            $existingLabel = trim((string) ($existingDelivery['intitule'] ?? ''));

            if (strcasecmp($existingLabel, $deliveryLabel) === 0) {
                $targetExists = true;
                continue;
            }

            if ($this->isMainDelivery($existingDelivery) && $existingLabel !== '') {
                $this->sageClient->patch('/Livraisons', [
                    'client' => self::CLIENT_ID,
                    'intitule' => $existingLabel,
                    'nouveauIntitule' => $existingLabel,
                    'livraisonPrincipal' => false,
                ]);
            }
        }

        if ($targetExists) {
            $this->sageClient->patch('/Livraisons', $delivery + ['nouveauIntitule' => $deliveryLabel]);
        } else {
            $this->sageClient->post('/Livraisons', $delivery);
        }
    }

    /**
     * Sage returns 404 when a client has no delivery address yet.
     *
     * @return list<array<string, mixed>>
     */
    private function findExistingDeliveries(): array
    {
        try {
            return $this->extractList($this->sageClient->get('/Livraisons', ['client' => self::CLIENT_ID]));
        } catch (\RuntimeException $exception) {
            if (str_contains($exception->getMessage(), 'Erreur API Sage [404]')) {
                return [];
            }

            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $delivery
     */
    private function isMainDelivery(array $delivery): bool
    {
        return filter_var($delivery['livraisonPrincipal'] ?? false, FILTER_VALIDATE_BOOL)
            || (int) ($delivery['livraisonPrincipal'] ?? 0) === 1;
    }

    /**
     * @param array{recipient: array<string, ?string>, lines: list<array{reference: string, designation: string, quantity: int}>} $normalized
     *
     * @return array<string, mixed>
     */
    private function buildOrderPayload(string $orderReference, array $normalized): array
    {
        $date = (new \DateTimeImmutable())->format('Y-m-d');
        $company = trim((string) $normalized['recipient']['company']);
        $message = trim((string) $normalized['recipient']['message']);
        $instruction = $company.($message !== '' ? ' - '.$message : ' - Echantillons');

        return [
            'numClient' => self::CLIENT_ID,
            'dateCommande' => $date,
            'dateLivraison' => $date,
            'referenceCommande' => $orderReference,
            'statut' => 'Saisi',
            'modeExpedition' => 'Standard',
            'ownerFirstName' => self::OWNER_FIRST_NAME,
            'ownerName' => self::OWNER_NAME,
            'instructionDeLivraison' => mb_substr($instruction, 0, self::DELIVERY_INSTRUCTION_MAX_LENGTH),
            'orderLines' => array_map(static fn (array $line): array => [
                'reference' => $line['reference'],
                'designation' => $line['designation'],
                'prixHT' => 0,
                'quantite' => $line['quantity'],
                'quantitePreparee' => 0,
            ], $normalized['lines']),
        ];
    }

    private function buildDeliveryLabel(string $company, int $shipmentId): string
    {
        $suffix = sprintf(' #%06d', $shipmentId);

        return mb_substr(trim($company), 0, 35 - mb_strlen($suffix)).$suffix;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractList(array $response): array
    {
        $items = isset($response['results']) && is_array($response['results']) ? $response['results'] : $response;

        if (!array_is_list($items) && isset($items['reference'])) {
            $items = [$items];
        }

        return array_values(array_filter($items, 'is_array'));
    }

    /**
     * @param array<string, mixed>  $input
     * @param array<string, string> $errors
     */
    private function requiredString(array $input, string $field, int $maxLength, string $label, array &$errors): string
    {
        $value = trim((string) ($input[$field] ?? ''));

        if ($value === '') {
            $errors[$field] = sprintf('%s est obligatoire.', $label);
        } elseif (mb_strlen($value) > $maxLength) {
            $errors[$field] = sprintf('%s ne peut pas dépasser %d caractères.', $label, $maxLength);
        }

        return $value;
    }

    /**
     * @param array<string, mixed>  $input
     * @param array<string, string> $errors
     */
    private function optionalString(array $input, string $field, int $maxLength, string $label, array &$errors): ?string
    {
        $value = trim((string) ($input[$field] ?? ''));

        if (mb_strlen($value) > $maxLength) {
            $errors[$field] = sprintf('%s ne peut pas dépasser %d caractères.', $label, $maxLength);
        }

        return $value !== '' ? $value : null;
    }

    /**
     * @return array{success: true, idempotent: bool, reference: string, sageDocument: ?string}
     */
    private function successResult(SampleShipment $shipment, bool $idempotent): array
    {
        $response = $shipment->getSageResponse() ?? [];
        $sageDocument = trim((string) ($response['piece'] ?? $response['numeroPiece'] ?? $response['doPiece'] ?? ''));

        return [
            'success' => true,
            'idempotent' => $idempotent,
            'reference' => (string) $shipment->getOrderReference(),
            'sageDocument' => $sageDocument !== '' ? $sageDocument : null,
        ];
    }
}
