<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

use App\Audit\Domain\Audited;
use App\Audit\Domain\AuditExcluded;
use App\Audit\Domain\AuditRooted;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Currency;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * One sending of a document by e-mail: what was sent, to whom, and how it went.
 * The PDF, the subject, the body and the recipients are frozen when the send is
 * asked for, so a later edit of the invoice changes nothing here; the worker only
 * delivers. The body keeps {link} as a token: the link is derived from the id when
 * the mail leaves, and the row holds only hashes of the links, never a link.
 *
 * The figures of the document are copied too, so the public page and the log
 * never read the invoice again. Read state is written by plain SQL, past the
 * audit log; the claim a worker takes while it talks to the mail server stays
 * out of the log too.
 */
#[ORM\Entity]
#[ORM\Table(name: 'invoice_send')]
#[ORM\Index(name: 'idx_invoice_send_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_invoice_send_invoice', columns: ['invoice_id'])]
#[ORM\Index(name: 'idx_invoice_send_status', columns: ['tenant_id', 'status', 'created_at'])]
#[ORM\UniqueConstraint(name: 'uniq_invoice_send_token', columns: ['token_hash'])]
#[ORM\UniqueConstraint(name: 'uniq_invoice_send_copy_token', columns: ['copy_token_hash'])]
#[ORM\UniqueConstraint(name: 'uniq_send_reminder_step', columns: ['tenant_id', 'invoice_id', 'reminder_step'])]
class InvoiceSend implements TenantOwned, Audited, AuditRooted
{
    /** To and Cc together, as iDoklad's own limit of other recipients plus the contact's addresses. */
    public const int MAX_RECIPIENTS = 15;
    public const int MAX_SUBJECT = 255;
    public const int MAX_BODY = 10000;
    public const int MAX_ERROR = 500;
    public const int MAX_CODE = 32;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    #[ORM\Column(type: 'string', length: 16, enumType: SendStatus::class)]
    private SendStatus $status = SendStatus::Queued;

    #[ORM\Column(type: 'string', length: 16, enumType: SendPurpose::class, options: ['default' => 'invoice'])]
    private SendPurpose $purpose = SendPurpose::Invoice;

    /** Which reminder of the invoice this is, from 1; null for any other send. Unique per invoice. */
    #[ORM\Column(name: 'reminder_step', type: 'smallint', nullable: true)]
    private ?int $reminderStep = null;

    #[ORM\Column(name: 'sent_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(name: 'failed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $failedAt = null;

    #[ORM\Column(name: 'cancelled_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\Column(type: 'integer')]
    private int $attempts = 0;

    #[ORM\Column(name: 'last_attempt_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastAttemptAt = null;

    #[ORM\Column(name: 'last_error', type: 'string', length: self::MAX_ERROR, nullable: true)]
    private ?string $lastError = null;

    /** A stable name of the last failure, which the screen translates; null on rows from before codes. */
    #[ORM\Column(name: 'last_error_code', type: 'string', length: self::MAX_CODE, nullable: true)]
    private ?string $lastErrorCode = null;

    /** The SMTP code of the last failure, when the server answered with one. */
    #[ORM\Column(name: 'last_error_smtp', type: 'smallint', nullable: true)]
    private ?int $lastErrorSmtp = null;

    /** Whether the last failure most likely needs a change of the e-mail settings. */
    #[ORM\Column(name: 'settings_problem', type: 'boolean')]
    private bool $settingsProblem = false;

    #[ORM\Column(name: 'message_id', type: 'string', length: 255, nullable: true)]
    private ?string $messageId = null;

    /** A worker talking to the mail server since then; null when none is. */
    #[AuditExcluded]
    #[ORM\Column(name: 'claimed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $claimedAt = null;

    #[ORM\Column(name: 'copy_sent_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $copySentAt = null;

    #[ORM\Column(name: 'copy_error', type: 'string', length: self::MAX_ERROR, nullable: true)]
    private ?string $copyError = null;

    #[ORM\Column(name: 'copy_error_code', type: 'string', length: self::MAX_CODE, nullable: true)]
    private ?string $copyErrorCode = null;

    #[ORM\Column(name: 'copy_error_smtp', type: 'smallint', nullable: true)]
    private ?int $copyErrorSmtp = null;

    #[ORM\Column(name: 'revoked_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    /** When RequeueStuckSends last handed it to the queue again, so it does not do so twice in a row. */
    #[AuditExcluded]
    #[ORM\Column(name: 'requeued_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $requeuedAt = null;

    #[AuditExcluded]
    #[ORM\Column(name: 'first_read_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $firstReadAt = null;

    #[AuditExcluded]
    #[ORM\Column(name: 'read_count', type: 'integer')]
    private int $readCount = 0;

