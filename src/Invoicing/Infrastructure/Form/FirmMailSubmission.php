<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Form;

use App\Invoicing\Application\MailVariables;
use App\Invoicing\Application\RenderedMail;
use App\Invoicing\Domain\FirmMailServer;
use App\Invoicing\Domain\MailEncryption;
use App\Invoicing\Domain\MailTemplate;
use App\Party\Domain\DocumentLanguage;
use Symfony\Component\HttpFoundation\Request;

/**
 * What came in from a tab of the e-mail settings, checked before anything is
 * written: the values to show again and a message per field that is wrong. The
 * password is kept apart from the values, so it is never shown again.
 */
final readonly class FirmMailSubmission
{
    private const int MAX_HOST = 253;
    private const int MAX_USERNAME = 255;
    private const int MAX_ADDRESS = 254;
    private const int MAX_NAME = 255;
    private const int MAX_PASSWORD = 512;

    /**
     * @param array<string, string>                                      $values server fields, without the password
     * @param array<string, array{subject: string, body: string}>        $texts  document language => template text, stored form
     * @param array<string, array{subject: string, body: string}>        $typed  document language => template text, as typed
     * @param array<string, array{0: string, 1?: array<string, string>}> $errors field id => translation key and its parameters
     */
    private function __construct(public array $values, public array $texts, public array $typed, public array $errors, public string $password = '')
    {
    }

    /**
     * Fields subject[cs], body[cs] and so on, one pair per document language. The
     * tokens come as readable labels of that language and are stored by name.
     */
    public static function templates(Request $request, MailTokenLabels $labels): self
    {
        $subjects = $request->request->all('subject');
        $bodies = $request->request->all('body');
        $texts = [];
        $typed = [];
        $errors = [];
        foreach (DocumentLanguage::cases() as $language) {
            $code = $language->value;
            $subject = trim(\is_string($subjects[$code] ?? null) ? $subjects[$code] : '');
            $body = trim(str_replace(["\r\n", "\r"], "\n", \is_string($bodies[$code] ?? null) ? $bodies[$code] : ''));
            $typed[$code] = ['subject' => $subject, 'body' => $body];
            $storedSubject = $labels->fromEditor($subject, $language);
            $storedBody = $labels->fromEditor($body, $language);
            $texts[$code] = ['subject' => $storedSubject['text'], 'body' => $storedBody['text']];

            if ('' === $subject) {
                $errors['subject-'.$code] = ['party.error.required'];
            } elseif (mb_strlen($storedSubject['text']) > MailTemplate::MAX_SUBJECT) {
                $errors['subject-'.$code] = ['party.error.too_long'];
            } elseif ([] !== $storedSubject['unknown']) {
                $errors['subject-'.$code] = ['firm.mail.error.unknown_variables', ['names' => implode(', ', $storedSubject['unknown'])]];
            } elseif (str_contains($storedSubject['text'], '{'.MailVariables::LINK.'}')) {
                $errors['subject-'.$code] = ['firm.mail.error.link_in_subject', ['link' => $labels->token(MailVariables::LINK, $language)]];
            }

            if ('' === $body) {
                $errors['body-'.$code] = ['party.error.required'];
            } elseif (mb_strlen($storedBody['text']) > MailTemplate::MAX_BODY) {
                $errors['body-'.$code] = ['party.error.too_long'];
            } elseif ([] !== $storedBody['unknown']) {
                $errors['body-'.$code] = ['firm.mail.error.unknown_variables', ['names' => implode(', ', $storedBody['unknown'])]];
            }
        }

        return new self([], $texts, $typed, $errors);
    }

    /**
     * @param bool $hasPassword a server is stored, so a blank password keeps its own
     */
    public static function server(Request $request, bool $hasPassword): self
    {
        $post = $request->request;
        $values = [
            'host' => rtrim(strtolower(trim($post->getString('host'))), '.'),
            'port' => trim($post->getString('port')),
            'encryption' => $post->getString('encryption'),
            'username' => trim($post->getString('username')),
            'fromAddress' => trim($post->getString('fromAddress')),
            'fromName' => trim($post->getString('fromName')),
        ];
        $password = $post->getString('password');

        $errors = [];
        foreach (['host' => self::MAX_HOST, 'username' => self::MAX_USERNAME, 'fromAddress' => self::MAX_ADDRESS, 'fromName' => self::MAX_NAME] as $field => $max) {
            if ('' === $values[$field]) {
                $errors[$field] = ['party.error.required'];
            } elseif (mb_strlen($values[$field]) > $max) {
                $errors[$field] = ['party.error.too_long'];
            } elseif (1 === preg_match('/[\x00-\x1F\x7F]/', $values[$field])) {
                $errors[$field] = ['firm.mail.error.line_break'];
            }
        }
        if (!isset($errors['host']) && null === FirmMailServer::normalHost($values['host'])) {
            $errors['host'] = ['firm.mail.error.host'];
        }
        if (!\in_array((int) $values['port'], FirmMailServer::PORTS, true) || (string) (int) $values['port'] !== $values['port']) {
            $errors['port'] = ['firm.mail.error.port', ['ports' => implode(', ', FirmMailServer::PORTS)]];
        }
        if (!MailEncryption::tryFrom($values['encryption']) instanceof MailEncryption) {
            $errors['encryption'] = ['firm.mail.error.encryption'];
        }
        if (!isset($errors['fromAddress']) && false === filter_var($values['fromAddress'], \FILTER_VALIDATE_EMAIL)) {
            $errors['fromAddress'] = ['firm.invoicing.error.email'];
        }
        if ('' === $password && !$hasPassword) {
            $errors['password'] = ['party.error.required'];
        } elseif (\strlen($password) > self::MAX_PASSWORD) {
            $errors['password'] = ['party.error.too_long'];
        } elseif (1 === preg_match('/[\x00-\x1F\x7F]/', $password)) {
            $errors['password'] = ['firm.mail.error.line_break'];
        }

        return new self($values, [], [], $errors, $password);
    }

    /**
     * Whether a submitted text is the built-in one, as a form sends it back.
     */
    public static function sameText(RenderedMail $builtIn, string $subject, string $body): bool
    {
        return trim($builtIn->subject) === $subject && trim(str_replace(["\r\n", "\r"], "\n", $builtIn->body)) === $body;
    }
}
