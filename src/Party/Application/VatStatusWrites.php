<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application;

use Foresi\Instance\Application\InstanceClock;
use Foresi\Party\Contract\Event\FirmVatStatusChanged;
use Foresi\Party\Contract\VatPeriodLength;
use Foresi\Party\Contract\VatStretch;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Party\Domain\VatStatusPeriod;
use Foresi\Shared\Application\ActorRole;
use Foresi\Shared\Application\Event\EventRecorder;

/**
 * Adds and removes the VAT statuses of the firm, for its owner only, as the
 * firm settings page. After every change the firm keeps the status that holds
 * today in its own column, which the code that issues documents reads; the
 * daily RefreshTodaysVatStatus moves it on when a dated status begins.
 *
 * A firm stored without any status (see VatStatusReads) gets its kept status as
 * the undated one first, so a change never loses it.
 */
final readonly class VatStatusWrites
{
    public function __construct(
        private VatStatusPeriods $periods,
        private InstanceFirm $firm,
        private TransactionalWork $transaction,
        private ActorRole $role,
        private EventRecorder $events,
        private InstanceClock $clock,
    ) {
    }

    /**
     * @throws VatStatusRefused
     */
    public function add(AddVatStatusInput $input): VatStretch
    {
        $this->denyUnlessOwner();
        $status = VatStatus::tryFrom(trim($input->status)) ?? throw VatStatusRefused::invalid('status_invalid', 'status', 'A status is registered, not_registered or identified_person.');
        $length = $this->length($input->periodLength);
        if (VatStatus::Registered === $status && !$length instanceof VatPeriodLength) {
            throw VatStatusRefused::invalid('period_length_required', 'periodLength', 'A registered payer needs a period length, month or quarter.');
        }
        if (VatStatus::Registered !== $status && $length instanceof VatPeriodLength) {
            throw VatStatusRefused::invalid('period_length_not_allowed', 'periodLength', 'Only a registered payer has a period length.');
        }
        $from = $this->day($input->validFrom);

        return $this->transaction->run(function () use ($status, $length, $from): VatStretch {
            $firm = $this->lockedFirm();
            $stored = $this->stored($firm);
            foreach ($stored as $period) {
                if ($period->validFrom()?->format('Y-m-d') === $from->format('Y-m-d')) {
                    throw VatStatusRefused::conflict('same_day', 'A VAT status of the firm already starts on this day. Remove it first with foresi_remove_firm_vat_status, then add the new one.');
                }
            }
            $added = new VatStatusPeriod($status, $length, $from);
            $this->hold($firm, [...$stored, $added]);
            $this->periods->add($added);
            $this->record(FirmVatStatusChanged::ADDED, $added);

            return $added->stretch();
        });
    }

    /**
     * @return string the id of the removed status
     *
     * @throws VatStatusRefused
     */
    public function remove(RemoveVatStatusInput $input): string
    {
        $this->denyUnlessOwner();
        $id = trim($input->vatStatusId);

        return $this->transaction->run(function () use ($id): string {
            $firm = $this->lockedFirm();
            $stored = $this->stored($firm);
            $removed = null;
            foreach ($stored as $period) {
                // The undated status made just now from the kept one was shown under the firm's id.
                if ($period->id() === $id || (1 === \count($stored) && $id === $firm->id())) {
                    $removed = $period;
                }
            }
            if (!$removed instanceof VatStatusPeriod) {
                throw VatStatusRefused::notFound('The firm has no VAT status with this id. Find it in vat_statuses of foresi_get_firm.');
            }
            if (1 === \count($stored)) {
                throw VatStatusRefused::conflict('last_stretch', 'The firm keeps at least one VAT status: add the new one first, then remove this one.');
            }
            $this->hold($firm, array_values(array_filter($stored, static fn (VatStatusPeriod $period): bool => $period !== $removed)));
            $removedId = $removed->id();
            $this->periods->remove($removed);
            $this->record(FirmVatStatusChanged::REMOVED, $removed);

            return $removedId;
        });
    }

    /**
     * The first VAT status of a firm being set up: its status from the day, or
     * from the start without one. Call it inside the transaction that creates
     * the firm, which checked the VAT ID already.
     */
    public function start(LegalEntity $firm, ?\DateTimeImmutable $from): void
    {
        $status = $firm->vatStatus();
        $period = new VatStatusPeriod($status, VatStatus::Registered === $status ? VatPeriodLength::Month : null, $from);
        $this->periods->add($period);
        $this->record(FirmVatStatusChanged::ADDED, $period);
    }

    /**
     * Keeps the status that holds today on the firm: the daily job, from
     * midnight in the instance's zone.
     *
     * @return bool whether the firm's status changed
     */
    public function refresh(): bool
    {
        return $this->transaction->run(function (): bool {
            if (!$this->firm->find() instanceof LegalEntity) {
                return false;
            }
            $stored = $this->periods->all();
            if ([] === $stored) {
                return false;
            }
            $firm = $this->firm->lock();
            $today = $this->today($stored);
            if ($today === $firm->vatStatus()) {
                return false;
            }
            $firm->holdVatStatus($today);

            return true;
        });
    }

    /**
     * @param list<VatStatusPeriod> $periods
     *
     * @throws VatStatusRefused vat_id_missing when the status of today needs a VAT ID the firm lacks
     */
    private function hold(LegalEntity $firm, array $periods): void
    {
        $today = $this->today($periods);
        if ($today->requiresVatId() && !$firm->vatId() instanceof VatId) {
            throw VatStatusRefused::conflict('vat_id_missing', 'The status of today would need a VAT ID, and the firm has none: add it on the firm first.');
        }
        $firm->holdVatStatus($today);
    }

    /**
     * The status that holds today; before the first dated one begins, that first one.
     *
     * @param list<VatStatusPeriod> $periods not empty
     */
    private function today(array $periods): VatStatus
    {
        usort($periods, static fn (VatStatusPeriod $a, VatStatusPeriod $b): int => ($a->validFrom()?->format('Y-m-d') ?? '') <=> ($b->validFrom()?->format('Y-m-d') ?? ''));
        $stretches = array_map(static fn (VatStatusPeriod $period): VatStretch => $period->stretch(), $periods);

        return VatStatus::ofRegistration((VatStatusReads::holding($stretches, $this->clock->today()) ?? $stretches[0])->status);
    }

    /**
     * The stored statuses, with the kept one stored first when there are none.
     *
     * @return list<VatStatusPeriod>
     */
    private function stored(LegalEntity $firm): array
    {
        $stored = $this->periods->all();
        if ([] !== $stored) {
            return $stored;
        }
        $status = $firm->vatStatus();
        $kept = new VatStatusPeriod($status, VatStatus::Registered === $status ? VatPeriodLength::Month : null, null);
        $this->periods->add($kept);

        return [$kept];
    }

    private function lockedFirm(): LegalEntity
    {
        try {
            return $this->firm->lock();
        } catch (NoFirmYet) {
            throw VatStatusRefused::notFound('The instance has no firm yet: its first-run setup has not happened.');
        }
    }

    private function record(string $what, VatStatusPeriod $period): void
    {
        $this->events->record(new FirmVatStatusChanged($what, $period->status()->value, $period->periodLength()?->value, $period->validFrom()?->format('Y-m-d')));
    }

    private function length(?string $value): ?VatPeriodLength
    {
        $value = trim($value ?? '');
        if ('' === $value) {
            return null;
        }

        return VatPeriodLength::tryFrom($value) ?? throw VatStatusRefused::invalid('period_length_invalid', 'periodLength', 'A period length is month or quarter.');
    }

    private function day(string $value): \DateTimeImmutable
    {
        $value = trim($value);
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (false === $day || $day->format('Y-m-d') !== $value) {
            throw VatStatusRefused::invalid('date_invalid', 'validFrom', 'A day is written in ISO 8601, such as 2026-11-12.');
        }

        return $day;
    }

    private function denyUnlessOwner(): void
    {
        if (!$this->role->isOwner()) {
            throw VatStatusRefused::ownerOnly();
        }
    }
}
