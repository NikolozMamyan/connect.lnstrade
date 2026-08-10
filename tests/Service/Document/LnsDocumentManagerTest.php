<?php

namespace App\Tests\Service\Document;

use App\Entity\LnsDocument;
use App\Service\Document\LnsDocumentContentManager;
use App\Service\Document\LnsDocumentManager;
use App\Service\Document\LnsDocumentVersionConflict;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class LnsDocumentManagerTest extends TestCase
{
    public function testAutosaveStoresAnIncompleteDraft(): void
    {
        $document = new LnsDocument();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with($document);
        $entityManager->expects(self::once())->method('flush');
        $manager = new LnsDocumentManager(new LnsDocumentContentManager(), $entityManager);

        $manager->autosave($document, [
            'title' => '',
            'description' => '  Travail en cours  ',
            'autoGenerateToc' => true,
            'contentJson' => json_encode([[
                'title' => '',
                'description' => '',
                'blocks' => [],
            ]], \JSON_THROW_ON_ERROR),
            'revision' => 1,
        ], null);

        self::assertSame('', $document->getTitle());
        self::assertSame('Travail en cours', $document->getDescription());
        self::assertTrue($document->isDraft());
        self::assertSame('', $document->getContent()[0]['title']);
    }

    public function testManualSaveMarksACompleteDocumentAsValidated(): void
    {
        $document = (new LnsDocument())
            ->setTitle('Guide')
            ->setDescription('Description');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with($document);
        $entityManager->expects(self::once())->method('flush');
        $manager = new LnsDocumentManager(new LnsDocumentContentManager(), $entityManager);

        $manager->save($document, json_encode([[
            'title' => 'Introduction',
            'description' => '',
            'blocks' => [],
        ]], \JSON_THROW_ON_ERROR), null, 1);

        self::assertFalse($document->isDraft());
        self::assertSame('Introduction', $document->getContent()[0]['title']);
    }

    public function testAutosaveRejectsAStaleRevisionBeforeWriting(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');
        $manager = new LnsDocumentManager(new LnsDocumentContentManager(), $entityManager);

        $this->expectException(LnsDocumentVersionConflict::class);
        $this->expectExceptionMessage('Votre travail local est conservé');

        $manager->autosave(new LnsDocument(), [
            'title' => '',
            'description' => '',
            'autoGenerateToc' => true,
            'contentJson' => '[{"title":"","description":"","blocks":[]}]',
            'revision' => 2,
        ], null);
    }
}
