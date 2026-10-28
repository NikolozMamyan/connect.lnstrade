<?php

namespace App\Service\Mailer;

use App\Entity\SampleShipment;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class SampleShipmentMailer
{
    public function __construct(
        private readonly SimpleMailerService $mailer,
        #[Autowire('%sample_shipment_notification_recipient%')]
        private readonly string $recipient,
    ) {
    }

    public function send(SampleShipment $shipment): void
    {
        $recipient = $shipment->getRecipient();
        $representative = $shipment->getRepresentative() ?? [];
        $consumption = $shipment->isConsumption();
        $subject = sprintf('[LNS Trade] %s %s — %s', $consumption ? 'Consommation interne' : 'Échantillons', $shipment->getSageDocument() ?? $shipment->getOrderReference(), $recipient['company']);
        $text = [
            $consumption ? 'Une consommation interne à 0 € a été enregistrée dans Sage.' : 'Un bon de commande d’échantillons à 0 € a été créé dans Sage.',
            'BC Sage : '.($shipment->getSageDocument() ?? 'Numéro non retourné par Sage'),
            'Référence : '.$shipment->getOrderReference(),
            'Client Sage : '.($consumption ? 'CONSOLNS' : '9ECH'),
            'Date : '.$shipment->getSentAt()?->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y H:i'),
            ($consumption ? 'Propriétaire Sage : ' : 'Représentant : ').trim(($representative['firstName'] ?? '').' '.($representative['lastName'] ?? '')),
            '',
            'Entreprise : '.$recipient['company'],
            'Destinataire : '.$recipient['contactName'],
            'Lieu de livraison : '.$shipment->getDeliveryLabel(),
            'Adresse : '.$recipient['address'],
            'Complément : '.$recipient['addressComplement'],
            $recipient['postalCode'].' '.$recipient['city'],
            'Pays : '.$recipient['country'],
            'Téléphone : '.$recipient['phone'],
            'E-mail : '.$recipient['email'],
            'Instructions : '.$recipient['message'],
            '',
            $consumption ? 'Produits consommés — dates de péremption :' : 'Produits — dates de péremption pour le choix des lots :',
        ];

        foreach ($shipment->getLines() as $line) {
            $text[] = sprintf('%s — %s | Quantité : %d | Péremption : %s', $line['reference'], $line['designation'], $line['quantity'], (new \DateTimeImmutable($line['expiryDate']))->format('d/m/Y'));
        }

        $this->mailer->sendTemplateMessage(
            $subject,
            'mailer/sample_shipment.html.twig',
            ['subject' => $subject, 'shipment' => $shipment],
            implode("\n", $text),
            [$this->recipient],
        );
    }
}
