<?php

namespace App\Controller\Flux;

use App\Repository\HubspotCompanyRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Process;
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
    public function sync(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('flux_client_sync', $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('flux_client_index');
        }

        $projectDir = $this->getParameter('kernel.project_dir');
        $phpBinary = PHP_BINARY;

        $process = new Process([
            $phpBinary,
            'bin/console',
            'app:sync:client-flux',
        ]);

        $process->setWorkingDirectory($projectDir);
        $process->disableOutput();
        $process->start();

        $this->addFlash('success', 'Un worker est lancée en arrière-plan.');

        return $this->redirectToRoute('flux_client_index');
    }
}