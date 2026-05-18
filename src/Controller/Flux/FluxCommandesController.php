<?php

namespace App\Controller\Flux;

use App\Repository\DealRepository;
use App\Repository\OrderFormRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/flux/commandes', name: 'flux_commandes_')]
class FluxCommandesController extends AbstractController
{
    #[Route('/', name: 'index')]
    public function index(DealRepository $dealRepository, OrderFormRepository $orderFormRepository): Response
    {
        $failedOrderForms = $orderFormRepository->findByStatus(OrderFormRepository::STATUS_FAILED);

        return $this->render('flux/commandes/index.html.twig', [
            'deals' => $dealRepository->findLatestWithLineItems(50),
            'dealCount' => $dealRepository->countAll(),
            'totalAmount' => $dealRepository->sumTotalAmount(),
            'failedCount' => count($failedOrderForms),
            'failedOrderForms' => array_slice($failedOrderForms, 0, 5),
        ]);
    }

    #[Route('/mapping/entete', name: 'mapping_entete')]
    public function mappingEntete(): Response
    {
        return $this->render('flux/commandes/mapping_entete.html.twig');
    }

    #[Route('/mapping/lignes', name: 'mapping_lignes')]
    public function mappingLignes(): Response
    {
        return $this->render('flux/commandes/mapping_lignes.html.twig');
    }

    #[Route('/logs', name: 'logs')]
    public function logs(): Response
    {
        return $this->render('flux/commandes/logs.html.twig');
    }
}
