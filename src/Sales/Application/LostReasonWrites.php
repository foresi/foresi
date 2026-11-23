<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Application;

use Foresi\Sales\Application\Api\ArchiveLostReasonInput;
use Foresi\Sales\Application\Api\CreateLostReasonInput;
use Foresi\Sales\Application\Api\UpdateLostReasonInput;
use Foresi\Sales\Domain\LostReason;

/**
 * The reasons a deal is lost for, under Settings → Sales. The reasons not
 * archived keep positions 0 to n-1: a new one comes last, and archiving one
 * closes the gap. The deals lost for an archived reason keep it.
 */
final readonly class LostReasonWrites
{
    public function __construct(
        private SalesAccess $access,
        private SalesStore $store,
        private SalesWork $work,
        private SalesClock $clock,
    ) {
    }

    /**
     * @throws SalesRefused
     */
    public function create(CreateLostReasonInput $input): LostReason
    {
        $this->access->denyUnlessStaff();

        return $this->work->run(function () use ($input): LostReason {
            if (null !== $input->key && $this->store->lostReasonByKey($input->key) instanceof LostReason) {
                throw SalesRefused::conflict('key_taken', 'Another lost reason has this key.', 'key');
            }
            $reason = SalesRefused::unless(fn (): LostReason => new LostReason($input->key, $input->name, \count($this->store->lostReasons()), $this->clock->now()));
            $this->store->add($reason);

            return $reason;
        });
    }

    /**
     * @throws SalesRefused
     */
    public function update(UpdateLostReasonInput $input): LostReason
    {
        $this->access->denyUnlessStaff();

        return $this->work->run(function () use ($input): LostReason {
            $reason = $this->reason($input->reasonId);
            SalesRefused::unless(static fn () => $reason->rename($input->name));

            return $reason;
        });
    }

    /**
     * Archives a reason; archiving it again changes nothing.
     *
     * @throws SalesRefused
     */
    public function archive(ArchiveLostReasonInput $input): LostReason
    {
        $this->access->denyUnlessStaff();

        return $this->work->run(function () use ($input): LostReason {
            $reason = $this->reason($input->reasonId);
            if ($reason->archive($this->clock->now())) {
                $position = 0;
                foreach ($this->store->lostReasons() as $kept) {
                    if ($kept !== $reason) {
                        $kept->moveTo($position++);
                    }
                }
            }

            return $reason;
        });
    }

    /**
     * @throws SalesRefused not_found
     */
    private function reason(string $id): LostReason
    {
        return $this->store->lostReason($id) ?? throw SalesRefused::notFound('There is no lost reason with this id. List them with foresi_list_deal_stages.');
    }
}
