<?php

namespace App\Controller\Flux;

use App\Repository\HubspotCompanyRepository;
use App\Service\Flux\ClientFluxOrchestrator;
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
public function sync(ClientFluxOrchestrator $orchestrator): Response
{
    try {
        $result = $orchestrator->run();

        $this->addFlash('success', sprintf(
            'Synchronisation terminée : %d company(s), %d contact(s), %d relation(s), %d export(s) ERP préparé(s).',
            $result['savedCompanies'],
            $result['savedContacts'],
            $result['savedRelations'],
            $result['erpSent'],
        ));

        if (($result['erpSkipped'] ?? 0) > 0) {
            $this->addFlash('warning', sprintf(
                '%d export(s) ERP ignoré(s).',
                $result['erpSkipped'],
            ));
        }

        if (!empty($result['erpErrors'])) {
            $this->addFlash('error', sprintf(
                '%d erreur(s) pendant la préparation de l’export ERP.',
                count($result['erpErrors'])
            ));
        }
    } catch (\Throwable $e) {
        $this->addFlash('error', 'Erreur lors du traitement : ' . $e->getMessage());
    }

    return $this->redirectToRoute('flux_client_index');
}
}