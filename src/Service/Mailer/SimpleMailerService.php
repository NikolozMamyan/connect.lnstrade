<?php

namespace App\Service\Mailer;

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;

class SimpleMailerService
{
    /**
     * @param list<string> $defaultRecipients
     */
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly string $defaultFrom,
        private readonly array $defaultRecipients = [],
    ) {
    }

    /**
     * @param list<string> $to
     * @param list<array{path?: string, name?: string, contentType?: string, content?: string}> $attachments
     * @param list<string> $cc
     *
     * @throws TransportExceptionInterface
     */
    public function sendTextMessage(
        string $subject,
        string $message,
        array $to = [],
        array $attachments = [],
        array $cc = [],
    ): void {
        $recipients = $to !== [] ? $to : $this->defaultRecipients;

        if ($recipients === []) {
            throw new \InvalidArgumentException('Aucun destinataire email n a ete configure.');
        }

        $email = (new Email())
            ->from($this->defaultFrom)
            ->subject($subject)
            ->text($message);

        foreach ($recipients as $recipient) {
            $email->addTo($recipient);
        }

        foreach ($cc as $recipient) {
            $email->addCc($recipient);
        }

        foreach ($attachments as $attachment) {
            if (isset($attachment['path']) && is_string($attachment['path']) && trim($attachment['path']) !== '') {
                $email->attachFromPath(
                    $attachment['path'],
                    $attachment['name'] ?? null,
                    $attachment['contentType'] ?? null
                );

                continue;
            }

            if (isset($attachment['content']) && is_string($attachment['content'])) {
                $email->attach(
                    $attachment['content'],
                    $attachment['name'] ?? 'attachment',
                    $attachment['contentType'] ?? 'application/octet-stream'
                );
            }
        }

        $this->mailer->send($email);
    }

    /**
     * @param list<string> $to
     * @param array<string, mixed> $context
     * @param list<array{path?: string, name?: string, contentType?: string, content?: string}> $attachments
     * @param list<string> $cc
     *
     * @throws TransportExceptionInterface
     */
    public function sendTemplateMessage(
        string $subject,
        string $htmlTemplate,
        array $context = [],
        string $textMessage = '',
        array $to = [],
        array $attachments = [],
        array $cc = [],
    ): void {
        $recipients = $to !== [] ? $to : $this->defaultRecipients;

        if ($recipients === []) {
            throw new \InvalidArgumentException('Aucun destinataire email n a ete configure.');
        }

        $email = (new Email())
            ->from($this->defaultFrom)
            ->subject($subject)
            ->html($this->twig->render($htmlTemplate, $context));

        if ($textMessage !== '') {
            $email->text($textMessage);
        }

        foreach ($recipients as $recipient) {
            $email->addTo($recipient);
        }

        foreach ($cc as $recipient) {
            $email->addCc($recipient);
        }

        foreach ($attachments as $attachment) {
            if (isset($attachment['path']) && is_string($attachment['path']) && trim($attachment['path']) !== '') {
                $email->attachFromPath(
                    $attachment['path'],
                    $attachment['name'] ?? null,
                    $attachment['contentType'] ?? null
                );

                continue;
            }

            if (isset($attachment['content']) && is_string($attachment['content'])) {
                $email->attach(
                    $attachment['content'],
                    $attachment['name'] ?? 'attachment',
                    $attachment['contentType'] ?? 'application/octet-stream'
                );
            }
        }

        $this->mailer->send($email);
    }
}
