<?php

namespace App\Tests\Service\Ui;

use App\Entity\Commercial;
use App\Entity\Deal;
use App\Entity\ErpInvoice;
use App\Entity\ErpProduct;
use App\Entity\HubspotCompany;
use App\Entity\HubspotContact;
use App\Entity\OrderForm;
use App\Entity\User;
use App\Repository\DealRepository;
use App\Repository\ErpInvoiceRepository;
use App\Repository\ErpProductRepository;
use App\Repository\HubspotCompanyRepository;
use App\Repository\HubspotContactRepository;
use App\Repository\OrderFormRepository;
use App\Repository\UserRepository;
use App\Service\Ui\GlobalSearchService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class GlobalSearchServiceTest extends TestCase
{
    public function testSearchReturnsResultsAcrossSections(): void
    {
        $userRepository = $this->createStub(UserRepository::class);
        $companyRepository = $this->createStub(HubspotCompanyRepository::class);
        $contactRepository = $this->createStub(HubspotContactRepository::class);
        $productRepository = $this->createStub(ErpProductRepository::class);
        $dealRepository = $this->createStub(DealRepository::class);
        $orderFormRepository = $this->createStub(OrderFormRepository::class);
        $invoiceRepository = $this->createStub(ErpInvoiceRepository::class);
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            static fn (string $route, array $parameters = []): string => '/' . $route . ($parameters !== [] ? '?' . http_build_query($parameters) : '')
        );

        $user = (new User())
            ->setFirstName('Alice')
            ->setLastName('Martin')
            ->setEmail('alice@example.test');
        $userRepository->method('searchByNameOrEmail')->willReturn([$user]);

        $company = (new HubspotCompany())
            ->setHubspotId('123')
            ->setName('Alpha Retail')
            ->setCity('Paris')
            ->setIdErp('ERP-10');
        $companyRepository->method('searchByTerm')->willReturn([$company]);

        $contact = (new HubspotContact())
            ->setHubspotId('124')
            ->setFirstname('Nina')
            ->setLastname('Durand')
            ->setEmail('nina@example.test');
        $contactRepository->method('searchByNameOrEmail')->willReturn([$contact]);

        $product = (new ErpProduct())
            ->setReference('AR-100')
            ->setDesignation('Produit test');
        $productRepository->method('searchByTerm')->willReturn([$product]);

        $commercial = (new Commercial())->setFirstName('Paul')->setLastName('Simon');
        $deal = (new Deal())
            ->setReferenceNumber('OF-ABC123')
            ->setDealId('987')
            ->setCommercial($commercial);
        $dealRepository->method('searchByTerm')->willReturn([$deal]);

        $orderForm = (new OrderForm())
            ->setDealType(OrderForm::DEAL_TYPE_NOUVEAU)
            ->setReferenceNumber('OF-XYZ789')
            ->setStatus(OrderForm::STATUS_FAILED);
        $orderFormRepository->method('searchByTerm')->willReturn([$orderForm]);

        $invoice = (new ErpInvoice())
            ->setInvoiceNumber('FA-2026-001')
            ->setClientId('CLI-10');
        $invoiceRepository->method('findPaginated')->willReturn([$invoice]);

        $service = new GlobalSearchService(
            $userRepository,
            $companyRepository,
            $contactRepository,
            $productRepository,
            $dealRepository,
            $orderFormRepository,
            $invoiceRepository,
            $urlGenerator
        );

        $results = $service->search('alpha');

        self::assertNotEmpty($results);
        self::assertContains('Utilisateurs', array_column($results, 'section'));
        self::assertContains('Clients', array_column($results, 'section'));
        self::assertContains('Produits', array_column($results, 'section'));
        self::assertContains('Commandes', array_column($results, 'section'));
        self::assertContains('Factures', array_column($results, 'section'));
    }

    public function testSearchIgnoresVeryShortTerms(): void
    {
        $service = new GlobalSearchService(
            $this->createStub(UserRepository::class),
            $this->createStub(HubspotCompanyRepository::class),
            $this->createStub(HubspotContactRepository::class),
            $this->createStub(ErpProductRepository::class),
            $this->createStub(DealRepository::class),
            $this->createStub(OrderFormRepository::class),
            $this->createStub(ErpInvoiceRepository::class),
            $this->createStub(UrlGeneratorInterface::class)
        );

        self::assertSame([], $service->search('a'));
    }
}
