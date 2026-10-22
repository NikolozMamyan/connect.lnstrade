<?php

namespace App\Controller;

use App\Entity\ErpProduct;
use App\Repository\ErpProductRepository;
use App\Repository\CommercialRepository;
use App\Service\SampleShipment\SampleShipmentAccessService;
use App\Service\SampleShipment\SampleShipmentBusyException;
use App\Service\SampleShipment\SampleShipmentService;
use App\Service\SampleShipment\SampleShipmentValidationException;
use App\Service\SampleShipment\SampleShipmentNotificationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

#[Route('/echantillons', name: 'sample_shipment_')]
final class SampleShipmentController extends AbstractController
{
    public function __construct(private readonly RateLimiterFactory $sampleShipmentAuthLimiter)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        Request $request,
        SampleShipmentAccessService $accessService,
        CsrfTokenManagerInterface $csrfTokenManager,
        CommercialRepository $commercialRepository,
    ): Response
    {
        $granted = $accessService->isGranted($request);

        return $this->render('sample_shipment/index.html.twig', [
            'sampleAccessGranted' => $granted,
            'representatives' => $granted ? $commercialRepository->findActiveSageRepresentatives() : [],
            'authCsrfToken' => $csrfTokenManager->getToken('sample-shipment-auth')->getValue(),
            'submitCsrfToken' => $csrfTokenManager->getToken('sample-shipment-submit')->getValue(),
        ]);
    }

    #[Route('/authentifier', name: 'authenticate', methods: ['POST'])]
    public function authenticate(Request $request, SampleShipmentAccessService $accessService): JsonResponse
    {
        $payload = $this->jsonPayload($request);

        if (!$this->isCsrfTokenValid('sample-shipment-auth', (string) ($payload['csrfToken'] ?? ''))) {
            return $this->errorResponse('La session a expiré. Rechargez la page.', Response::HTTP_FORBIDDEN);
        }

        if (!$accessService->verifyPin((string) ($payload['pin'] ?? ''))) {
            $limit = $this->sampleShipmentAuthLimiter
                ->create($request->getClientIp() ?? 'unknown')
                ->consume();

            if (!$limit->isAccepted()) {
                return $this->errorResponse(
                    'Trop de tentatives. Réessayez dans 15 minutes.',
                    Response::HTTP_TOO_MANY_REQUESTS
                );
            }

            return $this->errorResponse('Le code d’accès est incorrect.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $response = new JsonResponse(['success' => true]);
        $response->headers->setCookie($accessService->createCookie($request));
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[Route('/api/produits', name: 'products', methods: ['GET'])]
    public function products(
        Request $request,
        SampleShipmentAccessService $accessService,
        ErpProductRepository $productRepository,
    ): JsonResponse {
        if (!$accessService->isGranted($request)) {
            return $this->errorResponse('Accès expiré. Saisissez de nouveau le code.', Response::HTTP_UNAUTHORIZED);
        }

        $query = trim((string) $request->query->get('query', ''));
        $mode = (string) $request->query->get('mode', 'search');

        if ($query === '' || mb_strlen($query) > 100) {
            return $this->errorResponse('Saisissez un code-barres ou une référence valide.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($mode === 'barcode') {
            $product = $productRepository->findOneByBarcode($query);

            if (!$product instanceof ErpProduct) {
                return $this->errorResponse('Aucun produit ne correspond à ce code-barres.', Response::HTTP_NOT_FOUND);
            }

            return new JsonResponse(['success' => true, 'product' => $this->normalizeProduct($product)]);
        }

        if (mb_strlen($query) < 2) {
            return $this->errorResponse('Saisissez au moins deux caractères.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse([
            'success' => true,
            'products' => array_map($this->normalizeProduct(...), $productRepository->searchForSamples($query)),
        ]);
    }

    #[Route('/api/envois', name: 'submit', methods: ['POST'])]
    public function submit(
        Request $request,
        SampleShipmentAccessService $accessService,
        SampleShipmentService $shipmentService,
    ): JsonResponse {
        if (!$accessService->isGranted($request)) {
            return $this->errorResponse('Accès expiré. Saisissez de nouveau le code.', Response::HTTP_UNAUTHORIZED);
        }

        $payload = $this->jsonPayload($request);

        if (!$this->isCsrfTokenValid('sample-shipment-submit', (string) ($payload['csrfToken'] ?? ''))) {
            return $this->errorResponse('La session a expiré. Rechargez la page.', Response::HTTP_FORBIDDEN);
        }

        unset($payload['csrfToken']);

        try {
            return new JsonResponse($shipmentService->submit($payload), Response::HTTP_CREATED);
        } catch (SampleShipmentValidationException $exception) {
            return new JsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
                'errors' => $exception->getErrors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (SampleShipmentBusyException $exception) {
            return $this->errorResponse($exception->getMessage(), Response::HTTP_CONFLICT);
        } catch (SampleShipmentNotificationException $exception) {
            return $this->errorResponse($exception->getMessage(), Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (\Throwable) {
            return $this->errorResponse(
                'L’envoi vers Sage a échoué. La demande a été conservée et peut être relancée.',
                Response::HTTP_BAD_GATEWAY
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonPayload(Request $request): array
    {
        $payload = json_decode($request->getContent(), true);

        return is_array($payload) ? $payload : [];
    }

    /**
     * @return array{reference: string, designation: string, barcode: ?string}
     */
    private function normalizeProduct(ErpProduct $product): array
    {
        $barcode = trim((string) $product->getCodeBarre());

        return [
            'reference' => (string) $product->getReference(),
            'designation' => trim((string) $product->getDesignation()),
            'barcode' => $barcode !== '' ? $barcode : null,
        ];
    }

    private function errorResponse(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['success' => false, 'message' => $message], $status);
    }
}
