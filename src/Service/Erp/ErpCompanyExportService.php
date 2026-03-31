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
                $payload = $this->buildErpPayload($company);

                if ($payload === null) {
                    ++$skipped;
                    continue;
                }

                $payloads[] = $payload;

                $result = $this->sendToErpApi($payload);

                ++$sent;

                if (($result['action'] ?? null) === 'create') {
                    ++$created;
                } elseif (($result['action'] ?? null) === 'update') {
                    ++$updated;
                }

                $this->logger->info('ERP company sent', [
                    'companyHubspotId' => $company->getHubspotId(),
                    'companyName' => $company->getName(),
                    'action' => $result['action'] ?? null,
                    'reference' => $payload['reference'] ?? null,
                ]);
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

    private function buildErpPayload(HubspotCompany $company): ?array
    {
        $reference = $company->getIdErp();

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
        $response = $this->sageClient->get('/Clients', [
            'limit' => 1,
            'offset' => 0,
            'reference' => $reference,
            'estEnSommeil' => false,
        ]);

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
}