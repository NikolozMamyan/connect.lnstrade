<?php

namespace App\Controller\Flux;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/flux/commandes', name: 'flux_commandes_')]
class FluxCommandesController extends AbstractController
{
    /**
     * Liste des commandes/deals synchronisés
     * Flux : Deal/Orders HubSpot => Bon de commande Sage
     * Sens : Sage → HubSpot (lignes de commande, statuts, paiements)
     */
    #[Route('/', name: 'index')]
    public function index(): Response
    {
        return $this->render('flux/commandes/index.html.twig');
    }

    /**
     * Mapping : En-tête de commande (conditions livraison, règlement)
     */
    #[Route('/mapping/entete', name: 'mapping_entete')]
    public function mappingEntete(): Response
    {
        return $this->render('flux/commandes/mapping_entete.html.twig');
    }

    /**
     * Mapping : Lignes de commande (articles, quantités, prix)
     */
    #[Route('/mapping/lignes', name: 'mapping_lignes')]
    public function mappingLignes(): Response
    {
        return $this->render('flux/commandes/mapping_lignes.html.twig');
    }

    /**
     * Logs de synchronisation commandes
     */
    #[Route('/logs', name: 'logs')]
    public function logs(): Response
    {
        return $this->render('flux/commandes/logs.html.twig');
    }
}
