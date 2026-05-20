<?php

namespace App\Tests\Service\Mailer;

use App\Service\Mailer\SimpleMailerService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

class SimpleMailerServiceTest extends TestCase
{
    public function testSendTextMessageBuildsEmailWithAttachment(): void
    {
        $capturedEmail = null;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->once())
            ->method('send')
            ->with($this->callback(static function ($email) use (&$capturedEmail): bool {
                $capturedEmail = $email;

                return $email instanceof Email;
            }));

        $service = new SimpleMailerService(
            $mailer,
            'no-reply@lnstrade.fr',
            ['corentin.bury@lnstrade.fr']
        );

        $service->sendTextMessage(
            'Sujet test',
            'Message test',
            [],
            [[
                'content' => 'a,b,c',
                'name' => 'export.csv',
                'contentType' => 'text/csv',
            ]],
            ['nikoloz.mamyan@lnstrade.fr']
        );

        self::assertInstanceOf(Email::class, $capturedEmail);
        self::assertSame('Sujet test', $capturedEmail->getSubject());
        self::assertSame(['no-reply@lnstrade.fr'], array_map(static fn ($address) => $address->getAddress(), $capturedEmail->getFrom()));
        self::assertSame(['corentin.bury@lnstrade.fr'], array_map(static fn ($address) => $address->getAddress(), $capturedEmail->getTo()));
        self::assertSame(['nikoloz.mamyan@lnstrade.fr'], array_map(static fn ($address) => $address->getAddress(), $capturedEmail->getCc()));
        self::assertStringContainsString('Message test', $capturedEmail->getTextBody() ?? '');

        $attachments = $capturedEmail->getAttachments();
        self::assertCount(1, $attachments);
        self::assertInstanceOf(DataPart::class, $attachments[0]);
        self::assertSame('export.csv', $attachments[0]->getFilename());
    }
}
