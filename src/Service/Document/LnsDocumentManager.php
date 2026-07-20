<?php

namespace App\Service\Document;

use App\Entity\LnsDocument;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final class LnsDocumentManager
{
    public function __construct(
        private readonly LnsDocumentContentManager $contentManager,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(LnsDocument $document, string $contentPayload, ?User $author): void
    {
        $content = $this->contentManager->normalize($contentPayload);

        if ($document->getId() === null && $document->getCreatedBy() === null) {
            $document->setCreatedBy($author);
        }

        $document->setContent($content);
        $this->entityManager->persist($document);
        $this->entityManager->flush();
    }

    public function delete(LnsDocument $document): void
    {
        $this->entityManager->remove($document);
        $this->entityManager->flush();
    }
}
