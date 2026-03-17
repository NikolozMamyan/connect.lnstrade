<?php

namespace App\Service\HubSpot;

use App\Entity\HubspotCompany;
use App\Entity\HubspotCompanyContact;
use App\Entity\HubspotContact;
use App\Repository\HubspotCompanyContactRepository;
use App\Repository\HubspotCompanyRepository;
use App\Repository\HubspotContactRepository;
use Doctrine\ORM\EntityManagerInterface;

class HubspotCompanySyncService
{
    private const COMPANY_PROPERTIES = [
        'name',
        'e_mail',
        'phone',
        'website',
        'address',
        'address2',
        'zip',
        'city',
        'country',
        'sage_integration',
        'hs_object_id',
    ];

    private const CONTACT_PROPERTIES = [
        'firstname',
        'lastname',
        'email',
        'phone',
        'mobilephone',
        'jobtitle',
        'company',
        'hs_object_id',
    ];

    public function __construct(
        private readonly HubSpotClient $hubSpotClient,
        private readonly HubspotCompanyRepository $hubspotCompanyRepository,
        private readonly HubspotContactRepository $hubspotContactRepository,
        private readonly HubspotCompanyContactRepository $hubspotCompanyContactRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function syncCompanies(): array
    {
        $savedCompanies = 0;
        $savedContacts = 0;
        $savedRelations = 0;

        $processedContactIds = [];
        $after = null;

        do {
            $query = [
                'limit' => 100,
                'properties' => self::COMPANY_PROPERTIES,
                'associations' => ['contacts'],
            ];

            if ($after !== null) {
                $query['after'] = $after;
            }

            $companiesResponse = $this->hubSpotClient->listObjects('companies', $query);
            $companies = $companiesResponse['results'] ?? [];

            foreach ($companies as $companyData) {
                $companyId = (string) ($companyData['id'] ?? '');

                if ($companyId === '') {
                    continue;
                }

                $companyProperties = $companyData['properties'] ?? [];

                if (mb_strtolower((string) ($companyProperties['sage_integration'] ?? '')) !== 'yes') {
                    continue;
                }

                $company = $this->hubspotCompanyRepository->findOneByHubspotId($companyId);
                $isNewCompany = false;

                if (!$company instanceof HubspotCompany) {
                    $company = new HubspotCompany();
                    $company->setHubspotId($companyId);
                    $isNewCompany = true;
                }

                $this->hydrateCompany($company, $companyData);
                $this->entityManager->persist($company);

                if ($isNewCompany) {
                    ++$savedCompanies;
                }

                $associatedContacts = $this->extractAssociatedContacts($companyData);

                foreach ($associatedContacts as $associationRow) {
                    $contactId = $associationRow['id'];
                    $associationType = $associationRow['type'];

                    if ($contactId === '') {
                        continue;
                    }

                    $contact = $this->hubspotContactRepository->findOneByHubspotId($contactId);
                    $isNewContact = false;
                    $mustFetchContact = true;

                    if (!$contact instanceof HubspotContact) {
                        $contact = new HubspotContact();
                        $contact->setHubspotId($contactId);
                        $isNewContact = true;
                    } elseif (isset($processedContactIds[$contactId])) {
                        $mustFetchContact = false;
                    }

                    if ($mustFetchContact) {
                        $contactData = $this->hubSpotClient->getObject('contacts', $contactId, [
                            'properties' => self::CONTACT_PROPERTIES,
                        ]);

                        $this->hydrateContact($contact, $contactData);
                        $this->entityManager->persist($contact);

                        $processedContactIds[$contactId] = true;

                        if ($isNewContact) {
                            ++$savedContacts;
                        }
                    }

                    $relation = $this->hubspotCompanyContactRepository->findOneRelationByHubspotIds(
                        $company->getHubspotId(),
                        $contact->getHubspotId(),
                        $associationType
                    );

                    if (!$relation instanceof HubspotCompanyContact) {
                        $relation = new HubspotCompanyContact();
                        $relation
                            ->setCompany($company)
                            ->setContact($contact)
                            ->setAssociationType($associationType)
                            ->setRawPayload($associationRow);

                        $this->entityManager->persist($relation);
                        ++$savedRelations;
                    }
                }
            }

            $this->entityManager->flush();
            $this->entityManager->clear();

            $after = $companiesResponse['paging']['next']['after'] ?? null;
        } while ($after !== null);

        return [
            'savedCompanies' => $savedCompanies,
            'savedContacts' => $savedContacts,
            'savedRelations' => $savedRelations,
        ];
    }

    private function hydrateCompany(HubspotCompany $company, array $companyData): void
    {
        $properties = $companyData['properties'] ?? [];

        $company
            ->setHubspotId((string) ($companyData['id'] ?? $company->getHubspotId()))
            ->setHubspotObjectId($this->nullableString($properties['hs_object_id'] ?? null))
            ->setName($this->nullableString($properties['name'] ?? null))
            ->setEmail($this->nullableString($properties['e_mail'] ?? null))
            ->setPhone($this->nullableString($properties['phone'] ?? null))
            ->setWebsite($this->nullableString($properties['website'] ?? null))
            ->setAddress($this->nullableString($properties['address'] ?? null))
            ->setAddress2($this->nullableString($properties['address2'] ?? null))
            ->setZip($this->nullableString($properties['zip'] ?? null))
            ->setCity($this->nullableString($properties['city'] ?? null))
            ->setCountry($this->nullableString($properties['country'] ?? null))
            ->setSageIntegration($this->nullableString($properties['sage_integration'] ?? null))
            ->setArchived((bool) ($companyData['archived'] ?? false))
            ->setHubspotUrl($this->nullableString($companyData['url'] ?? null))
            ->setRawProperties($properties)
            ->setRawPayload($companyData)
            ->setHubspotCreatedAt($this->toDateTimeImmutable($companyData['createdAt'] ?? $properties['createdate'] ?? null))
            ->setHubspotUpdatedAt($this->toDateTimeImmutable($companyData['updatedAt'] ?? $properties['hs_lastmodifieddate'] ?? null))
            ->setUpdatedAt(new \DateTimeImmutable());

        if ($company->getCreatedAt() === null) {
            $company->setCreatedAt(new \DateTimeImmutable());
        }
    }

    private function hydrateContact(HubspotContact $contact, array $contactData): void
    {
        $properties = $contactData['properties'] ?? [];

        $contact
            ->setHubspotId((string) ($contactData['id'] ?? $contact->getHubspotId()))
            ->setHubspotObjectId($this->nullableString($properties['hs_object_id'] ?? null))
            ->setFirstname($this->nullableString($properties['firstname'] ?? null))
            ->setLastname($this->nullableString($properties['lastname'] ?? null))
            ->setEmail($this->nullableString($properties['email'] ?? null))
            ->setPhone($this->nullableString($properties['phone'] ?? null))
            ->setMobilephone($this->nullableString($properties['mobilephone'] ?? null))
            ->setJobtitle($this->nullableString($properties['jobtitle'] ?? null))
            ->setCompanyName($this->nullableString($properties['company'] ?? null))
            ->setArchived((bool) ($contactData['archived'] ?? false))
            ->setHubspotUrl($this->nullableString($contactData['url'] ?? null))
            ->setRawProperties($properties)
            ->setRawPayload($contactData)
            ->setHubspotCreatedAt($this->toDateTimeImmutable($contactData['createdAt'] ?? $properties['createdate'] ?? null))
            ->setHubspotUpdatedAt($this->toDateTimeImmutable($contactData['updatedAt'] ?? $properties['hs_lastmodifieddate'] ?? null))
            ->setUpdatedAt(new \DateTimeImmutable());

        if ($contact->getCreatedAt() === null) {
            $contact->setCreatedAt(new \DateTimeImmutable());
        }
    }

    /**
     * @return array<int, array{id: string, type: string}>
     */
    private function extractAssociatedContacts(array $companyData): array
    {
        $results = $companyData['associations']['contacts']['results'] ?? [];

        $associations = [];
        $dedupe = [];

        foreach ($results as $row) {
            $contactId = (string) ($row['id'] ?? '');
            $type = (string) ($row['type'] ?? '');

            if ($contactId === '' || $type === '') {
                continue;
            }

            $key = $contactId . '|' . $type;

            if (isset($dedupe[$key])) {
                continue;
            }

            $dedupe[$key] = true;

            $associations[] = [
                'id' => $contactId,
                'type' => $type,
            ];
        }

        return $associations;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function toDateTimeImmutable(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}