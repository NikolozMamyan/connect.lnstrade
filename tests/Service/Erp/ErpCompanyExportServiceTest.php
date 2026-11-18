<?php

namespace App\Tests\Service\Erp;

use App\Entity\Commercial;
use App\Entity\HubspotCompany;
use App\Entity\HubspotCompanyContact;
use App\Entity\HubspotContact;
use App\Repository\CommercialRepository;
use App\Repository\HubspotCompanyRepository;
use App\Service\Erp\ErpCompanyExportService;
use App\Service\Erp\SageClient;
use App\Service\HubSpot\HubSpotClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class ErpCompanyExportServiceTest extends TestCase
{
    public function testExportCreatesSageClientThenSynchronizesReferenceToHubSpot(): void
    {
        $sageCreated = false;
        $sagePayload = [];
        $contactPayload = [];
        $deliveryPayload = [];
        $hubspotPayload = [];
        $company = $this->createEligibleCompany()
            ->setCountry('France')
            ->setAddress('10 rue de Paris')
            ->setAddress2('Batiment A')
            ->setZip('75001')
            ->setCity('Paris')
            ->setPhone('0102030405')
            ->setWebsite('https://example.com')
            ->setRawProperties([
                'company_country_en' => 'France',
                'linkedin_company_page' => 'https://linkedin.com/company/acme',
                'facebook_company_page' => 'https://facebook.com/acme',
                'siret' => '12345678900012',
                'naf_code' => '4639B',
                'tva_intracommunautaire' => 'FR12345678901',
                'hubspot_owner_id' => 'owner-1',
                'insured_credit_limit' => '15000.50',
                'name_of_the_warehouse' => 'Entrepot ACME',
                'delivery_address' => '20 rue du Port',
                'delivery_address_2' => 'Quai 2',
                'postal_code_delivery' => '67000',
                'city_delivery' => 'Strasbourg',
                'country_delivery' => 'France',
                'phone_number_delivery' => '0304050607',
                'email_address_delivery' => 'warehouse@example.com',
            ]);
        $fallback = (new HubspotContact())->setHubspotId('contact-other')->setLastname('Autre');
        $primary = (new HubspotContact())
            ->setHubspotId('contact-123')
            ->setLastname('Dupont')
            ->setFirstname('Marie')
            ->setJobtitle('Acheteuse')
            ->setPhone('0102030406')
            ->setMobilephone('0607080910')
            ->setEmail('marie@example.com')
            ->setRawProperties(['hs_linkedin_url' => 'https://linkedin.com/in/marie']);
        $company->addCompanyContact((new HubspotCompanyContact())->setContact($fallback)->setIsPrimary(false));
        $company->addCompanyContact((new HubspotCompanyContact())->setContact($primary)->setIsPrimary(true));
        $commercialRepository = $this->createMock(CommercialRepository::class);
        $commercialRepository->expects($this->once())->method('findOneBy')->with(['hubspotId' => 'owner-1'])
            ->willReturn((new Commercial())->setSageFirstName('Jean')->setSageLastName('MARTIN'));

        $sageHttpClient = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$sageCreated, &$sagePayload, &$contactPayload, &$deliveryPayload): MockResponse {
                $path = parse_url($url, PHP_URL_PATH);

                if ($method === 'POST' && $path === '/auth/login') {
                    return self::jsonResponse(['accessToken' => 'sage-token']);
                }

                if ($method === 'GET' && $path === '/Clients') {
                    return self::jsonResponse(['results' => []]);
                }

                if ($method === 'POST' && $path === '/Clients') {
                    $sagePayload = json_decode((string) ($options['body'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
                    $sageCreated = true;

                    return self::jsonResponse(['reference' => $sagePayload['reference']]);
                }

                if ($method === 'GET' && in_array($path, ['/Contacts', '/Livraisons'], true)) {
                    self::assertTrue($sageCreated);

                    return new MockResponse('{"message":"Aucun resultat"}', ['http_code' => 404]);
                }

                if ($method === 'POST' && $path === '/Contacts') {
                    $contactPayload = json_decode((string) $options['body'], true, 512, JSON_THROW_ON_ERROR);

                    return self::jsonResponse(['success' => true]);
                }

                if ($method === 'POST' && $path === '/Livraisons') {
                    $deliveryPayload = json_decode((string) $options['body'], true, 512, JSON_THROW_ON_ERROR);

                    return self::jsonResponse(['success' => true]);
                }

                self::fail(sprintf('Unexpected Sage request: %s %s', $method, $url));
            }
        );
        $hubSpotHttpClient = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$sageCreated, &$hubspotPayload): MockResponse {
                self::assertTrue($sageCreated, 'HubSpot must only be updated after Sage accepts the client.');
                self::assertSame('PATCH', $method);
                self::assertSame('/crm/objects/v3/companies/company-123', parse_url($url, PHP_URL_PATH));

                $hubspotPayload = json_decode((string) ($options['body'] ?? ''), true, 512, JSON_THROW_ON_ERROR);

                return self::jsonResponse([
                    'id' => 'company-123',
                    'properties' => $hubspotPayload['properties'],
                ]);
            }
        );
        $companyRepository = $this->createMock(HubspotCompanyRepository::class);
        $companyRepository
            ->expects($this->once())
            ->method('findExportableWithContacts')
            ->willReturn([$company]);
        $companyRepository
            ->expects($this->once())
            ->method('save')
            ->with($company, true)
            ->willReturnCallback(static function (HubspotCompany $savedCompany) use (&$sageCreated): void {
                self::assertTrue($sageCreated, 'The ERP reference must not be persisted before Sage succeeds.');
                self::assertSame('9ACME', $savedCompany->getIdErp());
            });

        $result = $this->createService($companyRepository, $sageHttpClient, $hubSpotHttpClient, $commercialRepository)
            ->sendCompaniesToErp();

        self::assertSame('9ACME', $sagePayload['reference']);
        self::assertSame('ACME France', $sagePayload['intitule']);
        self::assertSame('ACME France', $sagePayload['abrege']);
        self::assertSame('Marie Dupont', $sagePayload['interlocuteur']);
        self::assertSame('10 rue de Paris', $sagePayload['adresse']);
        self::assertSame('Batiment A', $sagePayload['complementAdresse']);
        self::assertSame('75001', $sagePayload['codePostal']);
        self::assertSame('Paris', $sagePayload['ville']);
        self::assertSame('France', $sagePayload['pays']);
        self::assertSame('0102030405', $sagePayload['telephone']);
        self::assertSame('contact@example.com', $sagePayload['email']);
        self::assertSame('https://example.com', $sagePayload['siteInternet']);
        self::assertSame('https://linkedin.com/company/acme', $sagePayload['linkedin']);
        self::assertSame('https://facebook.com/acme', $sagePayload['facebook']);
        self::assertSame('12345678900012', $sagePayload['siret']);
        self::assertSame('4639B', $sagePayload['codeNaf']);
        self::assertSame('FR12345678901', $sagePayload['identifiantTva']);
        self::assertSame('MARTIN, Jean', $sagePayload['representant']);
        self::assertSame(15000.5, $sagePayload['encoursAutorise']);
        self::assertSame(15000.5, $sagePayload['assuranceCredit']);
        self::assertSame(['HUBSPOT_ID' => 'company-123', 'Facturation palette' => 'OUI'], $sagePayload['champsLibres']);
        self::assertSame([
            'client' => '9ACME',
            'nom' => 'Dupont',
            'prenom' => 'Marie',
            'fonction' => 'Acheteuse',
            'telephone' => '0102030406',
            'portable' => '0607080910',
            'linkedin' => 'https://linkedin.com/in/marie',
            'email' => 'marie@example.com',
            'hubSpotID' => 'contact-123',
        ], $contactPayload);
        self::assertSame([
            'client' => '9ACME',
            'intitule' => 'Entrepot ACME',
            'adresse' => '20 rue du Port',
            'complement' => 'Quai 2',
            'codePostal' => '67000',
            'ville' => 'Strasbourg',
            'pays' => 'France',
            'telephone' => '0304050607',
            'email' => 'warehouse@example.com',
            'livraisonPrincipal' => true,
        ], $deliveryPayload);
        self::assertSame(['id_erp' => '9ACME'], $hubspotPayload['properties']);
        self::assertSame('9ACME', $company->getIdErp());
        self::assertSame(1, $result['sent']);
        self::assertSame(1, $result['created']);
        self::assertSame(1, $result['hubspotUpdated']);
        self::assertSame([], $result['errors']);
    }

    public function testDirectExportSkipsCompanyThatIsNotEligibleForSage(): void
    {
        $company = (new HubspotCompany())
            ->setHubspotId('company-123')
            ->setName('ACME')
            ->setSageIntegration('no');
        $unexpectedRequest = static function (string $method, string $url): MockResponse {
            self::fail(sprintf('No HTTP request expected, got: %s %s', $method, $url));
        };
        $companyRepository = $this->createMock(HubspotCompanyRepository::class);
        $companyRepository->expects($this->never())->method('save');

        $result = $this->createService(
            $companyRepository,
            new MockHttpClient($unexpectedRequest),
            new MockHttpClient($unexpectedRequest),
        )->sendCompanyToErp($company);

        self::assertTrue($result['skipped']);
        self::assertSame('company_not_eligible', $result['reason']);
        self::assertNull($company->getIdErp());
    }

    public function testExistingSageClientAlsoSynchronizesItsReferenceToHubSpot(): void
    {
        $hubspotPayload = [];
        $company = $this->createEligibleCompany()->setIdErp('CLI-001');
        $sageHttpClient = new MockHttpClient(
            static function (string $method, string $url): MockResponse {
                $path = parse_url($url, PHP_URL_PATH);

                if ($method === 'POST' && $path === '/auth/login') {
                    return self::jsonResponse(['accessToken' => 'sage-token']);
                }

                if ($method === 'GET' && $path === '/Clients') {
                    return self::jsonResponse([
                        'results' => [
                            ['reference' => 'CLI-001'],
                        ],
                    ]);
                }

                if ($method === 'PATCH' && $path === '/Clients') {
                    return self::jsonResponse(['reference' => 'CLI-001']);
                }

                self::fail(sprintf('Unexpected Sage request: %s %s', $method, $url));
            }
        );
        $hubSpotHttpClient = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$hubspotPayload): MockResponse {
                self::assertSame('PATCH', $method);
                self::assertSame('/crm/objects/v3/companies/company-123', parse_url($url, PHP_URL_PATH));
                $hubspotPayload = json_decode((string) ($options['body'] ?? ''), true, 512, JSON_THROW_ON_ERROR);

                return self::jsonResponse(['id' => 'company-123']);
            }
        );
        $companyRepository = $this->createMock(HubspotCompanyRepository::class);
        $companyRepository->expects($this->never())->method('save');

        $result = $this->createService($companyRepository, $sageHttpClient, $hubSpotHttpClient)
            ->sendCompanyToErp($company);

        self::assertFalse($result['skipped']);
        self::assertSame('update', $result['action']);
        self::assertTrue($result['hubspotUpdated']);
        self::assertSame(['id_erp' => 'CLI-001'], $hubspotPayload['properties']);
    }

    #[DataProvider('countryMappings')]
    public function testCountryControlsPricingAccountingAndVat(?string $country, ?string $pricing, ?string $accounting, ?bool $vat): void
    {
        $company = $this->createEligibleCompany()->setIdErp('CLI-001')->setCountry($country)
            ->setRawProperties(['insured_credit_limit' => '0']);
        $result = $this->createService(
            $this->createStub(HubspotCompanyRepository::class),
            new MockHttpClient([
                self::jsonResponse(['accessToken' => 'sage-token']),
                self::jsonResponse(['results' => [['reference' => 'CLI-001']]]),
                self::jsonResponse(['success' => true]),
            ]),
            new MockHttpClient(self::jsonResponse(['id' => 'company-123'])),
        )->sendCompanyToErp($company);

        self::assertSame($pricing, $result['payload']['categorieTarifaire'] ?? null);
        self::assertSame($accounting, $result['payload']['categorieComptable'] ?? null);
        self::assertSame($vat, $result['payload']['assujettiALaTVA'] ?? null);
        self::assertSame(0.0, $result['payload']['encoursAutorise']);
        self::assertSame(0.0, $result['payload']['assuranceCredit']);
    }

    public static function countryMappings(): iterable
    {
        yield 'France' => ['France', 'Tarif public France', 'Vente France', true];
        yield 'French ISO code' => ['FR', 'Tarif public France', 'Vente France', true];
        yield 'EU' => ['Germany', 'Tarif public Hors France', 'Vente UE', false];
        yield 'EU ISO code' => ['ES', 'Tarif public Hors France', 'Vente UE', false];
        yield 'Czech Republic' => ['Czech Republic', 'Tarif public Hors France', 'Vente UE', false];
        yield 'Non EU' => ['United States', 'Tarif public Hors France', 'Vente hors UE', false];
        yield 'UK' => ['United Kingdom', 'Tarif public Hors France', 'Vente hors UE', false];
        yield 'Missing country' => [null, null, null, null];
    }

    public function testGeneratedReferenceDoesNotOverwriteAnotherCompany(): void
    {
        $company = $this->createEligibleCompany();
        $lookedUp = [];
        $sageHttpClient = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$lookedUp): MockResponse {
                $path = parse_url($url, PHP_URL_PATH);

                if ($method === 'POST' && $path === '/auth/login') {
                    return self::jsonResponse(['accessToken' => 'sage-token']);
                }

                if ($method === 'GET' && $path === '/Clients') {
                    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                    $lookedUp[] = $query['reference'];

                    return self::jsonResponse(['results' => $query['reference'] === '9ACME' ? [
                        ['reference' => '9ACME', 'champsLibres' => ['HUBSPOT_ID' => 'another-company']],
                    ] : []]);
                }

                if ($method === 'POST' && $path === '/Clients') {
                    $payload = json_decode((string) $options['body'], true, 512, JSON_THROW_ON_ERROR);
                    self::assertSame('9ACME2', $payload['reference']);

                    return self::jsonResponse(['success' => true]);
                }

                self::fail(sprintf('Unexpected Sage request: %s %s', $method, $url));
            }
        );
        $hubspotHttpClient = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame('PATCH', $method);
            $payload = json_decode((string) $options['body'], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(['id_erp' => '9ACME2'], $payload['properties']);

            return self::jsonResponse(['id' => 'company-123']);
        });

        $result = $this->createService($this->createStub(HubspotCompanyRepository::class), $sageHttpClient, $hubspotHttpClient)
            ->sendCompanyToErp($company);

        self::assertSame(['9ACME', '9ACME2'], $lookedUp);
        self::assertSame('create', $result['action']);
        self::assertSame('9ACME2', $result['reference']);
        self::assertSame('9ACME2', $company->getIdErp());
    }

    public function testRetryReusesSageClientWithSameHubspotId(): void
    {
        $company = $this->createEligibleCompany();
        $sageHttpClient = new MockHttpClient(static function (string $method, string $url): MockResponse {
            $path = parse_url($url, PHP_URL_PATH);

            return match ([$method, $path]) {
                ['POST', '/auth/login'] => self::jsonResponse(['accessToken' => 'sage-token']),
                ['GET', '/Clients'] => self::jsonResponse(['results' => [[
                    'reference' => '9ACME', 'champsLibres' => ['HUBSPOT_ID' => 'company-123'],
                ]]]),
                ['PATCH', '/Clients'] => self::jsonResponse(['success' => true]),
                default => self::fail(sprintf('Unexpected Sage request: %s %s', $method, $url)),
            };
        });

        $result = $this->createService(
            $this->createStub(HubspotCompanyRepository::class),
            $sageHttpClient,
            new MockHttpClient(self::jsonResponse(['id' => 'company-123'])),
        )->sendCompanyToErp($company);

        self::assertSame('update', $result['action']);
        self::assertSame('9ACME', $company->getIdErp());
    }

    public function testExistingContactAndDeliveryAreUpdatedOnRetry(): void
    {
        $company = $this->createEligibleCompany()->setIdErp('CLI-001')->setRawProperties([
            'name_of_the_warehouse' => 'Entrepot ACME',
            'delivery_address' => 'Nouvelle adresse',
        ]);
        $contact = (new HubspotContact())->setHubspotId('contact-123')->setLastname('Nouveau')->setFirstname('Marie');
        $company->addCompanyContact((new HubspotCompanyContact())->setContact($contact)->setIsPrimary(true));
        $oldIsMain = true;
        $contactUpdates = 0;
        $deliveryUpdates = 0;
        $sageHttpClient = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$oldIsMain, &$contactUpdates, &$deliveryUpdates): MockResponse {
                $path = parse_url($url, PHP_URL_PATH);

                if ($method === 'POST' && $path === '/auth/login') {
                    return self::jsonResponse(['accessToken' => 'sage-token']);
                }

                if ($method === 'GET') {
                    return match ($path) {
                        '/Clients' => self::jsonResponse(['results' => [['reference' => 'CLI-001']]]),
                        '/Contacts' => self::jsonResponse([[
                            'nom' => 'Ancien', 'prenom' => 'Marie', 'hubSpotID' => 'contact-123',
                        ]]),
                        '/Livraisons' => self::jsonResponse([
                            ['intitule' => 'Autre entrepot', 'livraisonPrincipal' => $oldIsMain],
                            ['intitule' => 'Entrepot ACME', 'livraisonPrincipal' => false],
                        ]),
                        default => self::fail(sprintf('Unexpected Sage lookup: %s', $url)),
                    };
                }

                self::assertSame('PATCH', $method, 'Retries must not create duplicate contacts or addresses.');
                $payload = json_decode((string) $options['body'], true, 512, JSON_THROW_ON_ERROR);

                if ($path === '/Contacts') {
                    ++$contactUpdates;
                    self::assertSame('Ancien', $payload['nom']);
                    self::assertSame('Nouveau', $payload['nouveauNom']);
                    self::assertSame('Marie', $payload['nouveauPrenom']);
                } elseif ($path === '/Livraisons' && $payload['intitule'] === 'Autre entrepot') {
                    self::assertFalse($payload['livraisonPrincipal']);
                    $oldIsMain = false;
                } elseif ($path === '/Livraisons') {
                    ++$deliveryUpdates;
                    self::assertSame('Entrepot ACME', $payload['nouveauIntitule']);
                    self::assertSame('Nouvelle adresse', $payload['adresse']);
                    self::assertTrue($payload['livraisonPrincipal']);
                }

                return self::jsonResponse(['success' => true]);
            }
        );
        $service = $this->createService(
            $this->createStub(HubspotCompanyRepository::class),
            $sageHttpClient,
            new MockHttpClient(static fn (): MockResponse => self::jsonResponse(['id' => 'company-123'])),
        );

        $service->sendCompanyToErp($company);
        $service->sendCompanyToErp($company);

        self::assertFalse($oldIsMain);
        self::assertSame(2, $contactUpdates);
        self::assertSame(2, $deliveryUpdates);
    }

    public function testInvalidCreditLimitFailsBeforeAnyExternalWrite(): void
    {
        $company = $this->createEligibleCompany()->setRawProperties(['insured_credit_limit' => 'invalid']);
        $unexpectedRequest = static function (): MockResponse {
            self::fail('Invalid credit must not be sent to Sage or HubSpot.');
        };
        $service = $this->createService(
            $this->createStub(HubspotCompanyRepository::class),
            new MockHttpClient($unexpectedRequest),
            new MockHttpClient($unexpectedRequest),
        );

        $this->expectException(\InvalidArgumentException::class);
        $service->sendCompanyToErp($company);
    }

    public function testRepresentativeFallsBackToHubspotOwnerIdentity(): void
    {
        $company = $this->createEligibleCompany()->setIdErp('CLI-001')->setRawProperties(['hubspot_owner_id' => 'owner-1']);
        $hubspotHttpClient = new MockHttpClient(static function (string $method, string $url): MockResponse {
            return match ([$method, parse_url($url, PHP_URL_PATH)]) {
                ['GET', '/crm/v3/owners/owner-1'] => self::jsonResponse(['firstName' => 'Jean', 'lastName' => 'Martin']),
                ['PATCH', '/crm/objects/v3/companies/company-123'] => self::jsonResponse(['id' => 'company-123']),
                default => self::fail(sprintf('Unexpected HubSpot request: %s %s', $method, $url)),
            };
        });

        $result = $this->createService(
            $this->createStub(HubspotCompanyRepository::class),
            new MockHttpClient([
                self::jsonResponse(['accessToken' => 'sage-token']),
                self::jsonResponse(['results' => [['reference' => 'CLI-001']]]),
                self::jsonResponse(['success' => true]),
            ]),
            $hubspotHttpClient,
        )->sendCompanyToErp($company);

        self::assertSame('Martin, Jean', $result['payload']['representant']);
    }

    public function testRemovedPrimaryAssociationIsNotExportedAgain(): void
    {
        $company = $this->createEligibleCompany()->setIdErp('CLI-001')->setRawPayload([
            'associations' => ['contacts' => ['results' => [
                ['id' => 'current-contact', 'type' => 'company_to_contact_unlabeled'],
            ]]],
        ]);
        $oldContact = (new HubspotContact())->setHubspotId('old-contact')->setLastname('Ancien');
        $currentContact = (new HubspotContact())->setHubspotId('current-contact')->setLastname('Actuel');
        $company->addCompanyContact((new HubspotCompanyContact())->setContact($oldContact)
            ->setAssociationType('company_to_contact')->setIsPrimary(true));
        $company->addCompanyContact((new HubspotCompanyContact())->setContact($currentContact)
            ->setAssociationType('company_to_contact_unlabeled')->setIsPrimary(false));
        $sageHttpClient = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            $path = parse_url($url, PHP_URL_PATH);

            if ($method === 'POST' && $path === '/Contacts') {
                $payload = json_decode((string) $options['body'], true, 512, JSON_THROW_ON_ERROR);
                self::assertSame('current-contact', $payload['hubSpotID']);
                self::assertSame('Actuel', $payload['nom']);

                return self::jsonResponse(['success' => true]);
            }

            return match ([$method, $path]) {
                ['POST', '/auth/login'] => self::jsonResponse(['accessToken' => 'sage-token']),
                ['GET', '/Clients'] => self::jsonResponse(['results' => [['reference' => 'CLI-001']]]),
                ['PATCH', '/Clients'] => self::jsonResponse(['success' => true]),
                ['GET', '/Contacts'] => self::jsonResponse([]),
                default => self::fail(sprintf('Unexpected Sage request: %s %s', $method, $url)),
            };
        });

        $result = $this->createService(
            $this->createStub(HubspotCompanyRepository::class),
            $sageHttpClient,
            new MockHttpClient(self::jsonResponse(['id' => 'company-123'])),
        )->sendCompanyToErp($company);

        self::assertSame('Actuel', $result['payload']['interlocuteur']);
    }

    public function testContactFailureCanBeRetriedWithoutConfirmingAnIncompleteExport(): void
    {
        $company = $this->createEligibleCompany();
        $company->addCompanyContact((new HubspotCompanyContact())->setContact(
            (new HubspotContact())->setHubspotId('contact-123')->setLastname('Dupont')
        ));
        $companyRepository = $this->createMock(HubspotCompanyRepository::class);
        $companyRepository->expects($this->exactly(2))->method('findExportableWithContacts')->willReturn([$company]);
        $companyRepository->expects($this->once())->method('save')->with($company, true);
        $createdClients = 0;
        $contactAttempts = 0;
        $sageHttpClient = new MockHttpClient(
            static function (string $method, string $url) use (&$createdClients, &$contactAttempts): MockResponse {
                $path = parse_url($url, PHP_URL_PATH);

                if ($method === 'POST' && $path === '/auth/login') {
                    return self::jsonResponse(['accessToken' => 'sage-token']);
                }

                if ($method === 'GET' && $path === '/Clients') {
                    return self::jsonResponse(['results' => $createdClients === 0 ? [] : [[
                        'reference' => '9ACME', 'champsLibres' => ['HUBSPOT_ID' => 'company-123'],
                    ]]]);
                }

                if ($method === 'POST' && $path === '/Clients') {
                    ++$createdClients;

                    return self::jsonResponse(['reference' => '9ACME']);
                }

                if ($method === 'GET' && $path === '/Contacts') {
                    return self::jsonResponse([]);
                }

                if ($method === 'POST' && $path === '/Contacts') {
                    ++$contactAttempts;

                    return $contactAttempts === 1
                        ? new MockResponse('{"message":"Contact invalide"}', ['http_code' => 400])
                        : self::jsonResponse(['success' => true]);
                }

                self::assertSame(['PATCH', '/Clients'], [$method, $path]);

                return self::jsonResponse(['success' => true]);
            }
        );
        $hubspotHttpClient = new MockHttpClient(static function () use (&$contactAttempts): MockResponse {
            self::assertSame(2, $contactAttempts, 'HubSpot must only be updated after the complete Sage export.');

            return self::jsonResponse(['id' => 'company-123']);
        });
        $service = $this->createService(
            $companyRepository,
            $sageHttpClient,
            $hubspotHttpClient,
        );
        $result = $service->sendCompaniesToErp();

        self::assertNull($company->getIdErp());
        self::assertSame(0, $result['sent']);
        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('400', $result['errors'][0]['message']);

        $result = $service->sendCompaniesToErp();

        self::assertSame(1, $createdClients);
        self::assertSame(2, $contactAttempts);
        self::assertSame('9ACME', $company->getIdErp());
        self::assertSame(1, $result['sent']);
        self::assertSame(1, $result['updated']);
        self::assertSame([], $result['errors']);
    }

    private function createEligibleCompany(): HubspotCompany
    {
        return (new HubspotCompany())
            ->setHubspotId('company-123')
            ->setName('ACME France')
            ->setEmail('contact@example.com')
            ->setSageIntegration('Yes')
            ->setArchived(false);
    }

    private function createService(
        HubspotCompanyRepository $companyRepository,
        MockHttpClient $sageHttpClient,
        MockHttpClient $hubSpotHttpClient,
        ?CommercialRepository $commercialRepository = null,
    ): ErpCompanyExportService {
        $parameters = new ParameterBag([
            'base_uri_hubspot' => 'https://hubspot.test',
            'hubspot_access' => 'hubspot-token',
            'base_uri_sage' => 'https://sage.test',
            'sage_username' => 'user',
            'sage_password' => 'password',
        ]);

        return new ErpCompanyExportService(
            $companyRepository,
            new SageClient($sageHttpClient, $parameters),
            new HubSpotClient($hubSpotHttpClient, $parameters),
            $commercialRepository ?? $this->createStub(CommercialRepository::class),
            new NullLogger(),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function jsonResponse(array $data): MockResponse
    {
        return new MockResponse(
            json_encode($data, JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type' => 'application/json']]
        );
    }
}
