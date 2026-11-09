<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Audit\Application\AuditActor;
use App\Identity\Application\People;
use App\Identity\Domain\Membership;
use App\Shared\Domain\Id;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Work\Domain\EmailDelivery;
use App\Work\Domain\NotificationKind;
use App\Work\Domain\NotificationPreference;

/**
 * The signed-in person's own choice of e-mails about their notifications, a
 * guest's too; and the link in an e-mail that stops one kind of them, which
 * works without signing in, as its signature says whose it is.
 */
final readonly class ManageNotificationPreferences
{
    /** What the link at the foot of a digest stops: every kind that comes in it. */
    public const string DIGEST = 'digest';

    public function __construct(
        private NotificationPreferences $preferences,
        private NotificationMailing $mailing,
        private People $people,
        private AuditActor $actor,
        private TenantContext $context,
        private WorkUnit $unit,
    ) {
    }

    /**
     * @throws WorkRefused when nobody of the organisation is signed in
     */
    public function mine(): NotificationPreference
    {
        return $this->mailing->preferenceOf($this->me());
    }

    /**
     * @param array<string, EmailDelivery> $deliveries by the value of the kind; a kind left out keeps its choice
     *
     * @throws WorkRefused      when nobody of the organisation is signed in
     * @throws \DomainException when a kind cannot be mailed at once or the hour is not 0 to 23
     * @throws \ValueError      when a key is no kind
     */
    public function save(array $deliveries, int $digestHour, bool $noEmail): void
    {
        $me = $this->me();
        $preference = $this->preferences->of($me);
        $new = !$preference instanceof NotificationPreference;
        $preference ??= new NotificationPreference($me);
        foreach ($deliveries as $kind => $delivery) {
            $preference->change(NotificationKind::from($kind), $delivery);
        }
        $preference->changeDigestHour($digestHour);
        $preference->stopEmails($noEmail);
        $this->unit->run(function () use ($new, $preference): void {
            if ($new) {
                $this->preferences->add($preference);
            }
        });
    }

    /**
     * Stops a kind of e-mail, or the digest, for the person of the signed
     * link, in their organisation.
     *
     * @param string $what the value of a kind, or DIGEST
     *
     * @return bool whether the person and the kind were found
     */
    public function switchOff(TenantId $tenant, string $membershipId, string $what): bool
    {
        $kind = NotificationKind::tryFrom($what);
        if (!Id::isValid($membershipId) || (!$kind instanceof NotificationKind && self::DIGEST !== $what)) {
            return false;
        }
        $previous = $this->context->has() ? $this->context->current() : null;
        if ($previous instanceof TenantId && $previous->equals($tenant)) {
            return $this->turnOff($membershipId, $kind);
        }
        $this->context->switchTo($tenant);
        try {
            return $this->turnOff($membershipId, $kind);
        } finally {
            if ($previous instanceof TenantId) {
                $this->context->switchTo($previous);
            } else {
                $this->context->reset();
            }
        }
    }

    /**
     * @param NotificationKind|null $kind null for the digest
     */
    private function turnOff(string $membershipId, ?NotificationKind $kind): bool
    {
        $membership = $this->people->membership($membershipId);
        if (!$membership instanceof Membership) {
            return false;
        }
        $preference = $this->preferences->of($membership);
        $new = !$preference instanceof NotificationPreference;
        $preference ??= new NotificationPreference($membership);
        if ($kind instanceof NotificationKind) {
            $preference->change($kind, EmailDelivery::Off);
        } else {
            $preference->stopDigest();
        }
        $this->unit->run(function () use ($new, $preference): void {
            if ($new) {
                $this->preferences->add($preference);
            }
        });

        return true;
    }

    /**
     * @throws WorkRefused
     */
    private function me(): Membership
    {
        $userId = $this->actor->userId();
        $me = null === $userId ? null : $this->people->membershipOf($userId);

        return $me ?? throw new WorkRefused('not_allowed');
    }
}
