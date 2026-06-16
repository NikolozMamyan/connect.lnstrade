<?php

namespace App\Tests\Controller;

use App\Controller\OrderFormController;
use App\Repository\CommercialRepository;
use App\Service\OrderForm\OrderFormSubmissionService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class OrderFormControllerTest extends TestCase
{
    public function testSubmitRejectsInvalidPasswordBeforeProcessing(): void
    {
        $controller = new OrderFormController();
        $request = Request::create('/order-form/submit', 'POST', [
            'orderFormPassword' => 'wrong',
        ]);

        $commercialRepository = $this->createStub(CommercialRepository::class);
        $validator = $this->createMock(ValidatorInterface::class);
        $submissionService = $this->createMock(OrderFormSubmissionService::class);

        $validator->expects(self::never())->method('validate');
        $submissionService->expects(self::never())->method('submit');

        $response = $controller->submit($request, $commercialRepository, $validator, $submissionService);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());

        $payload = json_decode($response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertFalse($payload['success']);
        self::assertSame('orderFormPassword', $payload['errors'][0]['field']);
        self::assertSame('Mot de passe invalide.', $payload['errors'][0]['message']);
    }
}
