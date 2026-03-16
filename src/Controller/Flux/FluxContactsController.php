<?php

namespace App\Controller\Flux;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/flux/contacts', name: 'flux_contacts_')]
class FluxContactsController extends AbstractController
{
    /**
     * Liste des contacts synchronisés HubSpot → Sage
     * Flux : Contact HubSpot => Client > Contact Sage
     * Sens : HubSpot → Sage (unilatéral)
     */
    #[Route('/', name: 'index')]
    public function index(): Response
    {
        return $this->render('flux/contacts/index.html.twig');
    }

    /**
     * Mapping détaillé des champs Contact
     */
    #[Route('/mapping', name: 'mapping')]
    public function mapping(): Response
    {
        return $this->render('flux/contacts/mapping.html.twig');
    }

    /**
     * Logs de synchronisation des contacts
     */
    #[Route('/logs', name: 'logs')]
    public function logs(): Response
    {
        return $this->render('flux/contacts/logs.html.twig');
    }
}
