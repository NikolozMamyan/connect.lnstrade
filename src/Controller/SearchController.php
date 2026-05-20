<?php

namespace App\Controller;

use App\Service\Ui\GlobalSearchService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class SearchController extends AbstractController
{
    #[Route('/search/global', name: 'app_global_search', methods: ['GET'])]
    public function global(Request $request, GlobalSearchService $globalSearchService): JsonResponse
    {
        $query = trim((string) $request->query->get('q', ''));

        return $this->json([
            'results' => $globalSearchService->search($query, 10),
        ]);
    }
}
