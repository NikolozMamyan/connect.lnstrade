<?php

namespace App\Controller\Flux;

use App\Entity\Notification;
use App\Repository\ErpProductRepository;
use App\Repository\SyncLogRepository;
use App\Service\Flux\SyncJobDispatcher;
use App\Service\Log\SyncLogService;
use App\Service\Ui\NotificationManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/flux/produits', name: 'flux_produits_')]
class FluxProduitsController extends AbstractController
{
    #[Route('/', name: 'index')]
    public function index(Request $request, ErpProductRepository $erpProductRepository): Response
    {
        $query = trim((string) $request->query->get('q', ''));

        return $this->render('flux/produits/index.html.twig', [
            'products' => $query !== '' ? $erpProductRepository->searchByTerm($query) : $erpProductRepository->findBy([], ['reference' => 'ASC']),
            'searchQuery' => $query,
        ]);
    }

    #[Route('/sync', name: 'sync', methods: ['POST'])]
    public function sync(
        Request $request,
        SyncJobDispatcher $syncJobDispatcher,
        SyncLogService $syncLogService,
        NotificationManager $notificationManager,
    ): Response {
        if (!$this->isCsrfTokenValid('flux_product_sync', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');

            return $this->redirectToRoute('flux_produits_index');
        }

        $syncJobDispatcher->dispatch(SyncJobDispatcher::PRODUCT);

        $syncLogService->info(
            'product',
            'Synchronisation produits demandee',
            'Le message Messenger a ete envoye pour lancer la synchronisation produits.'
        );

        $notificationManager->notify(
            'Synchronisation produits lancee',
            'La demande catalogue produits a ete prise en compte par la file de traitement.',
            Notification::LEVEL_INFO,
            'flux_produits_logs'
        );

        $this->addFlash('success', 'Synchronisation produits lancee en arriere-plan.');

        return $this->redirectToRoute('flux_produits_index');
    }

    #[Route('/mapping/identification', name: 'mapping_identification')]
    public function mappingIdentification(): Response
    {
        return $this->render('flux/produits/mapping_identification.html.twig');
    }

    #[Route('/mapping/descriptif', name: 'mapping_descriptif')]
    public function mappingDescriptif(): Response
    {
        return $this->render('flux/produits/mapping_descriptif.html.twig');
    }

    #[Route('/mapping/logistique', name: 'mapping_logistique')]
    public function mappingLogistique(ErpProductRepository $erpProductRepository): Response
    {
        return $this->render('flux/produits/mapping_logistique.html.twig', [
            'products' => $erpProductRepository->findBy([], ['designation' => 'ASC', 'reference' => 'ASC']),
        ]);
    }

    #[Route('/mapping/logistique/sync-stock', name: 'sync_stock', methods: ['POST'])]
    public function syncStock(
        Request $request,
        SyncJobDispatcher $syncJobDispatcher,
        SyncLogService $syncLogService,
        NotificationManager $notificationManager,
    ): Response {
        if (!$this->isCsrfTokenValid('flux_product_stock_sync', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');

            return $this->redirectToRoute('flux_produits_mapping_logistique');
        }

        $syncJobDispatcher->dispatch(SyncJobDispatcher::PRODUCT_STOCK);

        $syncLogService->info(
            'product_stock',
            'Synchronisation stock demandee',
            'Le message Messenger a ete envoye pour lancer la synchronisation des stocks produits.'
        );

        $notificationManager->notify(
            'Synchronisation stock lancee',
            'La demande de stocks produits a ete prise en compte par la file de traitement.',
            Notification::LEVEL_INFO,
            'flux_produits_logs'
        );

        $this->addFlash('success', 'Synchronisation stock produits lancee en arriere-plan.');

        return $this->redirectToRoute('flux_produits_mapping_logistique');
    }

    #[Route('/logs', name: 'logs')]
    public function logs(SyncLogRepository $syncLogRepository): Response
    {
        return $this->render('flux/produits/logs.html.twig', [
            'logs' => $syncLogRepository->findLatestByFluxKeys(['product', 'product_stock'], 150),
        ]);
    }
}
