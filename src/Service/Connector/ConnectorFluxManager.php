<?php

namespace App\Service\Connector;

use App\Entity\ConnectorFlux;
use App\Repository\ConnectorFluxRepository;
use Doctrine\ORM\EntityManagerInterface;

class ConnectorFluxManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ConnectorFluxRepository $connectorFluxRepository,
    ) {
    }

    public function create(
        string $name,
        string $code,
        string $sourceSystem,
        string $targetSystem,
        string $sourceObject,
        string $targetObject,
        bool $isActive = true,
        ?string $description = null,
    ): ConnectorFlux {
        $existingFlux = $this->connectorFluxRepository->findOneBy(['code' => $code]);

        if ($existingFlux !== null) {
            throw new \InvalidArgumentException(sprintf('Un flux avec le code "%s" existe déjà.', $code));
        }

        $flux = new ConnectorFlux();
        $flux->setName($name);
        $flux->setCode($code);
        $flux->setSourceSystem($sourceSystem);
        $flux->setTargetSystem($targetSystem);
        $flux->setSourceObject($sourceObject);
        $flux->setTargetObject($targetObject);
        $flux->setIsActive($isActive);
        $flux->setDescription($description);

        $this->entityManager->persist($flux);
        $this->entityManager->flush();

        return $flux;
    }

    public function update(
        ConnectorFlux $flux,
        string $name,
        string $code,
        string $sourceSystem,
        string $targetSystem,
        string $sourceObject,
        string $targetObject,
        bool $isActive,
        ?string $description = null,
    ): ConnectorFlux {
        $existingFlux = $this->connectorFluxRepository->findOneBy(['code' => $code]);

        if ($existingFlux !== null && $existingFlux->getId() !== $flux->getId()) {
            throw new \InvalidArgumentException(sprintf('Un autre flux avec le code "%s" existe déjà.', $code));
        }

        $flux->setName($name);
        $flux->setCode($code);
        $flux->setSourceSystem($sourceSystem);
        $flux->setTargetSystem($targetSystem);
        $flux->setSourceObject($sourceObject);
        $flux->setTargetObject($targetObject);
        $flux->setIsActive($isActive);
        $flux->setDescription($description);

        $this->entityManager->flush();

        return $flux;
    }

    public function toggleActive(ConnectorFlux $flux): ConnectorFlux
    {
        $flux->setIsActive(!$flux->isActive());

        $this->entityManager->flush();

        return $flux;
    }

    public function save(ConnectorFlux $flux): ConnectorFlux
    {
        $this->entityManager->persist($flux);
        $this->entityManager->flush();

        return $flux;
    }
}