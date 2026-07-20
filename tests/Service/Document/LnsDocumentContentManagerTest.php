<?php

namespace App\Tests\Service\Document;

use App\Service\Document\LnsDocumentContentManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LnsDocumentContentManagerTest extends TestCase
{
    private const PIXEL_PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6GQAAAABJRU5ErkJggg==';

    private LnsDocumentContentManager $manager;

    protected function setUp(): void
    {
        $this->manager = new LnsDocumentContentManager();
    }

    public function testDefaultContentContainsOneEditablePage(): void
    {
        self::assertSame([[
            'title' => '',
            'description' => '',
            'blocks' => [],
        ]], $this->manager->defaultContent());
    }

    public function testNormalizeKeepsOnlySupportedOrderedContent(): void
    {
        $payload = json_encode([[
            'title' => '  Introduction  ',
            'description' => " Première ligne\r\nDeuxième ligne ",
            'ignored' => 'value',
            'blocks' => [
                [
                    'type' => 'text',
                    'title' => ' Contexte ',
                    'description' => ' Description du contexte ',
                ],
                [
                    'type' => 'table',
                    'title' => ' Synthèse ',
                    'headers' => [' Élément ', ' Détail ', ' Statut '],
                    'rows' => [
                        [' Projet ', ' Description ', ' En cours '],
                    ],
                ],
            ],
        ]], \JSON_THROW_ON_ERROR);

        self::assertSame([[
            'title' => 'Introduction',
            'description' => "Première ligne\nDeuxième ligne",
            'blocks' => [
                [
                    'type' => 'text',
                    'title' => 'Contexte',
                    'description' => 'Description du contexte',
                ],
                [
                    'type' => 'table',
                    'title' => 'Synthèse',
                    'headers' => ['Élément', 'Détail', 'Statut'],
                    'rows' => [
                        ['Projet', 'Description', 'En cours'],
                    ],
                ],
            ],
        ]], $this->manager->normalize($payload));
    }

    #[DataProvider('invalidPayloadProvider')]
    public function testNormalizeRejectsInvalidContent(string $payload, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->manager->normalize($payload);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidPayloadProvider(): iterable
    {
        yield 'invalid JSON' => ['{', 'Le contenu de l’éditeur est invalide.'];
        yield 'no page' => ['[]', 'Le document doit contenir au moins une page.'];
        yield 'missing page title' => [json_encode([[
            'title' => '',
            'description' => '',
            'blocks' => [],
        ]], \JSON_THROW_ON_ERROR), 'Le titre de la page 1 est obligatoire.'];
        yield 'unsupported block' => [json_encode([[
            'title' => 'Page',
            'description' => '',
            'blocks' => [['type' => 'video']],
        ]], \JSON_THROW_ON_ERROR), 'Le type du bloc 1 de la page 1 n’est pas autorisé.'];
        yield 'wrong table columns' => [json_encode([[
            'title' => 'Page',
            'description' => '',
            'blocks' => [[
                'type' => 'table',
                'title' => 'Tableau',
                'headers' => ['Une seule colonne'],
                'rows' => [],
            ]],
        ]], \JSON_THROW_ON_ERROR), 'doit contenir trois colonnes'];
    }

    public function testNormalizeRejectsTooManyPages(): void
    {
        $pages = array_fill(0, LnsDocumentContentManager::MAX_PAGES + 1, [
            'title' => 'Page',
            'description' => '',
            'blocks' => [],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ne peut pas dépasser 50 pages');

        $this->manager->normalize(json_encode($pages, \JSON_THROW_ON_ERROR));
    }

    public function testNormalizeAcceptsAValidatedImageBlock(): void
    {
        $payload = json_encode([[
            'title' => 'Galerie',
            'description' => '',
            'blocks' => [[
                'type' => 'image',
                'title' => ' Produit ',
                'data' => self::PIXEL_PNG,
                'caption' => ' Vue principale ',
            ]],
        ]], \JSON_THROW_ON_ERROR);

        $image = $this->manager->normalize($payload)[0]['blocks'][0];

        self::assertSame('image', $image['type']);
        self::assertSame('Produit', $image['title']);
        self::assertSame(self::PIXEL_PNG, $image['data']);
        self::assertSame('Vue principale', $image['caption']);
    }

    public function testNormalizeRejectsAnUnsafeImageType(): void
    {
        $payload = json_encode([[
            'title' => 'Galerie',
            'description' => '',
            'blocks' => [[
                'type' => 'image',
                'title' => 'Illustration',
                'data' => 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
                'caption' => '',
            ]],
        ]], \JSON_THROW_ON_ERROR);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('doit être une image JPEG ou PNG');

        $this->manager->normalize($payload);
    }
}
