<?php

namespace App\Tests\Service\Mailer;

use App\Service\Mailer\DeliveryOrderSyncReportMailer;
use App\Service\Mailer\SimpleMailerService;
use PHPUnit\Framework\TestCase;

final class DeliveryOrderSyncReportMailerTest extends TestCase
{
    public function testCompletedReportContainsStatisticsAndHubSpotCorrectionLinks(): void
    {
        $capturedSubject = null;
        $capturedTemplate = null;
        $capturedContext = null;
        $capturedText = null;
        $capturedRecipients = null;
        $mailer = $this->createMock(SimpleMailerService::class);
        $mailer
            ->expects($this->once())
            ->method('sendTemplateMessage')
            ->willReturnCallback(static function (
                string $subject,
                string $template,
                array $context,
                string $text,
                array $recipients,
            ) use (&$capturedSubject, &$capturedTemplate, &$capturedContext, &$capturedText, &$capturedRecipients): void {
                $capturedSubject = $subject;
                $capturedTemplate = $template;
                $capturedContext = $context;
                $capturedText = $text;
                $capturedRecipients = $recipients;
            });
        $reportMailer = new DeliveryOrderSyncReportMailer(
            $mailer,
            'corentin.bury@lnstrade.fr',
            '143807682',
        );

        $reportMailer->sendCompleted(
            new \DateTimeImmutable('2026-07-01'),
            new \DateTimeImmutable('2026-09-28'),
            [
                'deliveryNotesAnalyzed' => 12,
                'invoicesAnalyzed' => 40,
                'sent' => 4,
                'updated' => 8,
                'existing' => 2,
                'skipped' => 38,
                'changed' => 0,
                'failed' => 1,
                'errors' => [[
                    'piece' => 'FA001',
                    'documentLabel' => 'Facture',
                    'clientId' => 'CLI001',
                    'clientName' => 'Client test',
                    'message' => 'Aucune societe HubSpot trouvee pour le client Sage CLI001.',
                ]],
                'warnings' => [[
                    'piece' => 'FA002',
                    'documentLabel' => 'Facture',
                    'clientId' => 'CLI002',
                    'clientName' => 'Autre client',
                    'message' => 'Produit HubSpot introuvable pour le SKU TEST-1.',
                ]],
            ],
        );

        self::assertSame('[LNS Connecteur] Orders HubSpot : 1 erreur(s) a corriger', $capturedSubject);
        self::assertSame('mailer/delivery_order_sync_report.html.twig', $capturedTemplate);
        self::assertSame(['corentin.bury@lnstrade.fr'], $capturedRecipients);
        self::assertSame(12, $capturedContext['result']['deliveryNotesAnalyzed']);
        self::assertStringContainsString('/objects/0-2/views/all/list', $capturedContext['errors'][0]['actionUrl']);
        self::assertStringContainsString('CLI001', $capturedContext['errors'][0]['actionHint']);
        self::assertStringContainsString('/objects/0-7/views/all/list', $capturedContext['warnings'][0]['actionUrl']);
        self::assertStringContainsString('Orders actualises : 8', $capturedText);
        self::assertStringContainsString('Client test (CLI001)', $capturedText);
    }
}
