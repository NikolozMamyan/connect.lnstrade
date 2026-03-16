<?php

namespace App\Controller;

use App\Service\Connector\ConnectorFluxManager;
use App\Service\HubSpot\HubSpotClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{

    public function __construct(
        private readonly HubSpotClient $hubSpotClient,
    ) {
    }

    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(ConnectorFluxManager $connectorFluxManager): Response
    {
        $flux = $connectorFluxManager->create(
            'Produits Sage vers HubSpot',
            '2xsfrezgeregth',
            'sage',
            'hubspot',
            'article',
            'product',
            true,
            'Flux de synchronisation des produits Sage vers HubSpot'
            );

        return $this->render('dashboard/index.html.twig', [
            'controller_name' => 'DashboardController',
        ]);
    }
}
