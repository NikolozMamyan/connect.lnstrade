<?php

namespace App\Controller;

use App\Entity\OrderForm;
use App\Repository\CommercialRepository;
use App\Service\OrderForm\OrderFormSubmissionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class OrderFormController extends AbstractController
{
    #[Route('/order-form/submit', name: 'order_form_submit', methods: ['GET', 'POST'])]
    public function submit(
        Request $request,
        CommercialRepository $commercialRepository,
        ValidatorInterface $validator,
        OrderFormSubmissionService $orderFormSubmissionService,
    ): Response {
        if ($request->isMethod('GET')) {
            return $this->render('order_form/submit.html.twig', [
                'commerciaux' => $commercialRepository->findActiveOrdered(),
            ]);
        }

        $orderForm = new OrderForm();
        $orderForm->setDealType((string) $request->request->get('dealType', ''));
        $orderForm->setDealId($request->request->get('dealId'));
        $orderForm->setEnterpriseId($request->request->get('enterpriseId'));
        $orderForm->setUploadedFile($request->files->get('orderFile'));

        $commercialId = $request->request->get('commercialId');

        if ($commercialId !== null && $commercialId !== '') {
            $commercial = $commercialRepository->find((int) $commercialId);
            $orderForm->setCommercial($commercial);
        }

        $validationGroup = OrderForm::resolveValidationGroup($orderForm->getDealType());
        $violations = $validator->validate($orderForm, null, ['Default', $validationGroup]);

        if (count($violations) > 0) {
            return new JsonResponse([
                'success' => false,
                'errors' => $this->normalizeViolations($violations),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $uploadedFile = $request->files->get('orderFile');

        if (!$uploadedFile instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            return new JsonResponse([
                'success' => false,
                'errors' => [['field' => 'orderFile', 'message' => 'Le fichier est obligatoire.']],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $submissionResult = $orderFormSubmissionService->submit($orderForm, $uploadedFile);

        if (($submissionResult['success'] ?? false) !== true) {
            return new JsonResponse([
                'success' => false,
                'errors' => $submissionResult['errors'] ?? [],
                'reference' => $orderForm->getReferenceNumber(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash('success', sprintf('Order form %s soumis avec succes.', $orderForm->getReferenceNumber()));

        return new JsonResponse([
            'success' => true,
            'reference' => $orderForm->getReferenceNumber(),
        ]);
    }

    /**
     * @param iterable<int, ConstraintViolationInterface> $violations
     *
     * @return array<int, array{field: string, message: string}>
     */
    private function normalizeViolations(iterable $violations): array
    {
        $errors = [];

        foreach ($violations as $violation) {
            $errors[] = [
                'field' => (string) $violation->getPropertyPath(),
                'message' => (string) $violation->getMessage(),
            ];
        }

        return $errors;
    }
}
