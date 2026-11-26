<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Application;

use Foresi\Catalog\Contract\BillingKind;
use Foresi\Catalog\Contract\Products;
use Foresi\Catalog\Contract\UnknownProduct;
use Foresi\Sales\Application\Api\AddDealLineInput;
use Foresi\Sales\Application\Api\RemoveDealLineInput;
use Foresi\Sales\Application\Api\ReorderDealLinesInput;
use Foresi\Sales\Application\Api\UpdateDealLineInput;
use Foresi\Sales\Contract\Event\DealUpdated;
use Foresi\Sales\Domain\Deal;
use Foresi\Sales\Domain\DealLine;
use Foresi\Sales\Domain\DealLineTerms;
use Foresi\Shared\Contract\Event\EventRecorder;
use Foresi\Shared\Contract\Operation\Omitted;
use Foresi\Shared\Domain\Money;

/**
 * The lines of a deal, kept in positions 0 to n-1. A line from a product
 * copies what the product says now (Foresi\Catalog\Contract\ProductSnapshot);
 * a typed line says it itself, by the catalog's rules. Each change records
 * sales.deal.updated for its deal.
 */
final readonly class DealLineWrites
{
    public function __construct(
        private SalesAccess $access,
        private SalesStore $store,
        private SalesWork $work,
        private EventRecorder $events,
        private SalesClock $clock,
        private DealLineRules $rules,
        private Products $products,
        private LineProjects $projects,
    ) {
    }

    /**
     * @throws SalesRefused
     */
    public function add(AddDealLineInput $input): DealLine
    {
        $this->access->denyUnlessStaff();

        return $this->work->run(function () use ($input): DealLine {
            $deal = $this->deal($input->dealId);
            $terms = null === $input->productId ? $this->typed($input) : $this->fromProduct($input->productId, $input);
            $line = new DealLine($deal->id(), \count($this->store->linesOf($deal->id())), $input->productId, $terms);
            $line->linkProject($this->project($deal, null, $terms->billingKind, $input->projectId));
            $this->store->add($line);
            $this->updated($deal);

            return $line;
        });
    }

    /**
     * @throws SalesRefused
     */
    public function update(UpdateDealLineInput $input): DealLine
    {
        $this->access->denyUnlessStaff();

        return $this->work->run(function () use ($input): DealLine {
            $deal = $this->deal($input->dealId);
            $line = $this->line($deal, $input->lineId);
            $kind = $this->given($input->billingKind, $line->billingKind()->value);
            // A line made one-off or time-based keeps no recurrence, unless the input sends one, which is refused.
            $recurs = BillingKind::Recurring->value === $kind;
            $price = $line->unitPrice();
            $terms = $this->rules->terms(new DealLineDraft(
                $this->given($input->name, $line->name()),
                $this->given($input->unit, $line->unit()),
                $this->given($input->quantity, $line->quantity()),
                $this->given($input->unitPrice, $price instanceof Money ? $this->decimal($price->minor()) : null),
                $this->given($input->currency, $price?->currency()->value ?? 'CZK'),
                $kind,
                $this->given($input->recurrenceUnit, $recurs ? $line->recurrenceUnit()?->value : null),
                $this->given($input->interval, $recurs ? $line->interval() : null),
                $this->given($input->cycles, $recurs ? $line->cycles() : null),
            ));
            // Checked before anything changes, so a refusal leaves the line as it was.
            $projectId = $this->project($deal, $line, $terms->billingKind, $this->given($input->projectId, $line->projectId()));
            $line->change($terms);
            $line->linkProject($projectId);
            $this->updated($deal);

            return $line;
        });
    }

    /**
     * Removes a line; the lines after it move up.
     *
     * @throws SalesRefused
     */
    public function remove(RemoveDealLineInput $input): Deal
    {
        $this->access->denyUnlessStaff();

        return $this->work->run(function () use ($input): Deal {
            $deal = $this->deal($input->dealId);
            $this->store->remove($this->line($deal, $input->lineId));
            foreach ($this->store->linesOf($deal->id()) as $position => $line) {
                $line->moveTo($position);
            }
            $this->updated($deal);

            return $deal;
        });
    }

    /**
     * @throws SalesRefused
     */
    public function reorder(ReorderDealLinesInput $input): Deal
    {
        $this->access->denyUnlessStaff();

        return $this->work->run(function () use ($input): Deal {
            $deal = $this->deal($input->dealId);
            $lines = DenseOrder::of($this->store->linesOf($deal->id()), $input->lineIds, 'lineIds', 'Name every line of the deal once, in the new order.');
            foreach ($lines as $position => $line) {
                $line->moveTo($position);
            }
            $this->updated($deal);

            return $deal;
        });
    }

    /**
     * @throws SalesRefused invalid
     */
    private function typed(AddDealLineInput $input): DealLineTerms
    {
        $kind = $input->billingKind ?? BillingKind::OneOff->value;

        return $this->rules->terms(new DealLineDraft(
            $input->name ?? '',
            $input->unit ?? '',
            // A line of any other kind than time_based that gives no quantity is one unit.
            $input->quantity ?? (BillingKind::TimeBased->value === $kind ? null : '1'),
            $input->unitPrice,
            $input->currency ?? 'CZK',
            $kind,
            $input->recurrenceUnit,
            $input->interval,
            $input->cycles,
        ));
    }

    /**
     * What the product says now, with the quantity of the input.
     *
     * @throws SalesRefused invalid
     */
    private function fromProduct(string $productId, AddDealLineInput $input): DealLineTerms
    {
        // What a line from a product takes from the product, not from the input.
        $copied = ['name' => $input->name, 'unit' => $input->unit, 'unitPrice' => $input->unitPrice, 'currency' => $input->currency, 'billingKind' => $input->billingKind, 'recurrenceUnit' => $input->recurrenceUnit, 'interval' => $input->interval, 'cycles' => $input->cycles];
        foreach ($copied as $field => $value) {
            if (null !== $value) {
                throw SalesRefused::invalid('product_line_typed', $field, 'A line from a product copies the product; give only its quantity, and change the line afterwards with foresi_update_deal_line.');
            }
        }
        try {
            $product = $this->products->get($productId);
        } catch (UnknownProduct) {
            throw SalesRefused::invalid('product_unknown', 'productId', 'The catalog has no product with this id. Find it with foresi_search_products.');
        }
        if ($product->archived) {
            throw SalesRefused::invalid('product_archived', 'productId', 'The product is archived and is not offered for new lines.');
        }

        return new DealLineTerms($product->name, $product->unit, $this->rules->quantityOf($product->billingKind, $input->quantity ?? (BillingKind::TimeBased === $product->billingKind ? null : '1')), $product->unitPrice, $product->billingKind, $product->recurrenceUnit, $product->interval, $product->cycles);
    }

    /**
     * The project a line of this kind may link to: only an hourly line has
     * one. A project newly linked is one the person sees, not archived, of the
     * deal's client or of no client, and not linked to another line; the
     * project a line already has is kept as it is.
     *
     * @throws SalesRefused invalid, or conflict when another line has the project
     */
    private function project(Deal $deal, ?DealLine $line, BillingKind $kind, ?string $projectId): ?string
    {
        if (null === $projectId) {
            return null;
        }
        if (BillingKind::TimeBased !== $kind) {
            throw SalesRefused::invalid('project_only_on_time_based', 'projectId', 'Only a time_based line links to a project; unlink it with project_id null first.');
        }
        if ($line?->projectId() === $projectId) {
            return $projectId;
        }
        $project = $this->projects->find($projectId) ?? throw SalesRefused::invalid('project_unknown', 'projectId', 'There is no project with this id. Find it with foresi_search_projects.');
        if ($project->archived) {
            throw SalesRefused::invalid('project_archived', 'projectId', 'The project is archived; restore it first to link it.');
        }
        if (null !== $project->clientId && $project->clientId !== $deal->clientPartyId()) {
            throw SalesRefused::invalid('project_client_differs', 'projectId', 'The project is for another client than the deal.');
        }
        $other = $this->store->lineOfProject($project->id);
        if ($other instanceof DealLine && $other->id() !== $line?->id()) {
            throw SalesRefused::conflict('project_already_linked', 'The project is linked to a line of another deal, or of this one; a project serves one hourly line. Unlink it there first.', 'projectId', ['dealId' => $other->dealId(), 'lineId' => $other->id()]);
        }

        return $project->id;
    }

    /**
     * @throws SalesRefused not_found
     */
    private function deal(string $id): Deal
    {
        $deal = $this->store->deal($id);
        if (!$deal instanceof Deal || $deal->isTrashed()) {
            throw SalesRefused::notFound('There is no deal with this id. Find it with foresi_search_deals.');
        }

        return $deal;
    }

    /**
     * @throws SalesRefused not_found
     */
    private function line(Deal $deal, string $id): DealLine
    {
        foreach ($this->store->linesOf($deal->id()) as $line) {
            if ($line->id() === $id) {
                return $line;
            }
        }

        throw SalesRefused::notFound('The deal has no line with this id. Read the deal with foresi_get_deal.');
    }

    private function updated(Deal $deal): void
    {
        $deal->touch($this->clock->now());
        $this->events->record(new DealUpdated($deal->id(), $deal->status()->value, $deal->stageId()));
    }

    /**
     * @template T
     *
     * @param T|Omitted $value
     * @param T         $current
     *
     * @return T
     */
    private function given(mixed $value, mixed $current): mixed
    {
        return $value instanceof Omitted ? $current : $value;
    }

    /**
     * Minor units as the decimal the rules read back, such as "1500.50". A
     * stored price is never negative.
     */
    private function decimal(int $minor): string
    {
        return \sprintf('%d.%02d', intdiv($minor, 100), $minor % 100);
    }
}
