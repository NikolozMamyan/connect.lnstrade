<?php

namespace App\Service\Erp;

use App\Entity\HubspotCompany;
use App\Entity\HubspotCompanyContact;
use App\Repository\HubspotCompanyRepository;
use Psr\Log\LoggerInterface;

class ErpCompanyExportService
{
    public function __construct(
        private readonly HubspotCompanyRepository $hubspotCompanyRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function sendCompaniesToErp(): array
    {
        $companies = $this->hubspotCompanyRepository->findAllWithContacts();

        $sent = 0;
        $skipped = 0;
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

                $this->logger->info('ERP company payload prepared', [
                    'companyHubspotId' => $company->getHubspotId(),
                    'companyName' => $company->getName(),
                    'payload' => $payload,
                ]);

                $payloads[] = $payload;

                // TODO: à implémenter quand l'API ERP sera disponible.
                // $this->sendToErpApi($payload);

                ++$sent;
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
            'skipped' => $skipped,
            'errors' => $errors,
            'payloads' => $payloads,
        ];
    }

    private function buildErpPayload(HubspotCompany $company): ?array
    {
        if ($company->getHubspotId() === null) {
            return null;
        }

        return [
            'external_id' => $company->getHubspotId(),
            'name' => $company->getName(),
            'email' => $company->getEmail(),
            'phone' => $company->getPhone(),
            'website' => $company->getWebsite(),
            'address' => [
                'line1' => $company->getAddress(),
                'line2' => $company->getAddress2(),
                'zip' => $company->getZip(),
                'city' => $company->getCity(),
                'country' => $company->getCountry(),
            ],
            'sage_integration' => $company->getSageIntegration(),
            'hubspot_object_id' => $company->getHubspotObjectId(),
            'hubspot_url' => $company->getHubspotUrl(),
            'contacts' => $this->buildContactsPayload($company),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildContactsPayload(HubspotCompany $company): array
    {
        $contacts = [];
        $dedupe = [];

        foreach ($company->getCompanyContacts() as $relation) {
            if (!$relation instanceof HubspotCompanyContact) {
                continue;
            }

            $contact = $relation->getContact();

            if ($contact === null || $contact->getHubspotId() === null) {
                continue;
            }

            $key = $contact->getHubspotId() . '|' . ($relation->getAssociationType() ?? '');

            if (isset($dedupe[$key])) {
                continue;
            }

            $dedupe[$key] = true;

            $contactPayload = [
                'external_id' => $contact->getHubspotId(),
                'firstname' => $contact->getFirstname(),
                'lastname' => $contact->getLastname(),
                'email' => $contact->getEmail(),
                'phone' => $contact->getPhone(),
                'mobilephone' => $contact->getMobilephone(),
                'jobtitle' => $contact->getJobtitle(),
                'company_name' => $contact->getCompanyName(),
                'association_type' => $relation->getAssociationType(),
            ];

            $this->logger->debug('ERP contact payload prepared', [
                'companyHubspotId' => $company->getHubspotId(),
                'contactHubspotId' => $contact->getHubspotId(),
                'payload' => $contactPayload,
            ]);

            $contacts[] = $contactPayload;
        }

        return $contacts;
    }

    /**
     * TODO
     * Cette méthode devra envoyer les données vers l'ERP quand l'API sera disponible.
     */
    private function sendToErpApi(array $payload): void
    {
        // À implémenter plus tard le erp client a developper.
    }
}