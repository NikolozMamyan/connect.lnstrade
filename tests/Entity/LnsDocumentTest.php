<?php

namespace App\Tests\Entity;

use App\Entity\LnsDocument;
use PHPUnit\Framework\TestCase;

class LnsDocumentTest extends TestCase
{
    public function testDocumentStoresMetadataAndCountsPages(): void
    {
        $document = (new LnsDocument())
            ->setTitle('  Guide LNS  ')
            ->setDescription('  Description du guide  ')
            ->setAutoGenerateToc(false)
            ->setContent([
                ['title' => 'Page 1', 'description' => '', 'blocks' => []],
                ['title' => 'Page 2', 'description' => '', 'blocks' => []],
            ]);

        self::assertSame('Guide LNS', $document->getTitle());
        self::assertSame('Description du guide', $document->getDescription());
        self::assertFalse($document->isAutoGenerateToc());
        self::assertSame(2, $document->getPageCount());
        self::assertTrue($document->isDraft());
        self::assertSame(1, $document->getEditVersion());
        self::assertInstanceOf(\DateTimeImmutable::class, $document->getCreatedAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $document->getUpdatedAt());
    }

    public function testDisplayTitleFallsBackForAnUntitledDraft(): void
    {
        $document = (new LnsDocument())->setTitle('');

        self::assertSame('Document sans titre', $document->getDisplayTitle());
    }
}