    /**
     * @param list<string> $recipientsTo
     * @param list<string> $recipientsCc
     */
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        private readonly string $id,
        #[ORM\ManyToOne(targetEntity: Invoice::class)]
        #[ORM\JoinColumn(name: 'invoice_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly Invoice $invoice,
        #[ORM\Column(name: 'recipients_to', type: 'json')]
        private readonly array $recipientsTo,
        #[ORM\Column(name: 'recipients_cc', type: 'json')]
        private readonly array $recipientsCc,
        #[AuditExcluded]
        #[ORM\Column(name: 'copy_to', type: 'string', length: 254, nullable: true)]
        private readonly ?string $copyTo,
        #[AuditExcluded]
        #[ORM\Column(type: 'string', length: self::MAX_SUBJECT)]
        private readonly string $subject,
        #[AuditExcluded]
        #[ORM\Column(type: 'text')]
        private readonly string $body,
        #[ORM\Column(type: 'string', length: 2, enumType: DocumentLanguage::class, options: ['fixed' => true])]
        private readonly DocumentLanguage $language,
        #[ORM\Column(name: 'invoice_version', type: 'integer')]
        private readonly int $invoiceVersion,
        #[ORM\Column(name: 'created_by', type: 'guid', nullable: true)]
        private readonly ?string $createdBy,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private readonly \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'token_hash', type: 'string', length: 64, options: ['fixed' => true])]
        private readonly string $tokenHash,
        #[ORM\Column(name: 'copy_token_hash', type: 'string', length: 64, nullable: true, options: ['fixed' => true])]
        private readonly ?string $copyTokenHash,
        #[ORM\Column(name: 'pdf_key', type: 'string', length: 255)]
        private readonly string $pdfKey,
        #[ORM\Column(name: 'pdf_sha256', type: 'string', length: 64, options: ['fixed' => true])]
        private readonly string $pdfSha256,
        #[ORM\Column(name: 'document_number', type: 'string', length: Invoice::MAX_NUMBER_LENGTH)]
        private readonly string $documentNumber,
        #[ORM\Column(name: 'issuer_name', type: 'string', length: DocumentParty::MAX_NAME)]
        private readonly string $issuerName,
        #[ORM\Column(name: 'customer_name', type: 'string', length: DocumentParty::MAX_NAME)]
        private readonly string $customerName,
        #[ORM\Column(name: 'amount_minor', type: 'bigint')]
        private readonly int $amountMinor,
        #[ORM\Column(type: 'string', length: 3, enumType: Currency::class, options: ['fixed' => true])]
        private readonly Currency $currency,
        #[ORM\Column(name: 'due_date', type: 'date_immutable')]
        private readonly \DateTimeImmutable $dueDate,
        #[ORM\Column(name: 'payment_method', type: 'string', length: 32, enumType: PaymentMethod::class, options: ['default' => 'bank_transfer'])]
        private readonly PaymentMethod $paymentMethod,
        #[ORM\Column(name: 'bank_account', type: 'string', length: 34, nullable: true)]
        private readonly ?string $bankAccount,
        #[ORM\Column(name: 'domestic_account', type: 'string', length: 64, nullable: true)]
        private readonly ?string $domesticAccount,
        #[ORM\Column(name: 'variable_symbol', type: 'string', length: 10, options: ['default' => ''])]
        private readonly string $variableSymbol,
        #[ORM\Column(name: 'payment_code', type: 'text', nullable: true)]
        private readonly ?string $paymentCode,
    ) {
    }

    /**
     * A new send, waiting for the worker.
     *
     * @param list<string> $to              the mail is addressed to them
     * @param list<string> $cc
     * @param string|null  $copyTo          where the firm's own copy goes, with a link of its own
     * @param string|null  $domesticAccount the account as people of the issuer's country write it
     * @param string|null  $paymentCode     the payment QR code of the PDF, as text
     * @param int|null     $reminderStep    for a reminder, which one of the invoice, from 1
     *
     * @throws \InvalidArgumentException without a recipient, past the limit, with a value that could start a header,
     *                                   or a reminder without its step
     */
    public static function queue(
        string $id,
        Invoice $invoice,
        array $to,
        array $cc,
        ?string $copyTo,
        string $subject,
        string $body,
        ?string $createdBy,
        \DateTimeImmutable $createdAt,
        string $tokenHash,
        ?string $copyTokenHash,
        string $pdfKey,
        string $pdfSha256,
        ?string $domesticAccount = null,
        ?string $paymentCode = null,
        SendPurpose $purpose = SendPurpose::Invoice,
        ?int $reminderStep = null,
    ): self {
        if ([] === $to) {
            throw new \InvalidArgumentException('A send needs a recipient.');
        }
        if (\count($to) + \count($cc) > self::MAX_RECIPIENTS) {
            throw new \InvalidArgumentException('A send has too many recipients.');
        }
        foreach ([$subject, $copyTo ?? '', ...$to, ...$cc] as $header) {
            if (1 === preg_match('/[\x00-\x1F\x7F\x{85}\x{2028}\x{2029}]/u', $header)) {
                throw new \InvalidArgumentException('A header value cannot hold a line break or a control character.');
            }
        }
        if ('' === trim($subject) || mb_strlen($subject) > self::MAX_SUBJECT || mb_strlen($body) > self::MAX_BODY) {
            throw new \InvalidArgumentException('The subject is empty, or the subject or body is too long.');
        }
        if ((null === $copyTokenHash) !== (null === $copyTo)) {
            throw new \InvalidArgumentException('A copy has a link of its own, and only a copy has one.');
        }
        if (SendPurpose::Reminder === $purpose ? (null === $reminderStep || $reminderStep < 1 || $reminderStep > FirmReminders::MAX_STEPS) : null !== $reminderStep) {
            throw new \InvalidArgumentException(\sprintf('A reminder has a step from 1 to %d, and only a reminder has one.', FirmReminders::MAX_STEPS));
        }

        $send = new self(
            $id,
            $invoice,
            $to,
            $cc,
            $copyTo,
            $subject,
            $body,
            $invoice->language(),
            $invoice->version(),
            $createdBy,
            $createdAt,
            $tokenHash,
            $copyTokenHash,
            $pdfKey,
            $pdfSha256,
            $invoice->number(),
            $invoice->issuer()->name,
            $invoice->customer()->name,
            $invoice->payable()->minor(),
            $invoice->currency(),
            $invoice->dueDate(),
            $invoice->paymentMethod(),
            $invoice->bankAccount(),
            $domesticAccount,
            $invoice->variableSymbol(),
            $paymentCode,
        );
        $send->purpose = $purpose;
        $send->reminderStep = $reminderStep;

        return $send;
    }

    /**
     * Taken by a worker before it talks to the mail server, so a second delivery of
     * the same message leaves it alone meanwhile.
     */
    public function claim(\DateTimeImmutable $at): void
    {
        $this->claimedAt = $at;
    }

    /**
     * The mail server accepted the message. Recorded whatever the status was, since
     * it is what happened: a send cancelled while the server talked still went out.
     * The claim stays: the worker still has the invoice and the copy to see to.
     */
    public function markSent(\DateTimeImmutable $at, string $messageId): void
    {
        if (SendStatus::Sent === $this->status) {
            return;
        }
        $this->status = SendStatus::Sent;
        $this->sentAt = $at;
        $this->messageId = $messageId;
        $this->lastError = null;
        $this->lastErrorCode = null;
        $this->lastErrorSmtp = null;
        $this->settingsProblem = false;
    }

    /**
     * The worker is done with it.
     */
    public function release(): void
    {
        $this->claimedAt = null;
    }

    /**
     * A try that did not go through and may be tried again.
     */
    public function recordAttempt(\DateTimeImmutable $at, string $reason, bool $settingsProblem = false, ?string $code = null, ?int $smtp = null): void
    {
        ++$this->attempts;
        $this->lastAttemptAt = $at;
        $this->lastError = $this->shortened($reason);
        $this->lastErrorCode = null === $code ? null : mb_substr($code, 0, self::MAX_CODE);
        $this->lastErrorSmtp = $smtp;
        $this->settingsProblem = $settingsProblem;
        $this->claimedAt = null;
    }

    /**
     * Given up: refused for good, or out of tries. A send that went out stays sent.
     */
    public function fail(\DateTimeImmutable $at, string $reason, bool $settingsProblem = false, ?string $code = null, ?int $smtp = null): void
    {
        if (SendStatus::Queued !== $this->status) {
            return;
        }
        $this->status = SendStatus::Failed;
        $this->failedAt = $at;
        $this->lastError = $this->shortened($reason);
        $this->lastErrorCode = null === $code ? null : mb_substr($code, 0, self::MAX_CODE);
        $this->lastErrorSmtp = $smtp;
        $this->settingsProblem = $settingsProblem;
        $this->claimedAt = null;
    }

    /**
     * Stops a send that has not gone out yet; anything else stays as it is.
     */
    public function cancel(\DateTimeImmutable $at): void
    {
        if (SendStatus::Queued === $this->status) {
            $this->status = SendStatus::Cancelled;
            $this->cancelledAt = $at;
        }
    }

    /**
     * Ends both links for good. Repeatable: the first moment stays.
     */
    public function revoke(\DateTimeImmutable $at): void
    {
        $this->revokedAt ??= $at;
    }

    /**
     * The copy is about to leave. Until the server answers its outcome counts as
     * unknown, so a worker that dies meanwhile never leads to a second copy.
     */
    public function startCopy(string $unknown, ?string $code = null): void
    {
        $this->copyError = self::shortened($unknown);
        $this->copyErrorCode = $code;
        $this->copyErrorSmtp = null;
    }

    public function markCopySent(\DateTimeImmutable $at): void
    {
        $this->copySentAt = $at;
        $this->copyError = null;
        $this->copyErrorCode = null;
        $this->copyErrorSmtp = null;
        $this->claimedAt = null;
    }

    /**
     * The copy did not go; the send to the customer stands.
     */
    public function recordCopyFailure(string $reason, ?string $code = null, ?int $smtp = null): void
    {
        $this->copyError = $this->shortened($reason);
        $this->copyErrorCode = null === $code ? null : mb_substr($code, 0, self::MAX_CODE);
        $this->copyErrorSmtp = $smtp;
        $this->claimedAt = null;
    }

    /**
     * Sent to the customer, with a copy asked for that was neither sent nor refused.
     */
    public function isCopyOwed(): bool
    {
        return null !== $this->copyTo && !$this->copySentAt instanceof \DateTimeImmutable && null === $this->copyError;
    }

    public function auditRoot(): object
    {
        return $this->invoice;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function invoice(): Invoice
    {
        return $this->invoice;
    }

    public function status(): SendStatus
    {
        return $this->status;
    }

    public function purpose(): SendPurpose
    {
        return $this->purpose;
    }

    public function reminderStep(): ?int
    {
        return $this->reminderStep;
    }

    /**
     * @return list<string>
     */
    public function to(): array
    {
        return $this->recipientsTo;
    }

    /**
     * @return list<string>
     */
    public function cc(): array
    {
        return $this->recipientsCc;
    }

    public function copyTo(): ?string
    {
        return $this->copyTo;
    }

    public function subject(): string
    {
        return $this->subject;
    }

    /**
     * With {link} where the link goes, if the writer placed it.
     */
    public function body(): string
    {
        return $this->body;
    }

    public function language(): DocumentLanguage
    {
        return $this->language;
    }

    public function invoiceVersion(): int
    {
        return $this->invoiceVersion;
    }

    public function createdBy(): ?string
    {
        return $this->createdBy;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function sentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function failedAt(): ?\DateTimeImmutable
    {
        return $this->failedAt;
    }

    public function cancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function lastAttemptAt(): ?\DateTimeImmutable
    {
        return $this->lastAttemptAt;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function lastErrorCode(): ?string
    {
        return $this->lastErrorCode;
    }

    public function lastErrorSmtp(): ?int
    {
        return $this->lastErrorSmtp;
    }

    public function isSettingsProblem(): bool
    {
        return $this->settingsProblem;
    }

    public function messageId(): ?string
    {
        return $this->messageId;
    }

    public function claimedAt(): ?\DateTimeImmutable
    {
        return $this->claimedAt;
    }

    public function tokenHash(): string
    {
        return $this->tokenHash;
    }

    public function copyTokenHash(): ?string
    {
        return $this->copyTokenHash;
    }

    public function copySentAt(): ?\DateTimeImmutable
    {
        return $this->copySentAt;
    }

    public function copyError(): ?string
    {
        return $this->copyError;
    }

    public function copyErrorCode(): ?string
    {
        return $this->copyErrorCode;
    }

    public function copyErrorSmtp(): ?int
    {
        return $this->copyErrorSmtp;
    }

    public function revokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function firstReadAt(): ?\DateTimeImmutable
    {
        return $this->firstReadAt;
    }

    public function readCount(): int
    {
        return $this->readCount;
    }

    public function pdfKey(): string
    {
        return $this->pdfKey;
    }

    public function pdfSha256(): string
    {
        return $this->pdfSha256;
    }

    public function documentNumber(): string
    {
        return $this->documentNumber;
    }

    public function issuerName(): string
    {
        return $this->issuerName;
    }

    public function customerName(): string
    {
        return $this->customerName;
    }

    public function amountMinor(): int
    {
        return $this->amountMinor;
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    public function dueDate(): \DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function paymentMethod(): PaymentMethod
    {
        return $this->paymentMethod;
    }

    public function bankAccount(): ?string
    {
        return $this->bankAccount;
    }

    public function domesticAccount(): ?string
    {
        return $this->domesticAccount;
    }

    public function variableSymbol(): string
    {
        return $this->variableSymbol;
    }

    public function paymentCode(): ?string
    {
        return $this->paymentCode;
    }

    public function requeuedAt(): ?\DateTimeImmutable
    {
        return $this->requeuedAt;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The send has no tenant yet.');
    }

    private function shortened(string $reason): string
    {
        return mb_substr($reason, 0, self::MAX_ERROR);
    }
}
