<?php

namespace App\Controller\Flux;

use App\Message\SyncClientMessage;
use App\Repository\HubspotCompanyRepository;
use App\Repository\SyncLogRepository;
use App\Service\Log\SyncLogService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/flux/client', name: 'flux_client_')]
class FluxClientController extends AbstractController
{
    #[Route('/', name: 'index')]
    public function index(HubspotCompanyRepository $hubspotCompanyRepository): Response
    {
        return $this->render('flux/client/index.html.twig', [
            'companies' => $hubspotCompanyRepository->findAllWithContacts(),
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
    public function sync(MessageBusInterface $bus, SyncLogService $syncLogService): Response
    {
        $bus->dispatch(new SyncClientMessage());

        $syncLogService->info(
            'client',
            'Synchronisation client demandee',
            'Le message Messenger a ete envoye pour lancer la synchronisation client.'
        );

        $this->addFlash('success', 'Synchronisation lancée en arrière-plan.');

        return $this->redirectToRoute('flux_client_index');
    }
}
