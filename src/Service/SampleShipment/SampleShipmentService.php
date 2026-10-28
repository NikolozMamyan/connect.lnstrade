<?php

namespace App\Service\SampleShipment;

use App\Entity\ErpProduct;
use App\Entity\Commercial;
use App\Entity\SampleShipment;
use App\Message\SampleShipmentNotificationMessage;
use App\Repository\CommercialRepository;
use App\Repository\ErpProductRepository;
use App\Repository\SampleShipmentRepository;
use App\Service\Erp\SageClient;
use App\Service\Mailer\SampleShipmentMailer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\MessageBusInterface;

final class SampleShipmentService
{
    private const CLIENT_ID = '9ECH';
    private const CLIENT_NAME = 'CLIENT ECHANTILLONS LNS TRADE';
    private const CONSUMPTION_CLIENT_ID = 'CONSOLNS';
    private const CONSUMPTION_CLIENT_NAME = 'CONSOMMATION INTERNE LNS TRADE';
    private const CONSUMPTION_DELIVERY_LABEL = 'LNS CONSO';
    private const DELIVERY_INSTRUCTION_MAX_LENGTH = 69;

    public function __construct(
        private readonly ErpProductRepository $productRepository,
        private readonly SampleShipmentRepository $shipmentRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly SageClient $sageClient,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
        private readonly CommercialRepository $commercialRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly SampleShipmentMailer $mailer,
        #[Autowire('%internal_consumption_owner_first_name%')]
        private readonly string $consumptionOwnerFirstName = 'Corentin',
        #[Autowire('%internal_consumption_owner_last_name%')]
        private readonly string $consumptionOwnerLastName = 'BURY',
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{success: true, idempotent: bool, reference: string, sageDocument: ?string}
     */
    public function submit(array $input): array
    {
        return $this->submitForPurpose($input, SampleShipment::PURPOSE_SAMPLES);
    }

    public function submitConsumption(array $input): array
    {
        return $this->submitForPurpose($input, SampleShipment::PURPOSE_CONSUMPTION);
    }

    private function submitForPurpose(array $input, string $purpose): array
    {
        $consumption = $purpose === SampleShipment::PURPOSE_CONSUMPTION;
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

            if ($shipment instanceof SampleShipment && $shipment->getPurpose() !== $purpose) {
                throw new SampleShipmentValidationException(['requestToken' => 'Cette demande appartient à un autre parcours. Rechargez la page.']);
            }

            if ($shipment instanceof SampleShipment && $shipment->isSent()) {
                $this->queueNotification($shipment);

                return $this->successResult($shipment, true);
            }

            if ($shipment instanceof SampleShipment && $shipment->getSagePayload() !== null) {
                $existingOrder = $this->findExistingOrder($shipment);

                if ($existingOrder !== null) {
                    $shipment->markSent((string) $shipment->getOrderReference(), (string) $shipment->getDeliveryLabel(), $shipment->getSagePayload(), $existingOrder);
                    $this->entityManager->flush();
                    $this->queueNotification($shipment);

                    return $this->successResult($shipment, true);
                }
            }

            $normalized = $this->normalizeInput($input, $consumption);

            if (!$shipment instanceof SampleShipment) {
                $shipment = new SampleShipment($requestToken, $purpose);
                $this->entityManager->persist($shipment);
            }

            $shipment
                ->setRecipient($normalized['recipient'])
                ->setLines($normalized['lines'])
                ->setRepresentative($normalized['representative'])
                ->markProcessing();
            $this->entityManager->flush();

            $orderReference = $shipment->getOrderReference()
                ?? sprintf('%s-%s-%06d', $consumption ? 'CNS' : 'ECH', (new \DateTimeImmutable())->format('ymd'), $shipment->getId());
            $deliveryLabel = $consumption ? self::CONSUMPTION_DELIVERY_LABEL : $this->buildDeliveryLabel($normalized['recipient']['company'], (int) $shipment->getId());
            $orderPayload = $this->buildOrderPayload($orderReference, $normalized, $consumption);
            $shipment->prepareExport($orderReference, $deliveryLabel, $orderPayload);
            $this->entityManager->flush();

            $exportLock = $this->lockFactory->createLock($consumption ? 'internal-consumption-sage-export' : 'sample-shipment-sage-export', 180);

            if (!$exportLock->acquire()) {
                throw new SampleShipmentBusyException('Un autre envoi est en cours. Réessayez dans quelques instants.');
            }

            try {
                $clientId = $consumption ? self::CONSUMPTION_CLIENT_ID : self::CLIENT_ID;
                $this->ensureClientExists($consumption);
                $this->upsertDeliveryAddress($deliveryLabel, $normalized['recipient'], $clientId);
                $sageResponse = $this->sageClient->post('/Order', $orderPayload);
            } finally {
                $exportLock->release();
            }

            $shipment->markSent($orderReference, $deliveryLabel, $orderPayload, $sageResponse);
            $this->entityManager->flush();
            $this->queueNotification($shipment);

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

    public function sendNotification(int $shipmentId): void
    {
        $lock = $this->lockFactory->createLock('sample-shipment-notification-'.$shipmentId, 180);

        if (!$lock->acquire()) {
            throw new SampleShipmentBusyException('L’e-mail de cet envoi est déjà en cours de traitement.');
        }

        try {
            $shipment = $this->shipmentRepository->find($shipmentId);

            if (!$shipment instanceof SampleShipment || !$shipment->isSent() || $shipment->isNotified()) {
                return;
            }

            $this->mailer->send($shipment);
            $shipment->markNotified();
            $this->entityManager->flush();
        } finally {
            $lock->release();
        }
    }

    private function queueNotification(SampleShipment $shipment): void
    {
        if ($shipment->isNotified()) {
            return;
        }

        try {
            $this->messageBus->dispatch(new SampleShipmentNotificationMessage((int) $shipment->getId()));
        } catch (\Throwable $exception) {
            throw new SampleShipmentNotificationException('Le BC est créé dans Sage, mais l’e-mail ADV n’a pas pu être programmé. Réessayez : seul l’e-mail sera repris.', 0, $exception);
        }
    }

    private function findExistingOrder(SampleShipment $shipment): ?array
    {
        $reference = (string) $shipment->getOrderReference();

        if ($reference === '' || strlen($reference) > 17) {
            return null;
        }

        try {
            $orders = $this->extractList($this->sageClient->get('/Document/header', [
                'domaine' => 0,
                'type' => 1,
                'tiers' => $shipment->isConsumption() ? self::CONSUMPTION_CLIENT_ID : self::CLIENT_ID,
                'dateDebut' => $shipment->getCreatedAt()->format('Y-m-d'),
                'dateFin' => (new \DateTimeImmutable('tomorrow'))->format('Y-m-d'),
            ]));
        } catch (\RuntimeException $exception) {
            if (str_contains($exception->getMessage(), 'Erreur API Sage [404]')) {
                return null;
            }

            throw $exception;
        }

        foreach ($orders as $order) {
            if (trim((string) ($order['reference'] ?? '')) === $reference && trim((string) ($order['piece'] ?? '')) !== '') {
                return $order;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{recipient: array<string, ?string>, representative: array{id?: int, firstName: string, lastName: string}, lines: list<array{reference: string, designation: string, quantity: int, expiryDate: string}>}
     */
    private function normalizeInput(array $input, bool $consumption): array
    {
        $errors = [];
        $representativeId = filter_var($input['representativeId'] ?? null, FILTER_VALIDATE_INT);
        $commercial = !$consumption && $representativeId !== false && $representativeId > 0
            ? $this->commercialRepository->find($representativeId)
            : null;

        if (!$consumption && (!$commercial instanceof Commercial || !$commercial->isActive() || !$commercial->getSageFirstName() || !$commercial->getSageLastName())) {
            $errors['representativeId'] = 'Sélectionnez un représentant Sage valide.';
        }
        $recipient = $consumption ? [
            'company' => self::CONSUMPTION_CLIENT_NAME,
            'contactName' => null,
            'address' => '13 quai Kleber',
            'addressComplement' => null,
            'postalCode' => '67000',
            'city' => 'Strasbourg',
            'country' => 'France',
            'phone' => null,
            'email' => null,
            'message' => 'Consommation interne LNS Trade',
        ] : [
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
        $expiryDates = [];

        foreach ($rawLines as $index => $rawLine) {
            if (!is_array($rawLine)) {
                $errors['lines'] = 'Une ligne produit est invalide.';
                continue;
            }

            $reference = trim((string) ($rawLine['reference'] ?? ''));
            $quantity = filter_var($rawLine['quantity'] ?? null, FILTER_VALIDATE_INT);
            $expiryDate = $rawLine['expiryDate'] ?? null;
            $parsedDate = is_string($expiryDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $expiryDate)
                ? \DateTimeImmutable::createFromFormat('!Y-m-d', $expiryDate)
                : false;

            if (!$parsedDate || $parsedDate->format('Y-m-d') !== $expiryDate) {
                $errors['lines.'.$index.'.expiryDate'] = 'Renseignez une date de péremption valide pour ce produit.';
            } elseif (isset($expiryDates[$reference]) && $expiryDates[$reference] !== $expiryDate) {
                $errors['lines.'.$index.'.expiryDate'] = 'Une même référence doit avoir une seule date de péremption.';
            } else {
                $expiryDates[$reference] = $expiryDate;
            }

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
                'reference' => (string) $reference,
                'designation' => trim((string) $product->getDesignation()),
                'quantity' => $quantity,
                'expiryDate' => $expiryDates[$reference],
            ];
        }

        return [
            'recipient' => $recipient,
            'representative' => $consumption ? [
                'firstName' => $this->consumptionOwnerFirstName,
                'lastName' => $this->consumptionOwnerLastName,
            ] : [
                'id' => (int) $commercial->getId(),
                'firstName' => $commercial->getSageFirstName(),
                'lastName' => $commercial->getSageLastName(),
            ],
            'lines' => $lines,
        ];
    }

    private function ensureClientExists(bool $consumption): void
    {
        $clientId = $consumption ? self::CONSUMPTION_CLIENT_ID : self::CLIENT_ID;

        try {
            $clients = $this->extractList($this->sageClient->get('/Clients', ['reference' => $clientId]));
        } catch (\RuntimeException $exception) {
            if (!str_contains($exception->getMessage(), 'Erreur API Sage [404]')) {
                throw $exception;
            }

            $clients = [];
        }

        foreach ($clients as $client) {
            if (strcasecmp(trim((string) ($client['reference'] ?? '')), $clientId) === 0) {
                return;
            }
        }

        $this->sageClient->post('/Clients', [
            'reference' => $clientId,
            'intitule' => $consumption ? self::CONSUMPTION_CLIENT_NAME : self::CLIENT_NAME,
            'abrege' => $consumption ? 'CONSO LNS' : 'ECHANTILLONS',
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
    private function upsertDeliveryAddress(string $deliveryLabel, array $recipient, string $clientId): void
    {
        $delivery = [
            'client' => $clientId,
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
        $existingDeliveries = $this->findExistingDeliveries($clientId);
        $targetExists = false;

        foreach ($existingDeliveries as $existingDelivery) {
            $existingLabel = trim((string) ($existingDelivery['intitule'] ?? ''));

            if (strcasecmp($existingLabel, $deliveryLabel) === 0) {
                $targetExists = true;
                continue;
            }

            if ($this->isMainDelivery($existingDelivery) && $existingLabel !== '') {
                $this->sageClient->patch('/Livraisons', [
                    'client' => $clientId,
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
    private function findExistingDeliveries(string $clientId): array
    {
        try {
            return $this->extractList($this->sageClient->get('/Livraisons', ['client' => $clientId]));
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
     * @param array{recipient: array<string, ?string>, representative: array{id?: int, firstName: string, lastName: string}, lines: list<array{reference: string, designation: string, quantity: int, expiryDate: string}>} $normalized
     *
     * @return array<string, mixed>
     */
    private function buildOrderPayload(string $orderReference, array $normalized, bool $consumption): array
    {
        $date = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $company = trim((string) $normalized['recipient']['company']);
        $message = trim((string) $normalized['recipient']['message']);
        $instruction = $consumption ? 'Consommation interne LNS Trade' : $company.($message !== '' ? ' - '.$message : ' - Echantillons');

        return [
            'numClient' => $consumption ? self::CONSUMPTION_CLIENT_ID : self::CLIENT_ID,
            'dateCommande' => $date,
            'dateLivraison' => $date,
            'referenceCommande' => $orderReference,
            'statut' => 'Saisi',
            'modeExpedition' => 'Standard',
            'ownerFirstName' => $normalized['representative']['firstName'],
            'ownerName' => $normalized['representative']['lastName'],
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
        return [
            'success' => true,
            'idempotent' => $idempotent,
            'reference' => (string) $shipment->getOrderReference(),
            'sageDocument' => $shipment->getSageDocument(),
        ];
    }
}
