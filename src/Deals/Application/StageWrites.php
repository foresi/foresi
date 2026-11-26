<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Application;

use Foresi\Deals\Application\Api\ArchiveDealStageInput;
use Foresi\Deals\Application\Api\CreateDealStageInput;
use Foresi\Deals\Application\Api\ReorderDealStagesInput;
use Foresi\Deals\Application\Api\UpdateDealStageInput;
use Foresi\Deals\Domain\Stage;

/**
 * The stages deals go through, under Settings → Sales. The stages not
 * archived keep positions 0 to n-1: a new one comes last, and archiving one
 * closes the gap. A stage that still holds open deals is not archived.
 */
final readonly class StageWrites
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
    public function create(CreateDealStageInput $input): Stage
    {
        $this->access->denyUnlessStaff();

        return $this->work->run(function () use ($input): Stage {
            if (null !== $input->key && $this->store->stageByKey($input->key) instanceof Stage) {
                throw SalesRefused::conflict('key_taken', 'Another stage has this key.', 'key');
            }
            $stage = SalesRefused::unless(fn (): Stage => new Stage($input->key, $input->name, \count($this->store->stages()), $this->clock->now()));
            $this->store->add($stage);

            return $stage;
        });
    }

    /**
     * @throws SalesRefused
     */
    public function update(UpdateDealStageInput $input): Stage
    {
        $this->access->denyUnlessStaff();

        return $this->work->run(function () use ($input): Stage {
            $stage = $this->stage($input->stageId);
            SalesRefused::unless(static fn () => $stage->rename($input->name));

            return $stage;
        });
    }

    /**
     * @return list<Stage> the stages not archived, in their new order
     *
     * @throws SalesRefused
     */
    public function reorder(ReorderDealStagesInput $input): array
    {
        $this->access->denyUnlessStaff();

        return $this->work->run(function () use ($input): array {
            $stages = DenseOrder::of($this->store->stages(), $input->stageIds, 'stageIds', 'Name every stage that is not archived once, in the new order.');
            foreach ($stages as $position => $stage) {
                $stage->moveTo($position);
            }

            return $stages;
        });
    }

    /**
     * Archives a stage that holds no open deal; archiving it again changes nothing.
     *
     * @throws SalesRefused
     */
    public function archive(ArchiveDealStageInput $input): Stage
    {
        $this->access->denyUnlessStaff();

        return $this->work->run(function () use ($input): Stage {
            $stage = $this->stage($input->stageId);
            if ($stage->isArchived()) {
                return $stage;
            }
            if ($this->store->openDealsIn($stage->id()) > 0) {
                throw SalesRefused::conflict('stage_has_open_deals', 'Open deals sit in this stage; move them to another stage first.');
            }
            $stage->archive($this->clock->now());
            $position = 0;
            foreach ($this->store->stages() as $kept) {
                if ($kept !== $stage) {
                    $kept->moveTo($position++);
                }
            }

            return $stage;
        });
    }

    /**
     * @throws SalesRefused not_found
     */
    private function stage(string $id): Stage
    {
        return $this->store->stage($id) ?? throw SalesRefused::notFound('There is no stage with this id. List them with foresi_list_deal_stages.');
    }
}
