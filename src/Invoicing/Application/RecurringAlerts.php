<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Identity\Application\UserRepository;
use App\Invoicing\Domain\FirmMailServer;
use App\Invoicing\Domain\RecurringInvoice;
use App\Party\Application\TransactionalWork;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\PartyEmail;
use Psr\Log\LoggerInterface;

/**
 * Tells the firm by e-mail that a recurring template could not issue or send its
 * invoice: to the firm's contact address, else to the template's owner, through
 * the firm's own server, in the language of the template's documents, with a
 * built-in text. At most once per template and day, however often the day runs.
 *
 * Without a server or an address nothing can leave; the shell notice of failing
 * templates tells instead, so this is the one case the mail is skipped. The day
 * is marked before the mail goes, so a run that dies while the server talks does
 * not tell twice; a refusal of the server is logged and the notice stays.
 */
final readonly class RecurringAlerts
{
    private const array SUBJECT = [
        'issue' => ['cs' => 'Pravidelnou fakturu se nepodařilo vystavit', 'en' => 'A recurring invoice could not be issued'],
        'send' => ['cs' => 'Pravidelná faktura se vystavila, ale neodeslala', 'en' => 'A recurring invoice was issued but not sent'],
    ];

    private const array OPENING = [
        'issue' => [
            'cs' => 'Pravidelnou fakturu firmy %s pro %s se %s nepodařilo vystavit.',
            'en' => 'A recurring invoice of %s for %s could not be issued on %3$s.',
        ],
        'send' => [
            'cs' => 'Pravidelná faktura firmy %s pro %s se %s vystavila, ale neodeslala. Čeká mezi fakturami k odeslání.',
            'en' => 'A recurring invoice of %s for %s was issued on %3$s but not sent. It waits among the invoices to send.',
        ],
    ];

    private const array CLOSING = [
        'issue' => [
            'cs' => 'Další běh to zkusí znovu zítra v 5:00. Příčinu opravte ve Foresi v pravidelných fakturách.',
            'en' => 'The next run tries again tomorrow at 05:00. Fix the cause in Foresi under recurring invoices.',
        ],
        'send' => [
            'cs' => 'Po opravě ji odešlete ve Foresi z přehledu faktur.',
            'en' => 'Once fixed, send it in Foresi from the list of invoices.',
        ],
    ];

    private const array REASONS = [
        'invoice.problem.customer_archived' => ['cs' => 'Odběratel je pro tuto firmu archivovaný.', 'en' => 'The customer is archived for this firm.'],
        'invoice.problem.customer_unknown' => ['cs' => 'Odběratel už neexistuje.', 'en' => 'The customer no longer exists.'],
        'invoice.problem.customer_address_incomplete' => ['cs' => 'Odběratel nemá úplnou fakturační adresu.', 'en' => 'The customer has no complete invoicing address.'],
        'invoice.problem.line_rate_missing' => ['cs' => 'Druh sazby DPH některé položky k datu zdanitelného plnění neexistuje.', 'en' => 'The kind of VAT rate of a line does not exist on the tax point.'],
        'invoice.problem.firm_archived' => ['cs' => 'Firma je archivovaná.', 'en' => 'The firm is archived.'],
        'invoice.problem.firm_country_unsupported' => ['cs' => 'Pravidla země firmy nejsou k dispozici.', 'en' => 'The rules of the firm\'s country are not available.'],
        'send.no_recipient' => ['cs' => 'Odběratel nemá e-mail pro faktury.', 'en' => 'The customer has no e-mail address for invoices.'],
        'send.bad_address' => ['cs' => 'E-mail odběratele není platný.', 'en' => 'An e-mail address of the customer is not valid.'],
        'send.rate_limited' => ['cs' => 'Firma vyčerpala denní limit odeslaných e-mailů.', 'en' => 'The firm has used up its daily budget of e-mails.'],
        'send.undrawable' => ['cs' => 'Fakturu nejde vykreslit do PDF.', 'en' => 'The invoice cannot be drawn as a PDF.'],
    ];

    private const array OTHER_REASON = ['cs' => 'Pravidla to nedovolila (kód %s).', 'en' => 'The rules refused it (code %s).'];

    public function __construct(
        private RecurringInvoices $templates,
        private MailServers $servers,
        private MailGateway $gateway,
        private FirmInvoicingSettings $settings,
        private UserRepository $users,
        private TransactionalWork $work,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param string             $code  what was refused: a problem key of IssueRefused, or "send." and a reason of SendRefused
     * @param \DateTimeImmutable $today the tenant's day of the run
     */
    public function alert(RecurringInvoice $template, string $code, \DateTimeImmutable $today): void
    {
        $day = $today->format('Y-m-d');
        if ($template->lastAlertOn()?->format('Y-m-d') === $day) {
            return;
        }
        $firm = $template->firm();
        $server = $this->servers->for($firm);
        $to = $this->recipient($template);
        if (!$server instanceof FirmMailServer || null === $to) {
            return;
        }
        $message = $this->message($template, $code, $today, $server, $to);
        $id = $template->id();

        $this->work->run(function () use ($id, $today): void {
            $this->templates->lock($id)->markAlerted($today);
        });
        try {
            $this->gateway->send($message, $server);
        } catch (MailRefused $refused) {
            $this->logger->warning('The alert about recurring invoice {template} was not sent: {reason}.', ['template' => $id, 'reason' => $refused->getMessage()]);
        }
    }

    private function recipient(RecurringInvoice $template): ?string
    {
        $contact = trim($this->settings->for($template->firm())->contactEmail());
        if ('' !== $contact && PartyEmail::isValid($contact)) {
            return $contact;
        }
        $owner = $template->owner();
        $email = null === $owner ? null : $this->users->findById($owner)?->email();

        return null !== $email && PartyEmail::isValid($email) ? $email : null;
    }

    private function message(RecurringInvoice $template, string $code, \DateTimeImmutable $today, FirmMailServer $server, string $to): MailMessage
    {
        $language = $template->language()->value;
        $stage = str_starts_with($code, 'send.') ? 'send' : 'issue';
        $date = DocumentLanguage::Czech === $template->language() ? $today->format('j. n. Y') : $today->format('Y-m-d');
        $reason = isset(self::REASONS[$code]) ? self::REASONS[$code][$language] : \sprintf(self::OTHER_REASON[$language], $code);
        $text = implode("\n\n", [
            \sprintf(self::OPENING[$stage][$language], $template->firm()->name(), $template->customer()->displayName(), $date),
            $reason,
            self::CLOSING[$stage][$language],
        ]);
        $from = $server->fromAddress();
        $domain = substr($from, (int) strrpos($from, '@') + 1);

        return new MailMessage(
            $from,
            $server->fromName(),
            null,
            [$to],
            [],
            self::SUBJECT[$stage][$language],
            $text,
            nl2br(htmlspecialchars($text, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'), false),
            // The same for every try of the day, so a repeat is recognisable.
            \sprintf('<recurring-alert.%s.%s@%s>', $template->id(), $today->format('Ymd'), $domain),
        );
    }
}
