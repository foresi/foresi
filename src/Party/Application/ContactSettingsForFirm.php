<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

use App\Party\Domain\EmailPurpose;
use App\Party\Domain\InvoicingDefaults;
use App\Party\Domain\InvoicingEmail;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PartyEmail;
use App\Party\Domain\PartyFirmSettings;
use App\Party\Domain\PartyRole;
use Psr\Clock\ClockInterface;

/**
 * What a contact is to a given firm: the firm's own value where it has one,
 * otherwise the value the firms share. Roles, invoicing defaults, invoicing
 * e-mails and the archive state can differ; anything else is always shared.
 * An archived contact is archived for all firms, whatever a firm has of its own.
 *
 * Changing a value belongs in a unit of work. Null takes a firm back to the
 * shared value.
 */
final readonly class ContactSettingsForFirm
{
    public function __construct(
        private FirmSettings $settings,
        private PartyDetails $details,
        private Parties $parties,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<PartyRole>
     */
    public function rolesFor(Party $party, LegalEntity $firm): array
    {
        return $this->settings->find($party, $firm)?->roles() ?? $party->roles();
    }

    public function defaultsFor(Party $party, LegalEntity $firm): InvoicingDefaults
    {
        return $this->settings->find($party, $firm)?->invoicingDefaults() ?? $party->invoicingDefaults();
    }

    /**
     * @return list<PartyEmail>
     */
    public function emailsFor(Party $party, LegalEntity $firm): array
    {
        $own = $this->settings->emails($party, $firm);

        return [] !== $own ? $own : $this->sharedEmails($party);
    }

    public function isArchivedFor(Party $party, LegalEntity $firm): bool
    {
        return $party->isArchived() || $this->hasOwnArchive($party, $firm);
    }

    public function hasOwnRoles(Party $party, LegalEntity $firm): bool
    {
        return null !== $this->settings->find($party, $firm)?->roles();
    }

    public function hasOwnDefaults(Party $party, LegalEntity $firm): bool
    {
        return $this->settings->find($party, $firm)?->invoicingDefaults() instanceof InvoicingDefaults;
    }

    public function hasOwnEmails(Party $party, LegalEntity $firm): bool
    {
        return [] !== $this->settings->emails($party, $firm);
    }

    public function hasOwnArchive(Party $party, LegalEntity $firm): bool
    {
        return $this->settings->find($party, $firm)?->archivedAt() instanceof \DateTimeImmutable;
    }

    /**
     * The shared invoicing e-mails; the general e-mail is not one of them.
     *
     * @return list<PartyEmail>
     */
    public function sharedEmails(Party $party): array
    {
        return array_values(array_filter($this->details->emails($party), static fn (PartyEmail $email): bool => EmailPurpose::General !== $email->purpose()));
    }

    /**
     * @param list<PartyRole>|null $roles
     *
     * @throws \DomainException for an archived firm, like every change here
     */
    public function changeRoles(Party $party, LegalEntity $firm, ?array $roles): void
    {
        $this->change($party, $firm, null !== $roles, static fn (PartyFirmSettings $row) => $row->changeRoles($roles));
    }

    public function changeDefaults(Party $party, LegalEntity $firm, ?InvoicingDefaults $defaults): void
    {
        $this->change($party, $firm, $defaults instanceof InvoicingDefaults, static fn (PartyFirmSettings $row) => $row->changeInvoicingDefaults($defaults));
    }

    /**
     * An empty list is no override: a firm has one when it has its own rows.
     *
     * @param list<InvoicingEmail>|null $emails
     */
    public function changeEmails(Party $party, LegalEntity $firm, ?array $emails): void
    {
        $this->refuseArchived($firm);
        $this->replaceEmails($this->settings->emails($party, $firm), $emails ?? [], $party, $firm);
    }

    public function changeArchived(Party $party, LegalEntity $firm, bool $archived): void
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $this->change($party, $firm, $archived, static fn (PartyFirmSettings $row) => $archived ? $row->archive($now) : $row->restore());
    }

    /**
     * @param list<InvoicingEmail> $emails
     */
    public function changeSharedEmails(Party $party, array $emails): void
    {
        $this->replaceEmails($this->sharedEmails($party), $emails, $party, null);
    }

    /**
     * Only a real change touches a row, so saving the form unchanged leaves no audit entry.
     *
     * @param list<PartyEmail>     $stored
     * @param list<InvoicingEmail> $wanted
     */
    private function replaceEmails(array $stored, array $wanted, Party $party, ?LegalEntity $firm): void
    {
        $missing = [];
        foreach ($wanted as $email) {
            $missing[$email->key()] = $email;
        }
        foreach ($stored as $email) {
            $key = InvoicingEmail::keyOf($email->address(), $email->purpose());
            if (isset($missing[$key])) {
                unset($missing[$key]);
            } else {
                $this->parties->removeEmail($email);
            }
        }
        foreach ($missing as $email) {
            $this->parties->addEmail(new PartyEmail($party, $email->address, $email->purpose, $firm));
        }
    }

    /**
     * @param \Closure(PartyFirmSettings): void $change
     */
    private function change(Party $party, LegalEntity $firm, bool $sets, \Closure $change): void
    {
        $this->refuseArchived($firm);
        $row = $this->settings->find($party, $firm);
        if (!$row instanceof PartyFirmSettings) {
            if (!$sets) {
                return;
            }
            $row = new PartyFirmSettings($party, $firm);
        }

        $change($row);
        if ($row->isEmpty()) {
            $this->settings->remove($row);
        } else {
            // Also brings back a row an earlier panel of the same save left empty.
            $this->settings->add($row);
        }
    }

    private function refuseArchived(LegalEntity $firm): void
    {
        if ($firm->isArchived()) {
            throw new \DomainException('An archived firm has no settings to change.');
        }
    }
}
