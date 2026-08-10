<?php

namespace App\Tests\Form;

use App\Entity\LnsDocument;
use App\Form\LnsDocumentType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;

class LnsDocumentTypeTest extends KernelTestCase
{
    public function testEmptyTitleProducesAValidationErrorInsteadOfAMappingError(): void
    {
        self::bootKernel();
        $formFactory = self::getContainer()->get(FormFactoryInterface::class);
        $document = new LnsDocument();
        $form = $formFactory->create(LnsDocumentType::class, $document, [
            'content_json' => '[{"title":"Page","description":"","blocks":[]}]',
            'revision' => 1,
            'csrf_protection' => false,
        ]);

        $form->submit([
            'title' => '',
            'description' => 'Description',
            'autoGenerateToc' => '1',
            'contentJson' => '[{"title":"Page","description":"","blocks":[]}]',
            'revision' => '1',
        ]);

        self::assertTrue($form->isSubmitted());
        self::assertFalse($form->isValid());
        self::assertSame('', $document->getTitle());
        self::assertSame(
            'Le titre du document est obligatoire.',
            (string) $form->get('title')->getErrors(true)[0]->getMessage(),
        );
    }

    public function testEmptyDescriptionProducesAValidationErrorInsteadOfAMappingError(): void
    {
        self::bootKernel();
        $formFactory = self::getContainer()->get(FormFactoryInterface::class);
        $document = new LnsDocument();
        $form = $formFactory->create(LnsDocumentType::class, $document, [
            'content_json' => '[{"title":"Page","description":"","blocks":[]}]',
            'revision' => 1,
            'csrf_protection' => false,
        ]);

        $form->submit([
            'title' => 'Guide LNS',
            'description' => '',
            'autoGenerateToc' => '1',
            'contentJson' => '[{"title":"Page","description":"","blocks":[]}]',
            'revision' => '1',
        ]);

        self::assertTrue($form->isSubmitted());
        self::assertFalse($form->isValid());
        self::assertSame('', $document->getDescription());
        self::assertSame(
            'La description du document est obligatoire.',
            (string) $form->get('description')->getErrors(true)[0]->getMessage(),
        );
    }
}
