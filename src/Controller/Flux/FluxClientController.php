<?php

namespace App\Controller\Flux;

use App\Entity\Notification;
use App\Repository\HubspotCompanyRepository;
use App\Repository\SyncLogRepository;
use App\Service\Flux\SyncJobDispatcher;
use App\Service\Log\SyncLogService;
use App\Service\Ui\NotificationManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/flux/client', name: 'flux_client_')]
class FluxClientController extends AbstractController
{
    #[Route('/', name: 'index')]
    public function index(Request $request, HubspotCompanyRepository $hubspotCompanyRepository): Response
    {
        $query = trim((string) $request->query->get('q', ''));

        return $this->render('flux/client/index.html.twig', [
            'companies' => $query !== '' ? $hubspotCompanyRepository->searchByTerm($query) : $hubspotCompanyRepository->findAllWithContacts(),
            'searchQuery' => $query,
        ]);
    }

    #[Route('/logs', name: 'logs')]
    public function logs(SyncLogRepository $syncLogRepository): Response
    {
        return $this->render('flux/client/logs.html.twig', [
            'logs' => $syncLogRepository->findLatestByFluxKeys(['client'], 150),
        ]);
    }

    #[Route('/sync', name: 'sync', methods: ['POST'])]
    public function sync(
        Request $request,
        SyncJobDispatcher $syncJobDispatcher,
        SyncLogService $syncLogService,
        NotificationManager $notificationManager,
    ): Response {
        if (!$this->isCsrfTokenValid('flux_client_sync', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');

            return $this->redirectToRoute('flux_client_index');
        }

        $syncJobDispatcher->dispatch(SyncJobDispatcher::CLIENT);

        $syncLogService->info(
            'client',
            'Synchronisation client demandee',
            'Le message Messenger a ete envoye pour lancer la synchronisation client.'
        );

        $notificationManager->notify(
            'Synchronisation clients lancee',
            'Le flux clients a ete ajoute a la file de traitement.',
            Notification::LEVEL_INFO,
            'flux_client_logs'
        );

        $this->addFlash('success', 'Synchronisation lancee en arriere-plan.');

        return $this->redirectToRoute('flux_client_index');
    }
}
