<?php

namespace App\Service\Document;

use App\Entity\LnsDocument;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;

final class LnsDocumentManager
{
    private const MAX_DOCUMENT_TITLE_LENGTH = 180;
    private const MAX_DOCUMENT_DESCRIPTION_LENGTH = 20000;

    public function __construct(
        private readonly LnsDocumentContentManager $contentManager,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(
        LnsDocument $document,
        string $contentPayload,
        ?User $author,
        ?int $expectedVersion = null,
    ): void
    {
        $this->assertVersion($document, $expectedVersion);
        $content = $this->contentManager->normalize($contentPayload);

        $this->assignAuthor($document, $author);
        $document->setContent($content);
        $document->setDraft(false);
        $this->entityManager->persist($document);
        $this->flush($document);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function autosave(LnsDocument $document, array $payload, ?User $author): void
    {
        $title = $this->draftString($payload['title'] ?? null, self::MAX_DOCUMENT_TITLE_LENGTH, 'Le titre du document');
        $description = $this->draftString($payload['description'] ?? null, self::MAX_DOCUMENT_DESCRIPTION_LENGTH, 'La description du document');
        $autoGenerateToc = $payload['autoGenerateToc'] ?? null;
        $contentPayload = $payload['contentJson'] ?? null;
        $expectedVersion = $payload['revision'] ?? null;

        if (!is_bool($autoGenerateToc)) {
            throw new \InvalidArgumentException('Le choix du sommaire automatique est invalide.');
        }

        if (!is_string($contentPayload)) {
            throw new \InvalidArgumentException('Le contenu du brouillon est invalide.');
        }

        if (!is_int($expectedVersion) || $expectedVersion < 1) {
            throw new \InvalidArgumentException('La version du brouillon est invalide.');
        }

        $this->assertVersion($document, $expectedVersion);
        $content = $this->contentManager->normalizeDraft($contentPayload);

        $this->assignAuthor($document, $author);
        $document
            ->setTitle($title)
            ->setDescription($description)
            ->setAutoGenerateToc($autoGenerateToc)
            ->setContent($content)
            ->setDraft(true);

        $this->entityManager->persist($document);
        $this->flush($document);
    }

    public function delete(LnsDocument $document): void
    {
        $this->entityManager->remove($document);
        $this->entityManager->flush();
    }

    private function assignAuthor(LnsDocument $document, ?User $author): void
    {
        if ($document->getId() === null && $document->getCreatedBy() === null) {
            $document->setCreatedBy($author);
        }
    }

    private function assertVersion(LnsDocument $document, ?int $expectedVersion): void
    {
        if ($expectedVersion !== null && $document->getEditVersion() !== $expectedVersion) {
            throw new LnsDocumentVersionConflict($document->getEditVersion());
        }
    }

    private function flush(LnsDocument $document): void
    {
        try {
            $this->entityManager->flush();
        } catch (OptimisticLockException) {
            throw new LnsDocumentVersionConflict($document->getEditVersion());
        }
    }

    private function draftString(mixed $value, int $maxLength, string $label): string
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
}
