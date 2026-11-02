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
    public function __construct(
        private RecurringInvoices $templates,
        private MailServers $servers,
        private MailGateway $gateway,
        private FirmInvoicingSettings $settings,
        private UserRepository $users,
        private StaffCheck $staff,
        private RecurringAlertTexts $texts,
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
    private function message(string $firm, string $firmId, DocumentLanguage $language, array $told, \DateTimeImmutable $today, FirmMailServer $server, string $to): MailMessage
    {
        ['subject' => $subject, 'text' => $text] = $this->texts->compose($firm, $language, array_map(static fn (array $one): array => ['customer' => $one['customer'], 'code' => $one['code']], $told), $today);
        // The same for every try of the day, so a repeat is recognisable.
        $reference = 1 === \count($told) ? $told[0]['id'] : $firmId;
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
}
