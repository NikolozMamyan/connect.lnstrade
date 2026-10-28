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
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final class SampleShipmentController extends AbstractController
{
    public function __construct(
        private readonly RateLimiterFactory $sampleShipmentAuthLimiter,
        private readonly SampleShipmentAccessService $sampleAccessService,
        #[Autowire(service: 'app.internal_consumption_access')]
        private readonly SampleShipmentAccessService $consumptionAccessService,
    )
    {
    }

    #[Route('/echantillons', name: 'sample_shipment_index', methods: ['GET'])]
    #[Route('/consommation', name: 'internal_consumption_index', defaults: ['internalConsumption' => true], methods: ['GET'])]
    public function index(
        Request $request,
        CsrfTokenManagerInterface $csrfTokenManager,
        CommercialRepository $commercialRepository,
    ): Response
    {
        $granted = $this->accessService($request)->isGranted($request);
        $consumption = $request->attributes->getBoolean('internalConsumption');

        return $this->render('sample_shipment/index.html.twig', [
            'sampleAccessGranted' => $granted,
            'internalConsumption' => $consumption,
            'routePrefix' => $consumption ? 'internal_consumption_' : 'sample_shipment_',
            'representatives' => $granted && !$consumption ? $commercialRepository->findActiveSageRepresentatives() : [],
            'authCsrfToken' => $csrfTokenManager->getToken($this->csrfId($request, 'auth'))->getValue(),
            'submitCsrfToken' => $csrfTokenManager->getToken($this->csrfId($request, 'submit'))->getValue(),
        ]);
    }

    #[Route('/echantillons/authentifier', name: 'sample_shipment_authenticate', methods: ['POST'])]
    #[Route('/consommation/authentifier', name: 'internal_consumption_authenticate', defaults: ['internalConsumption' => true], methods: ['POST'])]
    public function authenticate(Request $request): JsonResponse
    {
        $accessService = $this->accessService($request);
        $payload = $this->jsonPayload($request);

        if (!$this->isCsrfTokenValid($this->csrfId($request, 'auth'), (string) ($payload['csrfToken'] ?? ''))) {
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

    #[Route('/echantillons/api/produits', name: 'sample_shipment_products', methods: ['GET'])]
    #[Route('/consommation/api/produits', name: 'internal_consumption_products', defaults: ['internalConsumption' => true], methods: ['GET'])]
    public function products(
        Request $request,
        ErpProductRepository $productRepository,
    ): JsonResponse {
        if (!$this->accessService($request)->isGranted($request)) {
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

    #[Route('/echantillons/api/envois', name: 'sample_shipment_submit', methods: ['POST'])]
    #[Route('/consommation/api/envois', name: 'internal_consumption_submit', defaults: ['internalConsumption' => true], methods: ['POST'])]
    public function submit(
        Request $request,
        SampleShipmentService $shipmentService,
    ): JsonResponse {
        if (!$this->accessService($request)->isGranted($request)) {
            return $this->errorResponse('Accès expiré. Saisissez de nouveau le code.', Response::HTTP_UNAUTHORIZED);
        }

        $payload = $this->jsonPayload($request);

        if (!$this->isCsrfTokenValid($this->csrfId($request, 'submit'), (string) ($payload['csrfToken'] ?? ''))) {
            return $this->errorResponse('La session a expiré. Rechargez la page.', Response::HTTP_FORBIDDEN);
        }

        unset($payload['csrfToken']);

        try {
            $result = $request->attributes->getBoolean('internalConsumption')
                ? $shipmentService->submitConsumption($payload)
                : $shipmentService->submit($payload);

            return new JsonResponse($result, Response::HTTP_CREATED);
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

    private function accessService(Request $request): SampleShipmentAccessService
    {
        return $request->attributes->getBoolean('internalConsumption') ? $this->consumptionAccessService : $this->sampleAccessService;
    }

    private function csrfId(Request $request, string $action): string
    {
        return ($request->attributes->getBoolean('internalConsumption') ? 'internal-consumption-' : 'sample-shipment-').$action;
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
