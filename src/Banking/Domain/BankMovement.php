<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Domain;

use App\Audit\Domain\Audited;
use App\Audit\Domain\AuditExcluded;
use App\Party\Domain\FirmBankAccount;
use App\Party\Domain\LegalEntity;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Id;
use App\Shared\Domain\Money;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * Money that moved on an account of the firm, as its bank told it. What the bank
 * said never changes; only where the movement stands does. A movement is never
 * deleted: unpairing takes its payment back and leaves it unmatched, blocked from
 * pairing by itself again, so nobody's undo is undone behind their back.
 *
 * The payment is named by its id only, as the payment names the movement: the
 * two modules stay apart. The fingerprint is a hash of what the bank said, so the
 * same movement told twice can be told apart from two equal ones. The fields the
 * bank gave have no setter but are not readonly: the row lock reloads the movement
 * into the same object.
 */
#[ORM\Entity]
#[ORM\Table(name: 'bank_movement')]
#[ORM\Index(name: 'idx_bank_movement_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_bank_movement_fingerprint', columns: ['fingerprint'])]
#[ORM\Index(name: 'idx_bank_movement_firm', columns: ['legal_entity_id'])]
#[ORM\Index(name: 'idx_bank_movement_account_booked', columns: ['firm_bank_account_id', 'booked_on'])]
#[ORM\Index(name: 'idx_bank_movement_message', columns: ['inbound_message_id'])]
#[ORM\Index(name: 'idx_bank_movement_payment', columns: ['payment_id'])]
class BankMovement implements TenantOwned, Audited
{
    public const int MAX_COUNTER_ACCOUNT = 64;
    public const int MAX_COUNTER_NAME = 255;
    public const int MAX_SYMBOL = 10;
    public const int MAX_MESSAGE = 500;
    public const int MAX_BANK_REFERENCE = 64;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    #[ORM\ManyToOne(targetEntity: LegalEntity::class)]
    #[ORM\JoinColumn(name: 'legal_entity_id', nullable: false, onDelete: 'RESTRICT')]
    private LegalEntity $firm;

    #[ORM\Column(name: 'amount_minor', type: 'bigint')]
    private int $amountMinor;

    #[ORM\Column(type: 'string', length: 3, enumType: Currency::class, options: ['fixed' => true])]
    private Currency $currency;

    #[ORM\Column(type: 'string', length: 32, enumType: MovementState::class)]
    private MovementState $state;

    #[ORM\Column(name: 'unmatched_reason', type: 'string', length: 32, nullable: true, enumType: UnmatchedReason::class)]
    private ?UnmatchedReason $unmatchedReason;

    #[ORM\Column(name: 'payment_id', type: 'guid', nullable: true)]
    private ?string $paymentId = null;

    #[ORM\Column(name: 'auto_pair_blocked', type: 'boolean')]
    private bool $autoPairBlocked = false;

