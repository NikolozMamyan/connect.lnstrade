<?php

namespace App\Service\Document;

final class LnsDocumentVersionConflict extends \RuntimeException
{
    public function __construct(private readonly int $currentVersion)
    {
        parent::__construct('Une version plus récente est déjà enregistrée. Votre travail local est conservé et peut être resynchronisé.');
    }

    public function getCurrentVersion(): int
    {
        return $this->currentVersion;
    }
}
