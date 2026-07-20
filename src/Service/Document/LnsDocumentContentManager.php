<?php

namespace App\Service\Document;

final class LnsDocumentContentManager
{
    public const MAX_PAGES = 50;
    public const MAX_BLOCKS_PER_PAGE = 30;
    public const MAX_TABLE_ROWS = 100;
    public const MAX_IMAGE_BYTES = 1_200_000;

    private const MAX_PAYLOAD_BYTES = 7_000_000;
    private const MAX_IMAGE_DIMENSION = 4000;
    private const MAX_IMAGE_PIXELS = 16_000_000;
    private const MAX_TITLE_LENGTH = 180;
    private const MAX_DESCRIPTION_LENGTH = 50000;
    private const MAX_HEADER_LENGTH = 80;
    private const MAX_CELL_LENGTH = 5000;

    /**
     * @return list<array<string, mixed>>
     */
    public function defaultContent(): array
    {
        return [[
            'title' => '',
            'description' => '',
            'blocks' => [],
        ]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function normalize(string $payload): array
    {
        if (strlen($payload) > self::MAX_PAYLOAD_BYTES) {
            throw new \InvalidArgumentException('Le contenu du document est trop volumineux.');
        }

        try {
            $decoded = json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('Le contenu de l’éditeur est invalide. Rechargez la page puis réessayez.');
        }

        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new \InvalidArgumentException('La liste des pages est invalide.');
        }

        if ($decoded === []) {
            throw new \InvalidArgumentException('Le document doit contenir au moins une page.');
        }

        if (count($decoded) > self::MAX_PAGES) {
            throw new \InvalidArgumentException(sprintf('Le document ne peut pas dépasser %d pages.', self::MAX_PAGES));
        }

        $pages = [];

        foreach ($decoded as $pageIndex => $page) {
            if (!is_array($page)) {
                throw new \InvalidArgumentException(sprintf('La page %d est invalide.', $pageIndex + 1));
            }

            $pageNumber = $pageIndex + 1;
            $title = $this->requiredString($page['title'] ?? null, self::MAX_TITLE_LENGTH, sprintf('Le titre de la page %d', $pageNumber));
            $description = $this->optionalString($page['description'] ?? '', self::MAX_DESCRIPTION_LENGTH, sprintf('La description de la page %d', $pageNumber));
            $rawBlocks = $page['blocks'] ?? [];

            if (!is_array($rawBlocks) || !array_is_list($rawBlocks)) {
                throw new \InvalidArgumentException(sprintf('Les blocs de la page %d sont invalides.', $pageNumber));
            }

            if (count($rawBlocks) > self::MAX_BLOCKS_PER_PAGE) {
                throw new \InvalidArgumentException(sprintf('La page %d ne peut pas dépasser %d blocs.', $pageNumber, self::MAX_BLOCKS_PER_PAGE));
            }

            $blocks = [];

            foreach ($rawBlocks as $blockIndex => $block) {
                $blocks[] = $this->normalizeBlock($block, $pageNumber, $blockIndex + 1);
            }

            $pages[] = [
                'title' => $title,
                'description' => $description,
                'blocks' => $blocks,
            ];
        }

        return $pages;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeBlock(mixed $block, int $pageNumber, int $blockNumber): array
    {
        if (!is_array($block)) {
            throw new \InvalidArgumentException(sprintf('Le bloc %d de la page %d est invalide.', $blockNumber, $pageNumber));
        }

        $type = $block['type'] ?? null;

        if ($type === 'text') {
            return [
                'type' => 'text',
                'title' => $this->requiredString($block['title'] ?? null, self::MAX_TITLE_LENGTH, sprintf('Le titre du bloc %d de la page %d', $blockNumber, $pageNumber)),
                'description' => $this->optionalString($block['description'] ?? '', self::MAX_DESCRIPTION_LENGTH, sprintf('La description du bloc %d de la page %d', $blockNumber, $pageNumber)),
            ];
        }

        if ($type === 'image') {
            return [
                'type' => 'image',
                'title' => $this->requiredString($block['title'] ?? null, self::MAX_TITLE_LENGTH, sprintf('Le titre de l’image %d de la page %d', $blockNumber, $pageNumber)),
                'data' => $this->normalizeImageData($block['data'] ?? null, $blockNumber, $pageNumber),
                'caption' => $this->optionalString($block['caption'] ?? '', self::MAX_CELL_LENGTH, sprintf('La légende de l’image %d de la page %d', $blockNumber, $pageNumber)),
            ];
        }

        if ($type !== 'table') {
            throw new \InvalidArgumentException(sprintf('Le type du bloc %d de la page %d n’est pas autorisé.', $blockNumber, $pageNumber));
        }

        $headers = $block['headers'] ?? null;
        $rows = $block['rows'] ?? null;

        if (!is_array($headers) || !array_is_list($headers) || count($headers) !== 3) {
            throw new \InvalidArgumentException(sprintf('Le tableau %d de la page %d doit contenir trois colonnes.', $blockNumber, $pageNumber));
        }

        if (!is_array($rows) || !array_is_list($rows)) {
            throw new \InvalidArgumentException(sprintf('Les lignes du tableau %d de la page %d sont invalides.', $blockNumber, $pageNumber));
        }

        if (count($rows) > self::MAX_TABLE_ROWS) {
            throw new \InvalidArgumentException(sprintf('Un tableau ne peut pas dépasser %d lignes.', self::MAX_TABLE_ROWS));
        }

        $normalizedHeaders = [];

        foreach ($headers as $headerIndex => $header) {
            $normalizedHeaders[] = $this->requiredString($header, self::MAX_HEADER_LENGTH, sprintf('L’en-tête %d du tableau %d', $headerIndex + 1, $blockNumber));
        }

        $normalizedRows = [];

        foreach ($rows as $rowIndex => $row) {
            if (!is_array($row) || !array_is_list($row) || count($row) !== 3) {
                throw new \InvalidArgumentException(sprintf('La ligne %d du tableau %d de la page %d est invalide.', $rowIndex + 1, $blockNumber, $pageNumber));
            }

            $normalizedRows[] = array_map(
                fn (mixed $cell): string => $this->optionalString($cell, self::MAX_CELL_LENGTH, sprintf('Une cellule du tableau %d', $blockNumber)),
                $row,
            );
        }

        return [
            'type' => 'table',
            'title' => $this->requiredString($block['title'] ?? null, self::MAX_TITLE_LENGTH, sprintf('Le titre du tableau %d de la page %d', $blockNumber, $pageNumber)),
            'headers' => $normalizedHeaders,
            'rows' => $normalizedRows,
        ];
    }

    private function requiredString(mixed $value, int $maxLength, string $label): string
    {
        $normalized = $this->optionalString($value, $maxLength, $label);

        if ($normalized === '') {
            throw new \InvalidArgumentException($label . ' est obligatoire.');
        }

        return $normalized;
    }

    private function optionalString(mixed $value, int $maxLength, string $label): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException($label . ' est invalide.');
        }

        $normalized = trim(str_replace(["\r\n", "\r"], "\n", $value));

        if (mb_strlen($normalized) > $maxLength) {
            throw new \InvalidArgumentException(sprintf('%s ne peut pas dépasser %d caractères.', $label, $maxLength));
        }

        return $normalized;
    }

