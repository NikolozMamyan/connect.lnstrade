<?php

namespace App\Controller\Flux;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
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
    public function index(): Response
    {
        return $this->render('flux/produits/index.html.twig');
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
    public function mappingLogistique(): Response
    {
        return $this->render('flux/produits/mapping_logistique.html.twig');
    }

    /**
     * Logs de synchronisation produits
     */
    #[Route('/logs', name: 'logs')]
    public function logs(): Response
    {
        return $this->render('flux/produits/logs.html.twig');
    }
}
