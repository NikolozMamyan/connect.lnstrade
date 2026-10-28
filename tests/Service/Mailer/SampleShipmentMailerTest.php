<?php

namespace App\Tests\Service\Mailer;

use App\Entity\SampleShipment;
use App\Service\Mailer\SampleShipmentMailer;
use App\Service\Mailer\SimpleMailerService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class SampleShipmentMailerTest extends TestCase
{
    public function testRecapContainsSageDocumentRepresentativeRecipientAndExpiryDates(): void
    {
        $shipment = (new SampleShipment('request-token-0042'))
            ->setRecipient([
                'company' => 'Boutique <Démo>',
                'contactName' => 'Marie Martin',
                'address' => '12 rue du Test',
                'addressComplement' => 'Service réception',
                'postalCode' => '75001',
                'city' => 'Paris',
                'country' => 'France',
                'phone' => '0102030405',
                'email' => 'marie@example.com',
                'message' => 'Livrer le matin',
            ])
            ->setRepresentative(['id' => 7, 'firstName' => 'Sonita', 'lastName' => 'SIEK'])
            ->setLines([['reference' => '123456', 'designation' => 'Produit test', 'quantity' => 3, 'expiryDate' => '2027-03-15']])
            ->markSent('ECH-261022-000042', 'Boutique Démo #000042', [], ['message' => 'Bon de commande N° BC002885 créé avec succès.']);

        $transport = $this->createMock(MailerInterface::class);
        $transport->expects(self::once())->method('send')->willReturnCallback(static function (Email $email): void {
            self::assertSame(['adv@lnstrade.fr'], array_map(static fn ($address): string => $address->getAddress(), $email->getTo()));
            self::assertStringContainsString('BC002885', $email->getSubject());
            foreach (['ECH-261022-000042', 'Sonita SIEK', 'Marie Martin', '12 rue du Test', 'Service réception', '75001 Paris', 'France', '0102030405', 'marie@example.com', 'Livrer le matin', '123456', 'Produit test', '15/03/2027'] as $value) {
                self::assertStringContainsString($value, $email->getTextBody());
                self::assertStringContainsString($value, $email->getHtmlBody());
            }
            self::assertStringContainsString('Quantité : 3', $email->getTextBody());
            self::assertStringContainsString('Boutique &lt;Démo&gt;', $email->getHtmlBody());
        });
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3).'/templates'), ['autoescape' => 'html']);
        $mailer = new SampleShipmentMailer(new SimpleMailerService($transport, $twig, 'pro@ultrapop.com'), 'adv@lnstrade.fr');

        $mailer->send($shipment);
    }

    public function testConsumptionRecapIsSentToAdvWithTheCorrectClientAndProducts(): void
    {
        $shipment = (new SampleShipment('consumption-request-0042', SampleShipment::PURPOSE_CONSUMPTION))
            ->setRecipient([
                'company' => 'CONSOMMATION INTERNE LNS TRADE',
                'contactName' => null,
                'address' => '13 quai Kleber',
                'addressComplement' => null,
                'postalCode' => '67000',
                'city' => 'Strasbourg',
                'country' => 'France',
                'phone' => null,
                'email' => null,
                'message' => 'Consommation interne LNS Trade',
            ])
            ->setRepresentative(['firstName' => 'Corentin', 'lastName' => 'BURY'])
            ->setLines([['reference' => '123456', 'designation' => 'Produit consommé', 'quantity' => 2, 'expiryDate' => '2027-03-15']])
            ->markSent('CNS-261028-000042', 'LNS CONSO', ['numClient' => 'CONSOLNS'], ['piece' => 'BC003001']);
        $transport = $this->createMock(MailerInterface::class);
        $transport->expects(self::once())->method('send')->willReturnCallback(static function (Email $email): void {
            self::assertSame(['adv@lnstrade.fr'], array_map(static fn ($address): string => $address->getAddress(), $email->getTo()));
            self::assertStringContainsString('Consommation interne BC003001', $email->getSubject());
            foreach (['BC003001', 'CNS-261028-000042', 'CONSOLNS', 'Corentin BURY', '13 quai Kleber', '67000 Strasbourg', '123456', 'Produit consommé', '15/03/2027'] as $value) {
                self::assertStringContainsString($value, $email->getTextBody());
                self::assertStringContainsString($value, $email->getHtmlBody());
            }
            self::assertStringContainsString('Quantité : 2', $email->getTextBody());
            self::assertStringContainsString('Propriétaire Sage', $email->getHtmlBody());
            self::assertStringContainsString('Produits consommés', $email->getHtmlBody());
            self::assertStringNotContainsString('9ECH', $email->getTextBody());
            self::assertStringNotContainsString('9ECH', $email->getHtmlBody());
            self::assertStringNotContainsString('ÉCHANTILLONS', $email->getHtmlBody());
        });
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3).'/templates'), ['autoescape' => 'html']);
        $mailer = new SampleShipmentMailer(new SimpleMailerService($transport, $twig, 'pro@ultrapop.com'), 'adv@lnstrade.fr');

        $mailer->send($shipment);
    }
}