    private function normalizeImageData(mixed $value, int $blockNumber, int $pageNumber): string
    {
        $label = sprintf('L’image %d de la page %d', $blockNumber, $pageNumber);

        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException($label . ' est obligatoire.');
        }

        $separatorPosition = strpos($value, ',');

        if ($separatorPosition === false) {
            throw new \InvalidArgumentException($label . ' est invalide.');
        }

        $metadata = substr($value, 0, $separatorPosition);
        $encodedData = substr($value, $separatorPosition + 1);

        if (!preg_match('/^data:(image\/(?:jpeg|png));base64$/D', $metadata, $matches)) {
            throw new \InvalidArgumentException($label . ' doit être une image JPEG ou PNG.');
        }

        $binary = base64_decode($encodedData, true);

        if ($binary === false || $binary === '') {
            throw new \InvalidArgumentException($label . ' est invalide.');
        }

        if (strlen($binary) > self::MAX_IMAGE_BYTES) {
            throw new \InvalidArgumentException(sprintf('%s dépasse la taille maximale de %.1f Mo.', $label, self::MAX_IMAGE_BYTES / 1_000_000));
        }

        $imageInfo = @getimagesizefromstring($binary);
        $expectedMime = $matches[1];

        if ($imageInfo === false || ($imageInfo['mime'] ?? null) !== $expectedMime) {
            throw new \InvalidArgumentException($label . ' ne contient pas une image valide.');
        }

        $width = (int) ($imageInfo[0] ?? 0);
        $height = (int) ($imageInfo[1] ?? 0);

        if (
            $width < 1
            || $height < 1
            || $width > self::MAX_IMAGE_DIMENSION
            || $height > self::MAX_IMAGE_DIMENSION
            || ($width * $height) > self::MAX_IMAGE_PIXELS
        ) {
            throw new \InvalidArgumentException($label . ' possède des dimensions trop importantes.');
        }

        return $metadata . ',' . base64_encode($binary);
    }
}
