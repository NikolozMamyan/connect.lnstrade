<?php

namespace App\Controller\Flux;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/flux/clients', name: 'flux_clients_')]
class FluxClientsController extends AbstractController
{
    /**
     * Liste des clients/sociétés synchronisés
     * Flux : Company HubSpot <=> Client Sage (bidirectionnel)
     * Sens : Sage → HubSpot pour création, HubSpot → Sage pour modifications
     */
    #[Route('/', name: 'index')]
    public function index(): Response
    {
        return $this->render('flux/clients/index.html.twig');
    }

    /**
     * Mapping : Identification client
     */
    #[Route('/mapping/identification', name: 'mapping_identification')]
    public function mappingIdentification(): Response
    {
        return $this->render('flux/clients/mapping_identification.html.twig');
    }

    /**
     * Mapping : Adresses (facturation + livraison)
     */
    #[Route('/mapping/adresses', name: 'mapping_adresses')]
    public function mappingAdresses(): Response
    {
        return $this->render('flux/clients/mapping_adresses.html.twig');
    }

    /**
     * Mapping : Tarifs & conditions
     */
    #[Route('/mapping/tarifs', name: 'mapping_tarifs')]
    public function mappingTarifs(): Response
    {
        return $this->render('flux/clients/mapping_tarifs.html.twig');
    }

    /**
     * Mapping : Solvabilité & encours
     */
    #[Route('/mapping/solvabilite', name: 'mapping_solvabilite')]
    public function mappingSolvabilite(): Response
    {
        return $this->render('flux/clients/mapping_solvabilite.html.twig');
    }

    /**
     * Logs de synchronisation clients
     */
    #[Route('/logs', name: 'logs')]
    public function logs(): Response
    {
        return $this->render('flux/clients/logs.html.twig');
    }
}
