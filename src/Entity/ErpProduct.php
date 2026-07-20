<?php

namespace App\Entity;

use App\Repository\ErpProductRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ErpProductRepository::class)]
#[ORM\Table(name: 'erp_product')]
#[ORM\UniqueConstraint(name: 'uniq_erp_product_reference', columns: ['reference'])]
class ErpProduct
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    private ?string $reference = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $designation = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $famille = null;

    #[ORM\Column(name: 'unite_poids', length: 32, nullable: true)]
    private ?string $unitePoids = null;

    #[ORM\Column(name: 'poids_net', type: Types::FLOAT, nullable: true)]
    private ?float $poidsNet = null;

    #[ORM\Column(name: 'poids_brut', type: Types::FLOAT, nullable: true)]
    private ?float $poidsBrut = null;

    #[ORM\Column(name: 'unite_vente', length: 64, nullable: true)]
    private ?string $uniteVente = null;

    #[ORM\Column(name: 'prix_achat', type: Types::FLOAT, nullable: true)]
    private ?float $prixAchat = null;

    #[ORM\Column(name: 'prix_vente', type: Types::FLOAT, nullable: true)]
    private ?float $prixVente = null;

    #[ORM\Column(name: 'prix_ttc', type: Types::FLOAT, nullable: true)]
    private ?float $prixTtc = null;

    #[ORM\Column(name: 'suivi_stock', length: 32, nullable: true)]
    private ?string $suiviStock = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $statut = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $anglais = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $espagnol = null;

    #[ORM\Column(name: 'code_barre', length: 64, nullable: true)]
    private ?string $codeBarre = null;

    #[ORM\Column(name: 'code_fiscal', length: 64, nullable: true)]
    private ?string $codeFiscal = null;

    #[ORM\Column(name: 'code_edi', length: 64, nullable: true)]
    private ?string $codeEdi = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $pays = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $conditionnement = null;

    #[ORM\Column(name: 'catalogue_1', length: 150, nullable: true)]
    private ?string $catalogue1 = null;

    #[ORM\Column(name: 'catalogue_2', length: 150, nullable: true)]
    private ?string $catalogue2 = null;

    #[ORM\Column(name: 'catalogue_3', length: 150, nullable: true)]
    private ?string $catalogue3 = null;

    #[ORM\Column(name: 'catalogue_4', length: 150, nullable: true)]
    private ?string $catalogue4 = null;

    #[ORM\Column(name: 'date_creation', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\Column(name: 'champs_libres', type: Types::JSON, nullable: true)]
    private ?array $champsLibres = null;

    #[ORM\Column(name: 'raw_payload', type: Types::JSON, nullable: true)]
    private ?array $rawPayload = null;

    #[ORM\Column(name: 'hubspot_object_id', length: 64, nullable: true)]
    private ?string $hubspotObjectId = null;

    #[ORM\Column(name: 'stock_reel', type: Types::FLOAT, nullable: true)]
    private ?float $stockReel = null;

    #[ORM\Column(name: 'stock_dispo', type: Types::FLOAT, nullable: true)]
    private ?float $stockDispo = null;

    #[ORM\Column(name: 'stock_a_terme', type: Types::FLOAT, nullable: true)]
    private ?float $stockATerme = null;

    #[ORM\Column(name: 'raw_stock_payload', type: Types::JSON, nullable: true)]
    private ?array $rawStockPayload = null;

    #[ORM\Column(name: 'stock_updated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $stockUpdatedAt = null;

    #[ORM\Column(name: 'stock_synced_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $stockSyncedAt = null;

    #[ORM\Column(name: 'last_synced_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSyncedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(string $reference): static
    {
        $this->reference = $reference;

        return $this;
    }

    public function getDesignation(): ?string
    {
        return $this->designation;
    }

    public function setDesignation(?string $designation): static
    {
        $this->designation = $designation;

        return $this;
    }

    public function getFamille(): ?string
    {
        return $this->famille;
    }

    public function setFamille(?string $famille): static
    {
        $this->famille = $famille;

        return $this;
    }

    public function getUnitePoids(): ?string
    {
        return $this->unitePoids;
    }

    public function setUnitePoids(?string $unitePoids): static
    {
        $this->unitePoids = $unitePoids;

        return $this;
    }

    public function getPoidsNet(): ?float
    {
        return $this->poidsNet;
    }

    public function setPoidsNet(?float $poidsNet): static
    {
        $this->poidsNet = $poidsNet;

        return $this;
    }

    public function getPoidsBrut(): ?float
    {
        return $this->poidsBrut;
    }

    public function setPoidsBrut(?float $poidsBrut): static
    {
        $this->poidsBrut = $poidsBrut;

        return $this;
    }

    public function getUniteVente(): ?string
    {
        return $this->uniteVente;
    }

    public function setUniteVente(?string $uniteVente): static
    {
        $this->uniteVente = $uniteVente;

        return $this;
    }

    public function getPrixAchat(): ?float
    {
        return $this->prixAchat;
    }

    public function setPrixAchat(?float $prixAchat): static
    {
        $this->prixAchat = $prixAchat;

        return $this;
    }

    public function getPrixVente(): ?float
    {
        return $this->prixVente;
    }

    public function setPrixVente(?float $prixVente): static
    {
        $this->prixVente = $prixVente;

        return $this;
    }

    public function getPrixTtc(): ?float
    {
        return $this->prixTtc;
    }

    public function setPrixTtc(?float $prixTtc): static
    {
        $this->prixTtc = $prixTtc;

        return $this;
    }

    public function getSuiviStock(): ?string
    {
        return $this->suiviStock;
    }

    public function setSuiviStock(?string $suiviStock): static
    {
        $this->suiviStock = $suiviStock;

        return $this;
    }

    public function getStatut(): ?string
    {
        return $this->statut;
    }

    public function setStatut(?string $statut): static
    {
        $this->statut = $statut;

        return $this;
    }

    public function getAnglais(): ?string
    {
        return $this->anglais;
    }

    public function setAnglais(?string $anglais): static
    {
        $this->anglais = $anglais;

        return $this;
    }

    public function getEspagnol(): ?string
    {
        return $this->espagnol;
    }

    public function setEspagnol(?string $espagnol): static
    {
        $this->espagnol = $espagnol;

        return $this;
    }

    public function getCodeBarre(): ?string
    {
        return $this->codeBarre;
    }

    public function setCodeBarre(?string $codeBarre): static
    {
        $this->codeBarre = $codeBarre;

        return $this;
    }

    public function getCodeFiscal(): ?string
    {
        return $this->codeFiscal;
    }

    public function setCodeFiscal(?string $codeFiscal): static
    {
        $this->codeFiscal = $codeFiscal;

        return $this;
    }

    public function getCodeEdi(): ?string
    {
        return $this->codeEdi;
    }

    public function setCodeEdi(?string $codeEdi): static
    {
        $this->codeEdi = $codeEdi;

        return $this;
    }

    public function getPays(): ?string
    {
        return $this->pays;
    }

    public function setPays(?string $pays): static
    {
        $this->pays = $pays;

        return $this;
    }

    public function getConditionnement(): ?string
    {
        return $this->conditionnement;
    }

    public function setConditionnement(?string $conditionnement): static
    {
        $this->conditionnement = $conditionnement;

        return $this;
    }

    public function getCatalogue1(): ?string
    {
        return $this->catalogue1;
    }

    public function setCatalogue1(?string $catalogue1): static
    {
        $this->catalogue1 = $catalogue1;

        return $this;
    }

    public function getCatalogue2(): ?string
    {
        return $this->catalogue2;
    }

    public function setCatalogue2(?string $catalogue2): static
    {
        $this->catalogue2 = $catalogue2;

        return $this;
    }

    public function getCatalogue3(): ?string
    {
        return $this->catalogue3;
    }

    public function setCatalogue3(?string $catalogue3): static
    {
        $this->catalogue3 = $catalogue3;

        return $this;
    }

    public function getCatalogue4(): ?string
    {
        return $this->catalogue4;
    }

    public function setCatalogue4(?string $catalogue4): static
    {
        $this->catalogue4 = $catalogue4;

        return $this;
    }

    public function getDateCreation(): ?\DateTimeImmutable
    {
        return $this->dateCreation;
    }

    public function setDateCreation(?\DateTimeImmutable $dateCreation): static
    {
        $this->dateCreation = $dateCreation;

        return $this;
    }

    public function getChampsLibres(): ?array
    {
        return $this->champsLibres;
    }

    public function setChampsLibres(?array $champsLibres): static
    {
        $this->champsLibres = $champsLibres;

        return $this;
    }

    public function getRawPayload(): ?array
    {
        return $this->rawPayload;
    }

    public function setRawPayload(?array $rawPayload): static
    {
        $this->rawPayload = $rawPayload;

        return $this;
    }

    public function getHubspotObjectId(): ?string
    {
        return $this->hubspotObjectId;
    }

    public function setHubspotObjectId(?string $hubspotObjectId): static
    {
        $this->hubspotObjectId = $hubspotObjectId;

        return $this;
    }

    public function getLastSyncedAt(): ?\DateTimeImmutable
    {
        return $this->lastSyncedAt;
    }

    public function setLastSyncedAt(?\DateTimeImmutable $lastSyncedAt): static
    {
        $this->lastSyncedAt = $lastSyncedAt;

        return $this;
    }

    public function getStockReel(): ?float
    {
        return $this->stockReel;
    }

    public function setStockReel(?float $stockReel): static
    {
        $this->stockReel = $stockReel;

        return $this;
    }

    public function getStockDispo(): ?float
    {
        return $this->stockDispo;
    }

    public function setStockDispo(?float $stockDispo): static
    {
        $this->stockDispo = $stockDispo;

        return $this;
    }

    public function getStockATerme(): ?float
    {
        return $this->stockATerme;
    }

    public function setStockATerme(?float $stockATerme): static
    {
        $this->stockATerme = $stockATerme;

        return $this;
    }

    public function getRawStockPayload(): ?array
    {
        return $this->rawStockPayload;
    }

    public function setRawStockPayload(?array $rawStockPayload): static
    {
        $this->rawStockPayload = $rawStockPayload;

        return $this;
    }

    public function getStockUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->stockUpdatedAt;
    }

    public function setStockUpdatedAt(?\DateTimeImmutable $stockUpdatedAt): static
    {
        $this->stockUpdatedAt = $stockUpdatedAt;

        return $this;
    }

    public function getStockSyncedAt(): ?\DateTimeImmutable
    {
        return $this->stockSyncedAt;
    }

    public function setStockSyncedAt(?\DateTimeImmutable $stockSyncedAt): static
    {
        $this->stockSyncedAt = $stockSyncedAt;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function getSageFieldValue(string $field): mixed
    {
        return match ($field) {
            'reference' => $this->getReference(),
            'designation' => $this->getDesignation(),
            'famille' => $this->getFamille(),
            'unitePoids' => $this->getUnitePoids(),
            'poidsNet' => $this->getPoidsNet(),
            'poidsBrut' => $this->getPoidsBrut(),
            'uniteVente' => $this->getUniteVente(),
            'prixAchat' => $this->getPrixAchat(),
            'prixVente' => $this->getPrixVente(),
            'prixTTC' => $this->getPrixTtc(),
            'suiviStock' => $this->getSuiviStock(),
            'statut' => $this->getStatut(),
            'anglais' => $this->getAnglais(),
            'espagnol' => $this->getEspagnol(),
            'codeBarre' => $this->getCodeBarre(),
            'codeFiscal' => $this->getCodeFiscal(),
            'codeEdi' => $this->getCodeEdi(),
            'pays' => $this->getPays(),
            'conditionnement' => $this->getConditionnement(),
            'catalogue1' => $this->getCatalogue1(),
            'catalogue2' => $this->getCatalogue2(),
            'catalogue3' => $this->getCatalogue3(),
            'catalogue4' => $this->getCatalogue4(),
            'stockReel' => $this->getStockReel(),
            'stockDispo' => $this->getStockDispo(),
            'stockATerme' => $this->getStockATerme(),
            'dateCreation' => $this->getDateCreation()?->format('c'),
            'champsLibres' => $this->getChampsLibres(),
            default => $this->rawPayload[$field] ?? null,
        };
    }
}
