<?php

namespace App\Service\Document;

final class LnsDocumentVersionConflict extends \RuntimeException
{
    public function __construct(private readonly int $currentVersion)
    {
        parent::__construct('Ce document a été modifié ailleurs. Rechargez la page avant de continuer.');
    }

    public function getCurrentVersion(): int
    {
        return $this->currentVersion;
    }
}