    // Goes up with every change of where the movement stands; the row lock touches it.
    #[AuditExcluded]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    /**
     * @param string $fingerprint sha256 of what the bank said, in hex
     *
     * @throws \InvalidArgumentException for an amount that is not positive, a value past its column, or a
     *                                   state that needs a reason without one, or a reason with another state
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: FirmBankAccount::class)]
        #[ORM\JoinColumn(name: 'firm_bank_account_id', nullable: false, onDelete: 'RESTRICT')]
        private FirmBankAccount $account,
        #[ORM\Column(name: 'inbound_message_id', type: 'guid', nullable: true)]
        private ?string $inboundMessageId,
        #[ORM\Column(type: 'string', length: 16, enumType: MovementDirection::class)]
        private MovementDirection $direction,
        Money $amount,
        #[ORM\Column(name: 'booked_on', type: 'date_immutable')]
        private \DateTimeImmutable $bookedOn,
        #[ORM\Column(name: 'counter_account', type: 'string', length: self::MAX_COUNTER_ACCOUNT, nullable: true)]
        private ?string $counterAccount,
        #[ORM\Column(name: 'counter_iban', type: 'string', length: 34, nullable: true)]
        private ?string $counterIban,
        #[ORM\Column(name: 'counter_name', type: 'string', length: self::MAX_COUNTER_NAME, nullable: true)]
        private ?string $counterName,
        #[ORM\Column(name: 'variable_symbol', type: 'string', length: self::MAX_SYMBOL, nullable: true)]
        private ?string $variableSymbol,
        #[ORM\Column(name: 'constant_symbol', type: 'string', length: self::MAX_SYMBOL, nullable: true)]
        private ?string $constantSymbol,
        #[ORM\Column(name: 'specific_symbol', type: 'string', length: self::MAX_SYMBOL, nullable: true)]
        private ?string $specificSymbol,
        #[ORM\Column(type: 'string', length: self::MAX_MESSAGE, nullable: true)]
        private ?string $message,
        #[ORM\Column(name: 'bank_reference', type: 'string', length: self::MAX_BANK_REFERENCE, nullable: true)]
        private ?string $bankReference,
        #[ORM\Column(type: 'string', length: 64, options: ['fixed' => true])]
        private string $fingerprint,
        #[ORM\Column(type: 'string', length: 16, enumType: MessageTrust::class)]
        private MessageTrust $trust,
        #[ORM\Column(name: 'trust_basis', type: 'string', length: 32)]
        private string $trustBasis,
        MovementState $state,
        ?UnmatchedReason $unmatchedReason = null,
    ) {
        if ($amount->isZero() || $amount->isNegative()) {
            throw new \InvalidArgumentException('A movement amount is positive; the direction carries the sign.');
        }
        if (MovementState::Paired === $state) {
            throw new \InvalidArgumentException('A movement is paired by pairWith(), never recorded so.');
        }
        foreach ([[$counterAccount, self::MAX_COUNTER_ACCOUNT], [$counterIban, 34], [$counterName, self::MAX_COUNTER_NAME], [$variableSymbol, self::MAX_SYMBOL], [$constantSymbol, self::MAX_SYMBOL], [$specificSymbol, self::MAX_SYMBOL], [$message, self::MAX_MESSAGE], [$bankReference, self::MAX_BANK_REFERENCE]] as [$value, $max]) {
            if (null !== $value && mb_strlen($value) > $max) {
                throw new \InvalidArgumentException('A value of the movement is longer than its column.');
            }
        }
        if (1 !== preg_match('/^[0-9a-f]{64}$/D', $fingerprint)) {
            throw new \InvalidArgumentException('A fingerprint is a sha256 in lowercase hex.');
        }
        $this->assertReason($state, $unmatchedReason);
        $this->id = Id::generate();
        $this->firm = $account->firm();
        $this->amountMinor = $amount->minor();
        $this->currency = $amount->currency();
        $this->state = $state;
        $this->unmatchedReason = $unmatchedReason;
    }

    /**
     * The payment came from this movement: a payment received for an incoming one,
     * a refund for an outgoing one, which is recorded only until then.
     *
     * @throws \LogicException when it is not an unmatched incoming movement or a recorded outgoing one
     */
    public function pairWith(string $paymentId): void
    {
        if (MovementState::Unmatched !== $this->state && (MovementState::Recorded !== $this->state || $this->isIncoming())) {
            throw new \LogicException('Only an unmatched movement is paired.');
        }
        $this->state = MovementState::Paired;
        $this->unmatchedReason = null;
        $this->paymentId = $paymentId;
        ++$this->version;
    }

    /**
     * A new look found why it stays unmatched.
     *
     * @throws \LogicException when it is not an unmatched movement
     */
    public function stayUnmatched(UnmatchedReason $reason): void
    {
        if (MovementState::Unmatched !== $this->state) {
            throw new \LogicException('Only an unmatched movement has a reason to stay so.');
        }
        if ($reason === $this->unmatchedReason) {
            return;
        }
        $this->unmatchedReason = $reason;
        ++$this->version;
    }

    /**
     * Its payment was cancelled: it is unmatched again and never paired by itself.
     * An outgoing one, whose refund was cancelled, is recorded only again.
     * Releasing a movement that is not paired changes nothing.
     */
    public function release(): void
    {
        if (MovementState::Paired !== $this->state) {
            return;
        }
        if (!$this->isIncoming()) {
            $this->state = MovementState::Recorded;
            $this->unmatchedReason = null;
            $this->paymentId = null;
            ++$this->version;

            return;
        }
        $this->state = MovementState::Unmatched;
        $this->unmatchedReason = UnmatchedReason::Blocked;
        $this->paymentId = null;
        $this->autoPairBlocked = true;
        ++$this->version;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function firm(): LegalEntity
    {
        return $this->firm;
    }

    public function account(): FirmBankAccount
    {
        return $this->account;
    }

    public function inboundMessageId(): ?string
    {
        return $this->inboundMessageId;
    }

    public function direction(): MovementDirection
    {
        return $this->direction;
    }

    public function isIncoming(): bool
    {
        return MovementDirection::Incoming === $this->direction;
    }

    public function amount(): Money
    {
        return Money::ofMinor($this->amountMinor, $this->currency);
    }

    public function bookedOn(): \DateTimeImmutable
    {
        return $this->bookedOn;
    }

    public function counterAccount(): ?string
    {
        return $this->counterAccount;
    }

    public function counterIban(): ?string
    {
        return $this->counterIban;
    }

    public function counterName(): ?string
    {
        return $this->counterName;
    }

    public function variableSymbol(): ?string
    {
        return $this->variableSymbol;
    }

    public function constantSymbol(): ?string
    {
        return $this->constantSymbol;
    }

    public function specificSymbol(): ?string
    {
        return $this->specificSymbol;
    }

    public function message(): ?string
    {
        return $this->message;
    }

    public function bankReference(): ?string
    {
        return $this->bankReference;
    }

    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    public function trust(): MessageTrust
    {
        return $this->trust;
    }

    public function trustBasis(): string
    {
        return $this->trustBasis;
    }

    public function state(): MovementState
    {
        return $this->state;
    }

    public function unmatchedReason(): ?UnmatchedReason
    {
        return $this->unmatchedReason;
    }

    public function paymentId(): ?string
    {
        return $this->paymentId;
    }

    public function isAutoPairBlocked(): bool
    {
        return $this->autoPairBlocked;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }

    private function assertReason(MovementState $state, ?UnmatchedReason $reason): void
    {
        if ((MovementState::Unmatched === $state) !== ($reason instanceof UnmatchedReason)) {
            throw new \InvalidArgumentException('An unmatched movement has a reason, and only an unmatched one does.');
        }
    }
}
