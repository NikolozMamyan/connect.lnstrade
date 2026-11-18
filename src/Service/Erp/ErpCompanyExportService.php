<?php

namespace App\Service\Erp;

use App\Entity\HubspotCompany;
use App\Entity\HubspotCompanyContact;
use App\Entity\HubspotContact;
use App\Repository\CommercialRepository;
use App\Repository\HubspotCompanyRepository;
use App\Service\HubSpot\HubSpotClient;
use Psr\Log\LoggerInterface;

class ErpCompanyExportService
{
    private const EU_COUNTRIES = [
        'AT' => 'Austria', 'BE' => 'Belgium', 'BG' => 'Bulgaria',
        'HR' => 'Croatia', 'CY' => 'Cyprus', 'CZ' => 'Czech Republic',
        'DK' => 'Denmark', 'EE' => 'Estonia', 'FI' => 'Finland',
        'FR' => 'France', 'DE' => 'Germany', 'GR' => 'Greece',
        'HU' => 'Hungary', 'IE' => 'Ireland', 'IT' => 'Italy',
        'LV' => 'Latvia', 'LT' => 'Lithuania', 'LU' => 'Luxembourg',
        'MT' => 'Malta', 'NL' => 'Netherlands', 'PL' => 'Poland',
        'PT' => 'Portugal', 'RO' => 'Romania', 'SK' => 'Slovakia',
        'SI' => 'Slovenia', 'ES' => 'Spain', 'SE' => 'Sweden',
    ];

    private array $representatives = [];

