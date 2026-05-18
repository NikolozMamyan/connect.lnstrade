<?php

namespace App\Tests\Entity;

use App\Entity\Commercial;
use App\Entity\OrderForm;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class OrderFormValidationTest extends KernelTestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->validator = static::getContainer()->get(ValidatorInterface::class);
    }

    public function testNouveauDealRequiresEnterpriseIdAndFile(): void
    {
        $orderForm = (new OrderForm())
            ->setDealType(OrderForm::DEAL_TYPE_NOUVEAU)
            ->setCommercial($this->createCommercial())
            ->setEnterpriseId(null)
            ->setUploadedFile(null);

        $violations = $this->validator->validate($orderForm, null, ['Default', 'NouveauDeal']);
        $messagesByField = $this->collectMessagesByField($violations);

        self::assertArrayHasKey('enterpriseId', $messagesByField);
        self::assertArrayHasKey('uploadedFile', $messagesByField);
    }

    public function testExistantDealRequiresNumericDealIdAndFile(): void
    {
        $orderForm = (new OrderForm())
            ->setDealType(OrderForm::DEAL_TYPE_EXISTANT)
            ->setCommercial($this->createCommercial())
            ->setDealId('ABC123')
            ->setUploadedFile(null);

        $violations = $this->validator->validate($orderForm, null, ['Default', 'ExistantDeal']);
        $messagesByField = $this->collectMessagesByField($violations);

        self::assertArrayHasKey('dealId', $messagesByField);
        self::assertArrayHasKey('uploadedFile', $messagesByField);
    }

    public function testUtilityMethodsReturnExpectedValues(): void
    {
        $commercial = $this->createCommercial();

        self::assertSame('Alice Martin', $commercial->getFullName());
        self::assertSame('NouveauDeal', OrderForm::resolveValidationGroup(OrderForm::DEAL_TYPE_NOUVEAU));
        self::assertSame('ExistantDeal', OrderForm::resolveValidationGroup(OrderForm::DEAL_TYPE_EXISTANT));
        self::assertSame('Default', OrderForm::resolveValidationGroup('unknown'));
    }

    private function createCommercial(): Commercial
    {
        return (new Commercial())
            ->setFirstName('Alice')
            ->setLastName('Martin')
            ->setEmail('alice@example.test');
    }

    /**
     * @param iterable<\Symfony\Component\Validator\ConstraintViolationInterface> $violations
     *
     * @return array<string, list<string>>
     */
    private function collectMessagesByField(iterable $violations): array
    {
        $messages = [];

        foreach ($violations as $violation) {
            $messages[(string) $violation->getPropertyPath()][] = (string) $violation->getMessage();
        }

        return $messages;
    }
}
