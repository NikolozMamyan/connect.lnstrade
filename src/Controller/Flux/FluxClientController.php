<?php

namespace App\Controller\Flux;

use App\Repository\HubspotCompanyRepository;
use Symfony\Component\Messenger\MessageBusInterface;
use App\Message\SyncClientMessage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/flux/client', name: 'flux_client_')]
class FluxClientController extends AbstractController
{
    #[Route('/', name: 'index')]
    public function index(HubspotCompanyRepository $hubspotCompanyRepository): Response
    {
        $companies = $hubspotCompanyRepository->findAllWithContacts();

        return $this->render('flux/client/index.html.twig', [
            'companies' => $companies,
        ]);
    }


#[Route('/sync', name: 'sync', methods: ['POST'])]
public function sync(MessageBusInterface $bus): Response
{
    $bus->dispatch(new SyncClientMessage());

    $this->addFlash('success', 'Synchronisation lancée en arrière-plan.');

    return $this->redirectToRoute('flux_client_index');
}
}