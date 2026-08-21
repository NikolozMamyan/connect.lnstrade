<?php

namespace App\Tests\Controller\Flux;

use App\Controller\Flux\FluxWebhookController;
use App\Service\Mailer\SimpleMailerService;
use PHPUnit\Framework\TestCase;

class FluxWebhookControllerTest extends TestCase
{
    public function testDealWebhookMailAddsCommercialAsCcRecipient(): void
    {
        $mailer = $this->createMock(SimpleMailerService::class);
        $mailer->expects($this->once())
            ->method('sendTemplateMessage')
            ->with(
                self::stringContains('12345'),
                'mailer/order_webhook_success.html.twig',
                self::callback(static fn (array $context): bool => $context['dealId'] === '12345'),
                self::isString(),
                [],
                [],
                ['commercial@lnstrade.fr']
            );

        $controller = new FluxWebhookController();
        $method = new \ReflectionMethod($controller, 'sendDealWebhookMail');
        $method->invoke(
            $controller,
            $mailer,
            true,
            '12345',
            ['referenceCommande' => 'BC-001', 'numClient' => 'CLI-001', 'orderLines' => []],
            null,
            'commercial@lnstrade.fr'
        );
    }
}
