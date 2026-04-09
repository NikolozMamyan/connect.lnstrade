<?php

namespace App\Controller\Flux;

use App\Message\SyncProductMessage;
use App\Message\SyncProductStockMessage;
use App\Repository\ErpProductRepository;
use App\Repository\SyncLogRepository;
use App\Service\Log\SyncLogService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/flux/produits', name: 'flux_produits_')]
class FluxProduitsController extends AbstractController
{
    /**
     * Liste des produits/articles synchronisés
     * Flux : Article Sage => Products HubSpot (unilatéral)
     * Sens : Sage → HubSpot
     */
    #[Route('/', name: 'index')]
    public function index(ErpProductRepository $erpProductRepository): Response
    {
        return $this->render('flux/produits/index.html.twig', [
            'products' => $erpProductRepository->findBy([], ['reference' => 'ASC']),
        ]);
    }

    #[Route('/sync', name: 'sync', methods: ['POST'])]
    public function sync(MessageBusInterface $bus, SyncLogService $syncLogService): Response
    {
        $bus->dispatch(new SyncProductMessage());

        $syncLogService->info(
            'product',
            'Synchronisation produits demandee',
            'Le message Messenger a ete envoye pour lancer la synchronisation produits.'
        );

        $this->addFlash('success', 'Synchronisation produits lancee en arriere-plan.');

        return $this->redirectToRoute('flux_produits_index');
    }

    /**
     * Mapping : Identification article
     */
    #[Route('/mapping/identification', name: 'mapping_identification')]
    public function mappingIdentification(): Response
    {
        return $this->render('flux/produits/mapping_identification.html.twig');
    }

    /**
     * Mapping : Descriptif & catalogue
     */
    #[Route('/mapping/descriptif', name: 'mapping_descriptif')]
    public function mappingDescriptif(): Response
    {
        return $this->render('flux/produits/mapping_descriptif.html.twig');
    }

    /**
     * Mapping : Logistique (stocks, poids, codes barres)
     */
    #[Route('/mapping/logistique', name: 'mapping_logistique')]
    public function mappingLogistique(ErpProductRepository $erpProductRepository): Response
    {
        return $this->render('flux/produits/mapping_logistique.html.twig', [
            'products' => $erpProductRepository->findBy([], ['designation' => 'ASC', 'reference' => 'ASC']),
        ]);
    }

    #[Route('/mapping/logistique/sync-stock', name: 'sync_stock', methods: ['POST'])]
    public function syncStock(MessageBusInterface $bus, SyncLogService $syncLogService): Response
    {
        $bus->dispatch(new SyncProductStockMessage());

        $syncLogService->info(
            'product_stock',
            'Synchronisation stock demandee',
            'Le message Messenger a ete envoye pour lancer la synchronisation des stocks produits.'
        );

        $this->addFlash('success', 'Synchronisation stock produits lancee en arriere-plan.');

        return $this->redirectToRoute('flux_produits_mapping_logistique');
    }

    /**
     * Logs de synchronisation produits
     */
    #[Route('/logs', name: 'logs')]
    public function logs(SyncLogRepository $syncLogRepository): Response
    {
        return $this->render('flux/produits/logs.html.twig', [
            'logs' => $syncLogRepository->findLatestByFluxKeys(['product', 'product_stock'], 150),
        ]);
    }
}