    public function __construct(
        private readonly HubspotCompanyRepository $hubspotCompanyRepository,
        private readonly SageClient $sageClient,
        private readonly HubSpotClient $hubSpotClient,
        private readonly CommercialRepository $commercialRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function sendCompaniesToErp(): array
    {
        $companies = $this->hubspotCompanyRepository->findExportableWithContacts();

        $sent = 0;
        $skipped = 0;
        $updated = 0;
        $created = 0;
        $hubspotUpdated = 0;
        $errors = [];
        $payloads = [];

        foreach ($companies as $company) {
            if (!$company instanceof HubspotCompany) {
                continue;
            }

            try {
                $result = $this->exportCompany($company);

                if (($result['skipped'] ?? false) === true) {
                    ++$skipped;
                    continue;
                }

                if (count($payloads) < 20) {
                    $payloads[] = $result['payload'];
                }
                ++$sent;
                $hubspotUpdated += ($result['hubspotUpdated'] ?? false) ? 1 : 0;

                if (($result['action'] ?? null) === 'create') {
                    ++$created;
                } elseif (($result['action'] ?? null) === 'update') {
                    ++$updated;
                }
            } catch (\Throwable $e) {
                $error = [
                    'companyHubspotId' => $company->getHubspotId(),
                    'companyName' => $company->getName(),
                    'message' => $e->getMessage(),
                ];

                $errors[] = $error;

                $this->logger->error('ERP company export error', $error);
            }
        }

        return [
            'sent' => $sent,
            'created' => $created,
            'updated' => $updated,
            'hubspotUpdated' => $hubspotUpdated,
            'skipped' => $skipped,
            'errors' => $errors,
            'payloads' => $payloads,
        ];
    }

    public function sendCompanyToErp(HubspotCompany $company): array
    {
        return $this->exportCompany($company);
    }

    private function buildErpPayload(HubspotCompany $company): ?array
    {
        $reference = $this->resolveReference($company);

        if ($reference === null || $reference === '') {
            return null;
        }

        $mainContact = $this->resolveMainContact($company);
        $properties = $company->getRawProperties() ?? [];
        $country = $this->truncate($properties['company_country_en'] ?? $company->getCountry(), 150);
        $isFrance = in_array(mb_strtolower((string) $country), ['france', 'fr'], true);
        $isEu = array_key_exists(strtoupper((string) $country), self::EU_COUNTRIES)
            || in_array(mb_strtolower((string) $country), array_map('mb_strtolower', self::EU_COUNTRIES), true)
            || mb_strtolower((string) $country) === 'czechia';
        $creditLimit = $this->normalizeCreditLimit($properties['insured_credit_limit'] ?? null);

        $payload = [
            'reference' => $reference,
            'intitule' => $this->truncate($company->getName(), 255),
            'abrege' => $this->truncate($company->getName(), 17),
            'interlocuteur' => $mainContact ? $this->buildContactFullName($mainContact) : null,
            'adresse' => $this->truncate($company->getAddress(), 255),
            'complementAdresse' => $this->truncate($company->getAddress2(), 255),
            'codePostal' => $this->truncate($company->getZip(), 50),
            'ville' => $this->truncate($company->getCity(), 150),
            'pays' => $country,
            'telephone' => $this->truncate($company->getPhone(), 255),
            'email' => $this->truncate($company->getEmail(), 255),
            'siteInternet' => $this->truncate($company->getWebsite(), 255),
            'linkedin' => $this->truncate($properties['linkedin_company_page'] ?? null, 255),
            'facebook' => $this->truncate($properties['facebook_company_page'] ?? null, 255),
            'siret' => $this->truncate($properties['siret'] ?? null, 255),
            'codeNaf' => $this->truncate($properties['naf_code'] ?? null, 255),
            'identifiantTva' => $this->truncate($properties['tva_intracommunautaire'] ?? null, 255),
            'categorieTarifaire' => $country === null ? null : ($isFrance ? 'Tarif public France' : 'Tarif public Hors France'),
            'representant' => $this->resolveRepresentative((string) ($properties['hubspot_owner_id'] ?? '')),
            'encoursAutorise' => $creditLimit,
            'assuranceCredit' => $creditLimit,
            'categorieComptable' => $country === null ? null : ($isFrance ? 'Vente France' : ($isEu ? 'Vente UE' : 'Vente hors UE')),
            'estEnSommeil' => false,
            'assujettiALaTVA' => $country === null ? null : $isFrance,
            'champsLibres' => [
                'HUBSPOT_ID' => $company->getHubspotObjectId() ?? $company->getHubspotId(),
                'Facturation palette' => 'OUI',
            ],
        ];

        return $this->removeNulls($payload);
    }

    private function exportCompany(HubspotCompany $company): array
    {
        if (!$this->isEligibleForExport($company)) {
            return [
                'skipped' => true,
                'reason' => 'company_not_eligible',
                'companyHubspotId' => $company->getHubspotId(),
                'companyName' => $company->getName(),
            ];
        }

        $payload = $this->buildErpPayload($company);

        if ($payload === null) {
            return [
                'skipped' => true,
                'companyHubspotId' => $company->getHubspotId(),
                'companyName' => $company->getName(),
            ];
        }

        $result = $this->sendToErpApi($payload, $company);
        $payload = $result['payload'];
        $reference = (string) ($payload['reference'] ?? '');

        $this->upsertMainContact($company, $reference);
        $this->upsertDeliveryAddress($company, $reference);
        $this->syncErpReferenceToHubSpot($company, $reference);
        $this->saveErpReference($company, $reference);

        $this->logger->info('ERP company sent', [
            'companyHubspotId' => $company->getHubspotId(),
            'companyName' => $company->getName(),
            'action' => $result['action'] ?? null,
            'reference' => $reference,
            'hubspotUpdated' => true,
        ]);

        return [
            'skipped' => false,
            'action' => $result['action'] ?? null,
            'payload' => $payload,
            'response' => $result['response'] ?? null,
            'reference' => $reference,
            'hubspotUpdated' => true,
            'companyHubspotId' => $company->getHubspotId(),
            'companyName' => $company->getName(),
        ];
    }

    private function saveErpReference(HubspotCompany $company, string $reference): void
    {
        if ($reference === '' || $company->getIdErp() === $reference) {
            return;
        }

        $company
            ->setIdErp($reference)
            ->setUpdatedAt(new \DateTimeImmutable());

        $this->hubspotCompanyRepository->save($company, true);
    }

    private function syncErpReferenceToHubSpot(HubspotCompany $company, string $reference): void
    {
        $companyHubspotId = trim((string) $company->getHubspotId());

        if ($companyHubspotId === '' || $reference === '') {
            throw new \RuntimeException('Impossible de synchroniser id_erp vers HubSpot : identifiant manquant.');
        }

        $this->hubSpotClient->updateObject('companies', $companyHubspotId, [
            'id_erp' => $reference,
        ]);
    }

    private function isEligibleForExport(HubspotCompany $company): bool
    {
        return $company->isArchived() !== true
            && mb_strtolower(trim((string) $company->getSageIntegration())) === 'yes';
    }

    private function sendToErpApi(array $payload, HubspotCompany $company): array
    {
        $reference = (string) ($payload['reference'] ?? '');

        if ($reference === '') {
            throw new \RuntimeException('Payload ERP invalide : reference manquante.');
        }

        $existing = $this->findClientByReference($reference);

        if (trim((string) $company->getIdErp()) === '') {
            $baseReference = $reference;
            $suffix = 2;

            while ($existing !== null && (string) ($existing['champsLibres']['HUBSPOT_ID'] ?? $existing['champsLibres']['hubspot_id'] ?? '') !== (string) $company->getHubspotId()) {
                if ($suffix > 99) {
                    throw new \RuntimeException(sprintf('Aucun compte tiers disponible pour le prefixe %s.', $baseReference));
                }

                $reference = $baseReference . $suffix++;
                $existing = $this->findClientByReference($reference);
            }

            $payload['reference'] = $reference;
        }

        if ($existing !== null) {
            $response = $this->sageClient->patch('/Clients', $payload);

            return [
                'action' => 'update',
                'reference' => $reference,
                'payload' => $payload,
                'response' => $response,
            ];
        }

        $response = $this->sageClient->post('/Clients', $payload);

        return [
            'action' => 'create',
            'reference' => $reference,
            'payload' => $payload,
            'response' => $response,
        ];
    }

    private function findClientByReference(string $reference): ?array
    {
        try {
            $response = $this->sageClient->get('/Clients', [
                'limit' => 1,
                'offset' => 0,
                'reference' => $reference,
            ]);
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();

            if (str_contains($message, 'Erreur API Sage [404]')) {
                return null;
            }

            throw $e;
        }

        $clients = $response['results'] ?? $response;

        if (isset($clients['reference'])) {
            $clients = [$clients];
        }

        foreach ($clients as $client) {
            if (\is_array($client) && strcasecmp(trim((string) ($client['reference'] ?? '')), $reference) === 0) {
                return $client;
            }
        }

        return null;
    }

    private function resolveMainContact(HubspotCompany $company): ?HubspotContact
    {
        $fallback = null;
        $currentAssociations = $company->getRawPayload()['associations']['contacts']['results'] ?? null;
        $currentRelations = [];

        foreach ($currentAssociations ?? [] as $association) {
            $currentRelations[(string) ($association['id'] ?? '') . '|' . (string) ($association['type'] ?? '')] = true;
        }

        foreach ($company->getCompanyContacts() as $relation) {
            if (!$relation instanceof HubspotCompanyContact) {
                continue;
            }

            $contact = $relation->getContact();

            if (!$contact instanceof HubspotContact || $contact->isArchived() === true) {
                continue;
            }

            if ($currentAssociations !== null && !isset($currentRelations[$contact->getHubspotId() . '|' . $relation->getAssociationType()])) {
                continue;
            }

            if ($relation->isPrimary() === true) {
                return $contact;
            }

            if ($fallback === null) {
                $fallback = $contact;
            }
        }

        return $fallback;
    }

    private function resolveRepresentative(string $ownerId): ?string
    {
        $ownerId = trim($ownerId);

        if ($ownerId === '') {
            return null;
        }

        if (isset($this->representatives[$ownerId])) {
            return $this->representatives[$ownerId];
        }

        $commercial = $this->commercialRepository->findOneBy(['hubspotId' => $ownerId]);
        $firstName = $this->truncate($commercial?->getSageFirstName(), 100);
        $lastName = $this->truncate($commercial?->getSageLastName(), 100);

        if ($firstName === null || $lastName === null) {
            $owner = $this->hubSpotClient->get(sprintf('/crm/v3/owners/%s', rawurlencode($ownerId)));
            $firstName = $this->truncate($owner['firstName'] ?? null, 100);
            $lastName = $this->truncate($owner['lastName'] ?? null, 100);
        }

        if ($firstName === null || $lastName === null) {
            throw new \RuntimeException(sprintf('Identite Sage du representant HubSpot %s incomplete.', $ownerId));
        }

        return $this->representatives[$ownerId] = $lastName . ', ' . $firstName;
    }

    private function normalizeCreditLimit(mixed $value): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0) {
            throw new \InvalidArgumentException('La limite de credit assuree doit etre un montant positif ou nul.');
        }

