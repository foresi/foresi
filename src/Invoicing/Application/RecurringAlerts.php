<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Identity\Application\StaffCheck;
use App\Identity\Application\UserRepository;
use App\Invoicing\Domain\FirmMailServer;
use App\Invoicing\Domain\RecurringInvoice;
use App\Party\Application\TransactionalWork;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\PartyEmail;
use Psr\Log\LoggerInterface;

/**
 * Tells the firm by e-mail which recurring templates could not issue or send
 * their invoice in a run: to the firm's contact address, else to the template's
 * owner while they are still staff of the tenant, through the firm's own server, in the language of the template's
 * documents, with a built-in text. One mail per firm, address and language for
 * the whole run, naming each template, so a day of refusals is one mail and not
 * one per template. Each template is told at most once a day, however often the
 * day runs: the day is checked and marked under the template's lock.
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
        'send.unexpected' => ['cs' => 'Odeslání selhalo kvůli neočekávané chybě.', 'en' => 'Sending failed on an unexpected error.'],
    ];

    private const array OTHER_REASON = ['cs' => 'Pravidla to nedovolila (kód %s).', 'en' => 'The rules refused it (code %s).'];

    private const array DIGEST_SUBJECT = [
        'cs' => '%d pravidelných faktur se nepodařilo vystavit nebo odeslat',
        'en' => '%d recurring invoices could not be issued or sent',
    ];

    private const array DIGEST_SEND_SUBJECT = [
        'cs' => '%d pravidelných faktur se vystavilo, ale neodeslalo',
        'en' => '%d recurring invoices were issued but not sent',
    ];

    private const array DIGEST_OPENING = [
        'cs' => 'Pravidelné faktury firmy %s se %s nepodařilo vystavit nebo odeslat:',
        'en' => 'Recurring invoices of %s could not be issued or sent on %s:',
    ];

    private const array DIGEST_CLOSING = [
        'cs' => 'Vystavené faktury čekají mezi fakturami k odeslání. Nevystavené zkusí další běh znovu zítra v 5:00. Příčinu opravte ve Foresi v pravidelných fakturách.',
        'en' => 'The issued invoices wait among the invoices to send. The next run tries the others again tomorrow at 05:00. Fix the cause in Foresi under recurring invoices.',
    ];

    public function __construct(
        private RecurringInvoices $templates,
        private MailServers $servers,
        private MailGateway $gateway,
        private FirmInvoicingSettings $settings,
        private UserRepository $users,
        private StaffCheck $staff,
        private TransactionalWork $work,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, string> $failures what was refused, by template id: a problem key of IssueRefused, or
     *                                        "send." and a reason of SendRefused
     * @param \DateTimeImmutable    $today    the tenant's day of the run
     */
    public function alertAll(array $failures, \DateTimeImmutable $today): void
    {
        $groups = [];
        foreach ($failures as $id => $code) {
            $template = $this->templates->current($id);
            $server = $this->servers->for($template->firm());
            $to = $this->recipient($template);
            if (!$server instanceof FirmMailServer || null === $to) {
                continue;
            }
            $key = $template->firm()->id().' '.$to.' '.$template->language()->value;
            $groups[$key] ??= ['server' => $server, 'to' => $to, 'language' => $template->language(), 'firm' => $template->firm()->name(), 'firmId' => $template->firm()->id(), 'codes' => []];
            $groups[$key]['codes'][$id] = $code;
        }
        foreach ($groups as $group) {
            $told = [];
            foreach ($group['codes'] as $id => $code) {
                $customer = $this->markAlerted($id, $today);
                if (null !== $customer) {
                    $told[] = ['id' => $id, 'customer' => $customer, 'code' => $code];
                }
            }
            if ([] === $told) {
                continue;
            }
            $message = $this->message($group['firm'], $group['firmId'], $group['language'], $told, $today, $group['server'], $group['to']);
            try {
                $this->gateway->send($message, $group['server']);
            } catch (MailRefused $refused) {
                $this->logger->warning('The alert about recurring invoices {templates} was not sent: {reason}.', ['templates' => implode(', ', array_column($told, 'id')), 'reason' => $refused->getMessage()]);
            }
        }
    }

    /**
     * Marks the day under the template's lock, so two runs of a day never both tell.
     *
     * @return string|null the customer's name, or null when the template was already told today
     */
    private function markAlerted(string $id, \DateTimeImmutable $today): ?string
    {
        return $this->work->run(function () use ($id, $today): ?string {
            $template = $this->templates->lock($id);
            if ($template->lastAlertOn()?->format('Y-m-d') === $today->format('Y-m-d')) {
                return null;
            }
            $template->markAlerted($today);

            return $template->customer()->displayName();
        });
    }

    private function recipient(RecurringInvoice $template): ?string
    {
        $contact = trim($this->settings->for($template->firm())->contactEmail());
        if ('' !== $contact && PartyEmail::isValid($contact)) {
            return $contact;
        }
        // The owner only while they still work here: someone who left hears nothing more.
        $owner = $template->owner();
        $email = null === $owner || !$this->staff->isStaffMember($owner) ? null : $this->users->findById($owner)?->email();

        return null !== $email && PartyEmail::isValid($email) ? $email : null;
    }

    /**
     * @param non-empty-list<array{id: string, customer: string, code: string}> $told
     */
    private function message(string $firm, string $firmId, DocumentLanguage $documentLanguage, array $told, \DateTimeImmutable $today, FirmMailServer $server, string $to): MailMessage
    {
        $language = $documentLanguage->value;
        $date = DocumentLanguage::Czech === $documentLanguage ? $today->format('j. n. Y') : $today->format('Y-m-d');
        if (1 === \count($told)) {
            $code = $told[0]['code'];
            $stage = $this->stage($code);
            $subject = self::SUBJECT[$stage][$language];
            $text = implode("\n\n", [
                \sprintf(self::OPENING[$stage][$language], $firm, $told[0]['customer'], $date),
                $this->reason($code, $language),
                self::CLOSING[$stage][$language],
            ]);
            // The same for every try of the day, so a repeat is recognisable.
            $reference = $told[0]['id'];
        } else {
            $onlySends = [] === array_filter($told, fn (array $one): bool => 'send' !== $this->stage($one['code']));
            $subject = \sprintf(($onlySends ? self::DIGEST_SEND_SUBJECT : self::DIGEST_SUBJECT)[$language], \count($told));
            $lines = array_map(fn (array $one): string => '- '.$one['customer'].': '.$this->reason($one['code'], $language), $told);
            $text = implode("\n\n", [
                \sprintf(self::DIGEST_OPENING[$language], $firm, $date),
                implode("\n", $lines),
                self::DIGEST_CLOSING[$language],
            ]);
            $reference = $firmId;
        }
        $from = $server->fromAddress();
        $domain = substr($from, (int) strrpos($from, '@') + 1);

        return new MailMessage(
            $from,
            $server->fromName(),
            null,
            [$to],
            [],
            $subject,
            $text,
            nl2br(htmlspecialchars($text, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'), false),
            \sprintf('<recurring-alert.%s.%s@%s>', $reference, $today->format('Ymd'), $domain),
        );
    }

    private function stage(string $code): string
    {
        return str_starts_with($code, 'send.') ? 'send' : 'issue';
    }

    private function reason(string $code, string $language): string
    {
        return isset(self::REASONS[$code]) ? self::REASONS[$code][$language] : \sprintf(self::OTHER_REASON[$language], $code);
    }
}
