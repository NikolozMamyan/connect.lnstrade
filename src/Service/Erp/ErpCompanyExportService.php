<?php

namespace App\Service\Erp;

use App\Entity\HubspotCompany;
use App\Entity\HubspotCompanyContact;
use App\Entity\HubspotContact;
use App\Repository\HubspotCompanyRepository;
use Psr\Log\LoggerInterface;

class ErpCompanyExportService
{
    public function __construct(
        private readonly HubspotCompanyRepository $hubspotCompanyRepository,
        private readonly SageClient $sageClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function sendCompaniesToErp(): array
    {
        $companies = $this->hubspotCompanyRepository->findAllWithContacts();

        $sent = 0;
        $skipped = 0;
        $updated = 0;
        $created = 0;
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

                $payloads[] = $result['payload'];
                ++$sent;

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

        $payload = [
            'reference' => $reference,
            'intitule' => $this->truncate($company->getName(), 255),
            'abrege' => $this->truncate($company->getName(), 17),
            'interlocuteur' => $mainContact ? $this->buildContactFullName($mainContact) : null,
            'adresse' => $this->truncate($company->getAddress(), 255),
            'complementAdresse' => $this->truncate($company->getAddress2(), 255),
            'codePostal' => $this->truncate($company->getZip(), 50),
            'ville' => $this->truncate($company->getCity(), 150),
            'pays' => $this->truncate($company->getCountry(), 150),
            'telephone' => $this->truncate($company->getPhone(), 255),
            'email' => $this->truncate($company->getEmail(), 255),
            'siteInternet' => $this->truncate($company->getWebsite(), 255),
            'estEnSommeil' => false,
            'assujettiALaTVA' => true,
            'champsLibres' => [
                'hubspot_id' => $company->getHubspotId(),
                'hubspot_object_id' => $company->getHubspotObjectId(),
                'hubspot_url' => $company->getHubspotUrl(),
                'sage_integration' => $company->getSageIntegration(),
            ],
        ];

        return $this->removeNulls($payload);
    }

    private function exportCompany(HubspotCompany $company): array
    {
        $payload = $this->buildErpPayload($company);

        if ($payload === null) {
            return [
                'skipped' => true,
                'companyHubspotId' => $company->getHubspotId(),
                'companyName' => $company->getName(),
            ];
        }

        $result = $this->sendToErpApi($payload);

        $this->logger->info('ERP company sent', [
            'companyHubspotId' => $company->getHubspotId(),
            'companyName' => $company->getName(),
            'action' => $result['action'] ?? null,
            'reference' => $payload['reference'] ?? null,
        ]);

        return [
            'skipped' => false,
            'action' => $result['action'] ?? null,
            'payload' => $payload,
            'response' => $result['response'] ?? null,
            'reference' => $payload['reference'] ?? null,
            'companyHubspotId' => $company->getHubspotId(),
            'companyName' => $company->getName(),
        ];
    }

    private function sendToErpApi(array $payload): array
    {
        $reference = (string) ($payload['reference'] ?? '');

        if ($reference === '') {
            throw new \RuntimeException('Payload ERP invalide : reference manquante.');
        }

        $existing = $this->findClientByReference($reference);

        if ($existing !== null) {
            $response = $this->sageClient->patch('/Clients', $payload);

            return [
                'action' => 'update',
                'reference' => $reference,
                'response' => $response,
            ];
        }

        $response = $this->sageClient->post('/Clients', $payload);

        return [
            'action' => 'create',
            'reference' => $reference,
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
                'estEnSommeil' => false,
            ]);
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();

            if (str_contains($message, 'Erreur API Sage [404]')) {
                return null;
            }

            throw $e;
        }

        if (isset($response['results']) && \is_array($response['results']) && $response['results'] !== []) {
            return $response['results'][0];
        }

        if (isset($response[0]) && \is_array($response[0])) {
            return $response[0];
        }

        if (isset($response['reference']) && $response['reference'] === $reference) {
            return $response;
        }

        return null;
    }

    private function resolveMainContact(HubspotCompany $company): ?HubspotContact
    {
        $fallback = null;

        foreach ($company->getCompanyContacts() as $relation) {
            if (!$relation instanceof HubspotCompanyContact) {
                continue;
            }

            $contact = $relation->getContact();

            if (!$contact instanceof HubspotContact) {
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
    $reference = $company->getIdErp();

    if (!empty($reference)) {
        return $reference;
    }

    $name = $company->getName();

    if (empty($name)) {
        return null;
    }

    // Nettoyage + récupération des 4 premières lettres
    $prefix = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $name), 0, 4));

    return '9' . str_pad($prefix, 4, 'X'); // fallback si < 4 lettres
}
}
