<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Audit\Application\AuditActor;
use App\Identity\Application\UserRepository;
use App\Invoicing\Domain\FirmMailServer;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceSend;
use App\Invoicing\Domain\LinkAudience;
use App\Party\Application\TransactionalWork;
use App\Party\Domain\PartyEmail;
use App\Shared\Domain\Id;
use App\Tenancy\Application\TenantContext;
use Psr\Clock\ClockInterface;

/**
 * Queues an invoice for sending. Everything the customer will get is decided here,
 * in the request, so a refusal shows at once and the mail is what the dialog
 * showed: the PDF is drawn and kept, the variables are filled in, the recipients
 * are fixed. The worker only delivers.
 *
 * The PDF is drawn outside the transaction; inside, under the lock of the invoice,
 * the invoice must still be the one drawn and not in the trash, and the file and
 * the row are written. The message is queued after the commit, since a
 * synchronous transport would otherwise deliver inside the transaction.
 */
final readonly class SendInvoice
{
    private const int DRAWING_ROUNDS = 3;
    private const string CONTROL = '/[\x00-\x1F\x7F\x{85}\x{2028}\x{2029}]/u';

    public function __construct(
        private IssuedDocumentFactory $documents,
        private InvoiceStore $store,
        private InvoiceSends $sends,
        private MailServers $servers,
        private InvoicePdf $pdf,
        private SentFiles $files,
        private LinkTokens $tokens,
        private DocumentLinks $links,
        private SendLimits $limits,
        private InvoiceSendQueue $queue,
        private MailVariables $variables,
        private FirmInvoicingSettings $settings,
        private UserRepository $users,
        private AuditActor $actor,
        private TenantContext $context,
        private TransactionalWork $work,
        private ConflictRetry $retry,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return InvoiceSend the send as stored, queued
     *
     * @throws SendRefused    nothing is stored and nothing queued
     * @throws UnknownInvoice also for an invoice of another tenant
     */
    public function send(SendRequest $request): InvoiceSend
    {
        [$to, $cc] = $this->recipients($request);
        $this->checkText($request);
        if (!$this->links->allowed()) {
            throw new SendRefused(SendRefused::LOCAL_LINK_BASE);
        }
        $userId = $this->actor->userId();
        $limited = false;
        for ($round = 0; $round < self::DRAWING_ROUNDS; ++$round) {
            $document = $this->documents->of($request->invoiceId);
            $invoice = $this->store->get($request->invoiceId);
            if ($document->trashed) {
                throw new SendRefused(SendRefused::TRASHED);
            }
            $firm = $invoice->firm();
            if (!$this->servers->for($firm) instanceof FirmMailServer) {
                throw new SendRefused(SendRefused::NO_SERVER);
            }
            if (!$limited && !$this->limits->take($userId, $firm->id())) {
                throw new SendRefused(SendRefused::RATE_LIMITED);
            }
            $limited = true;
            try {
                $pdf = $this->pdf->render($document)->bytes;
            } catch (UndrawableDocument|UnsupportedCountry) {
                throw new SendRefused(SendRefused::UNDRAWABLE);
            }
            $user = null === $userId ? null : $this->users->findById($userId);
            $values = $this->variables->valuesFor($document, $user?->displayName() ?? $firm->name());
            $text = new MailText();
            $subject = $text->render($request->subject, $values, true);
            $body = $text->render($request->body, $values, false);
            // A long customer name can push a filled-in text past its limit.
            if ('' === $subject || mb_strlen($subject) > InvoiceSend::MAX_SUBJECT) {
                throw new SendRefused(SendRefused::BAD_SUBJECT);
            }
            if (mb_strlen($body) > InvoiceSend::MAX_BODY) {
                throw new SendRefused(SendRefused::BAD_BODY);
            }
            $contact = trim($this->settings->for($firm)->contactEmail());
            $copyTo = $request->copyToMe ? ('' !== $contact ? $contact : $user?->email()) : null;
            if (null !== $copyTo && !$this->isAddress($copyTo)) {
                $copyTo = null;
            }

            $send = $this->store($request->invoiceId, $invoice->version(), $to, $cc, $copyTo, $subject, $body, $userId, $pdf);
            if ($send instanceof InvoiceSend) {
                $this->queue->deliver($send->id());

                return $send;
            }
        }

        throw new \RuntimeException('The invoice kept changing while it was drawn for sending.');
    }

    /**
     * @param list<string> $to
     * @param list<string> $cc
     *
     * @return InvoiceSend|null null when the invoice changed since it was drawn, so it is drawn again
     */
    private function store(string $invoiceId, int $drawnVersion, array $to, array $cc, ?string $copyTo, string $subject, string $body, ?string $userId, string $pdf): ?InvoiceSend
    {
        $tenant = $this->context->current()->toString();
        $id = Id::generate();
        try {
            return $this->retry->run(fn (): ?InvoiceSend => $this->work->run(function () use ($invoiceId, $drawnVersion, $to, $cc, $copyTo, $subject, $body, $userId, $pdf, $tenant, $id): ?InvoiceSend {
                $invoice = $this->store->lock($invoiceId);
                if ($invoice->isTrashed()) {
                    throw new SendRefused(SendRefused::TRASHED);
                }
                if ($invoice->version() !== $drawnVersion) {
                    return null;
                }
                $key = $this->files->store($tenant, $id, $pdf);
                $send = $this->queued($id, $invoice, $to, $cc, $copyTo, $subject, $body, $userId, $key, hash('sha256', $pdf));
                $this->sends->add($send);

                return $send;
            }));
        } catch (\Throwable $failure) {
            // The file of a send that was never stored.
            $this->files->remove($tenant, $id);

            throw $failure;
        }
    }

    /**
     * @param list<string> $to
     * @param list<string> $cc
     */
    private function queued(string $id, Invoice $invoice, array $to, array $cc, ?string $copyTo, string $subject, string $body, ?string $userId, string $key, string $sha256): InvoiceSend
    {
        return InvoiceSend::queue(
            $id,
            $invoice,
            $to,
            $cc,
            $copyTo,
            $subject,
            $body,
            $userId,
            \DateTimeImmutable::createFromInterface($this->clock->now()),
            $this->tokens->hash($this->tokens->token($id, LinkAudience::Customer)),
            null === $copyTo ? null : $this->tokens->hash($this->tokens->token($id, LinkAudience::Copy)),
            $key,
            $sha256,
        );
    }

    /**
     * Blank entries are dropped and repeats kept once; an address in To is not
     * repeated in Cc.
     *
     * @return array{list<string>, list<string>}
     */
    private function recipients(SendRequest $request): array
    {
        $to = $this->addresses($request->to, []);
        $cc = $this->addresses($request->cc, $to);
        if ([] === $to) {
            throw new SendRefused(SendRefused::NO_RECIPIENT);
        }
        if (\count($to) + \count($cc) > InvoiceSend::MAX_RECIPIENTS) {
            throw new SendRefused(SendRefused::TOO_MANY_RECIPIENTS);
        }

        return [$to, $cc];
    }

    /**
     * @param list<string> $typed
     * @param list<string> $taken
     *
     * @return list<string>
     */
    private function addresses(array $typed, array $taken): array
    {
        $seen = array_map(mb_strtolower(...), $taken);
        $addresses = [];
        foreach ($typed as $address) {
            // Only spaces are trimmed: a line break must refuse, not vanish.
            $address = trim($address, ' ');
            if ('' === $address) {
                continue;
            }
            if (!$this->isAddress($address)) {
                throw new SendRefused(SendRefused::BAD_ADDRESS, [$address]);
            }
            if (!\in_array(mb_strtolower($address), $seen, true)) {
                $seen[] = mb_strtolower($address);
                $addresses[] = $address;
            }
        }

        return $addresses;
    }

    private function checkText(SendRequest $request): void
    {
        $subject = trim($request->subject, ' ');
        if ('' === $subject || mb_strlen($subject) > InvoiceSend::MAX_SUBJECT || 1 === preg_match(self::CONTROL, $subject) || str_contains($subject, '{'.MailVariables::LINK.'}')) {
            throw new SendRefused(SendRefused::BAD_SUBJECT);
        }
        if (mb_strlen($request->body) > InvoiceSend::MAX_BODY) {
            throw new SendRefused(SendRefused::BAD_BODY);
        }
        $unknown = $this->variables->unknownIn($request->subject.' '.$request->body);
        if ([] !== $unknown) {
            throw new SendRefused(SendRefused::UNKNOWN_VARIABLE, array_map(static fn (string $name): string => '{'.$name.'}', $unknown));
        }
    }

    private function isAddress(string $address): bool
    {
        return PartyEmail::isValid($address) && $address === trim($address) && 1 !== preg_match(self::CONTROL, $address);
    }
}
