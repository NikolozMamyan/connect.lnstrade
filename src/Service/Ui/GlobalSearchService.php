<?php

namespace App\Service\Ui;

use App\Repository\DealRepository;
use App\Repository\ErpInvoiceRepository;
use App\Repository\ErpProductRepository;
use App\Repository\HubspotCompanyRepository;
use App\Repository\HubspotContactRepository;
use App\Repository\OrderFormRepository;
use App\Repository\UserRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class GlobalSearchService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly HubspotCompanyRepository $hubspotCompanyRepository,
        private readonly HubspotContactRepository $hubspotContactRepository,
        private readonly ErpProductRepository $erpProductRepository,
        private readonly DealRepository $dealRepository,
        private readonly OrderFormRepository $orderFormRepository,
        private readonly ErpInvoiceRepository $erpInvoiceRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @return array<int, array{title: string, subtitle: string, url: string, section: string}>
     */
    public function search(string $query, int $limit = 10): array
    {
        $query = trim($query);

        if (mb_strlen($query) < 2) {
            return [];
        }

        $results = [];

        foreach ($this->userRepository->searchByNameOrEmail($query, 3) as $user) {
            $results[] = [
                'title' => $user->getFullName(),
                'subtitle' => sprintf('Utilisateur · %s', (string) $user->getEmail()),
                'url' => $this->urlGenerator->generate('supervision_users_index', ['q' => $query]),
                'section' => 'Utilisateurs',
            ];
        }

        foreach ($this->hubspotCompanyRepository->searchByTerm($query, 3) as $company) {
            $subtitleParts = array_filter([
                'Company',
                $company->getCity(),
                $company->getIdErp() !== null && trim($company->getIdErp()) !== '' ? 'ERP ' . $company->getIdErp() : null,
            ]);

            $results[] = [
                'title' => (string) $company->getName(),
                'subtitle' => implode(' · ', $subtitleParts),
                'url' => $this->urlGenerator->generate('flux_client_index', ['q' => $query]),
                'section' => 'Clients',
            ];
        }

        foreach ($this->hubspotContactRepository->searchByNameOrEmail($query, 2) as $contact) {
            $contactName = trim(sprintf('%s %s', (string) $contact->getFirstname(), (string) $contact->getLastname()));

            $results[] = [
                'title' => $contactName !== '' ? $contactName : (string) $contact->getEmail(),
                'subtitle' => sprintf('Contact · %s', (string) $contact->getEmail()),
                'url' => $this->urlGenerator->generate('flux_client_index', ['q' => $query]),
                'section' => 'Clients',
            ];
        }

        foreach ($this->erpProductRepository->searchByTerm($query, 3) as $product) {
            $results[] = [
                'title' => (string) $product->getReference(),
                'subtitle' => sprintf('Produit · %s', (string) $product->getDesignation()),
                'url' => $this->urlGenerator->generate('flux_produits_index', ['q' => $query]),
                'section' => 'Produits',
            ];
        }

        foreach ($this->dealRepository->searchByTerm($query, 3) as $deal) {
            $results[] = [
                'title' => (string) $deal->getReferenceNumber(),
                'subtitle' => sprintf(
                    'Deal · %s',
                    trim(implode(' · ', array_filter([
                        $deal->getDealId() !== null ? 'ID ' . $deal->getDealId() : null,
                        $deal->getCommercial()?->getFullName(),
                    ])))
                ),
                'url' => $this->urlGenerator->generate('flux_commandes_index', ['q' => $query]),
                'section' => 'Commandes',
            ];
        }

        foreach ($this->orderFormRepository->searchByTerm($query, 2) as $orderForm) {
            $results[] = [
                'title' => (string) $orderForm->getReferenceNumber(),
                'subtitle' => sprintf('Order form · %s', (string) $orderForm->getStatus()),
                'url' => $this->urlGenerator->generate('flux_commandes_index', ['q' => $query]),
                'section' => 'Commandes',
            ];
        }

        foreach ($this->erpInvoiceRepository->findPaginated($query, '', '', 1, 2) as $invoice) {
            $results[] = [
                'title' => (string) $invoice->getInvoiceNumber(),
                'subtitle' => sprintf('Facture · Client %s', (string) $invoice->getClientId()),
                'url' => $this->urlGenerator->generate('flux_factures_show', ['invoiceNumber' => $invoice->getInvoiceNumber()]),
                'section' => 'Factures',
            ];
        }

        return array_slice($results, 0, max(1, $limit));
    }
}