        return (float) $value;
    }

    private function upsertMainContact(HubspotCompany $company, string $reference): void
    {
        $contact = $this->resolveMainContact($company);

        if ($contact === null) {
            return;
        }

        $payload = $this->removeNulls([
            'client' => $reference,
            'nom' => $this->truncate($contact->getLastname(), 150),
            'prenom' => $this->truncate($contact->getFirstname(), 150),
            'fonction' => $this->truncate($contact->getJobtitle(), 255),
            'telephone' => $this->truncate($contact->getPhone(), 255),
            'portable' => $this->truncate($contact->getMobilephone(), 255),
            'linkedin' => $this->truncate($contact->getRawProperties()['hs_linkedin_url'] ?? null, 255),
            'email' => $this->truncate($contact->getEmail(), 255),
            'hubSpotID' => $contact->getHubspotId(),
        ]);

        foreach ($this->findRelatedEntries('/Contacts', $reference) as $existing) {
            $sameId = $contact->getHubspotId() !== null && (string) ($existing['hubSpotID'] ?? '') === $contact->getHubspotId();
            $sameName = strcasecmp(trim((string) ($existing['nom'] ?? '')), (string) ($payload['nom'] ?? '')) === 0
                && strcasecmp(trim((string) ($existing['prenom'] ?? '')), (string) ($payload['prenom'] ?? '')) === 0;

            if ($sameId || $sameName) {
                $payload['nouveauNom'] = $payload['nom'] ?? '';
                $payload['nouveauPrenom'] = $payload['prenom'] ?? '';
                $payload['nom'] = $existing['nom'] ?? '';
                $payload['prenom'] = $existing['prenom'] ?? '';
                $this->sageClient->patch('/Contacts', $payload);

                return;
            }
        }

        $this->sageClient->post('/Contacts', $payload);
    }

    private function upsertDeliveryAddress(HubspotCompany $company, string $reference): void
    {
        $properties = $company->getRawProperties() ?? [];
        $address = $this->truncate($properties['delivery_address'] ?? null, 255);

        if ($address === null) {
            return;
        }

        $label = $this->truncate($properties['name_of_the_warehouse'] ?? null, 255)
            ?? $this->truncate($company->getName(), 255);
        $payload = $this->removeNulls([
            'client' => $reference,
            'intitule' => $label,
            'adresse' => $address,
            'complement' => $this->truncate($properties['delivery_address_2'] ?? null, 255),
            'codePostal' => $this->truncate($properties['postal_code_delivery'] ?? null, 50),
            'ville' => $this->truncate($properties['city_delivery'] ?? null, 150),
            'pays' => $this->truncate($properties['country_delivery'] ?? null, 150),
            'telephone' => $this->truncate($properties['phone_number_delivery'] ?? null, 255),
            'email' => $this->truncate($properties['email_address_delivery'] ?? null, 255),
            'livraisonPrincipal' => true,
        ]);
        $targetExists = false;

        foreach ($this->findRelatedEntries('/Livraisons', $reference) as $existing) {
            $existingLabel = trim((string) ($existing['intitule'] ?? ''));

            if (strcasecmp($existingLabel, (string) $label) === 0) {
                $targetExists = true;
            } elseif ($existingLabel !== '' && filter_var($existing['livraisonPrincipal'] ?? false, FILTER_VALIDATE_BOOL)) {
                $this->sageClient->patch('/Livraisons', [
                    'client' => $reference,
                    'intitule' => $existingLabel,
                    'nouveauIntitule' => $existingLabel,
                    'livraisonPrincipal' => false,
                ]);
            }
        }

        if ($targetExists) {
            $this->sageClient->patch('/Livraisons', $payload + ['nouveauIntitule' => $label]);
        } else {
            $this->sageClient->post('/Livraisons', $payload);
        }
    }

    private function findRelatedEntries(string $path, string $reference): array
    {
        try {
            $response = $this->sageClient->get($path, ['client' => $reference]);
        } catch (\RuntimeException $exception) {
            if (str_contains($exception->getMessage(), 'Erreur API Sage [404]')) {
                return [];
            }

            throw $exception;
        }

        return $response['results'] ?? $response;
    }

    private function buildContactFullName(HubspotContact $contact): ?string
    {
        $fullName = trim(sprintf(
            '%s %s',
            (string) $contact->getFirstname(),
            (string) $contact->getLastname()
        ));

        return $fullName !== '' ? $this->truncate($fullName, 255) : null;
    }

    private function truncate(?string $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, $maxLength);
    }

    private function removeNulls(array $data): array
    {
        foreach ($data as $key => $value) {
            if (\is_array($value)) {
                $data[$key] = $this->removeNulls($value);

                if ($data[$key] === []) {
                    unset($data[$key]);
                }

                continue;
            }

            if ($value === null) {
                unset($data[$key]);
            }
        }

        return $data;
    }

    private function resolveReference(HubspotCompany $company): ?string
    {
        $reference = trim((string) $company->getIdErp());

        if (!empty($reference)) {
            return $reference;
        }

        $name = $company->getName();

        if (empty($name)) {
            return null;
        }

        $normalizedName = preg_replace('/[^a-zA-Z]/', '', $name) ?? '';
        $prefix = strtoupper(substr($normalizedName, 0, 4));

        return '9' . str_pad($prefix, 4, 'X');
    }
}
