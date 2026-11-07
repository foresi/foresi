<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Audit\Application\AuditActor;
use App\Invoicing\Domain\RecurringContent;
use App\Invoicing\Domain\RecurringInvoice;
use App\Invoicing\Domain\RecurringInvoiceLine;
use App\Invoicing\Domain\RecurringState;
use App\Invoicing\Domain\Series;
use App\Party\Application\LegalEntities;
use App\Party\Application\PartyQuery;
use App\Party\Application\TransactionalWork;
use App\Party\Application\UnknownLegalEntity;
use App\Party\Domain\Iban;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\PaymentMethod;
use App\Tenancy\Application\TenantClock;
use Psr\Clock\ClockInterface;

/**
 * Creating and editing a recurring template. Each save runs in one transaction;
 * an edit runs under the lock of the template, rules included, so a run issuing it
 * sees the template before or after the edit, never half of it. Lines change in
 * place by position, as on an invoice. Whoever creates a template owns it.
 */
final readonly class SaveRecurringInvoice
{
    public function __construct(
        private LegalEntities $firms,
        private PartyQuery $parties,
        private SeriesCatalog $catalog,
        private RecurringRules $rules,
        private RecurringInvoices $templates,
        private InvoicingCountries $countries,
        private TransactionalWork $work,
        private ConflictRetry $retry,
        private AuditActor $actor,
        private ClockInterface $clock,
        private TenantClock $tenantClock,
    ) {
    }

    /**
     * @param bool        $startPaused  stored paused, as an import does with a template still running elsewhere
     * @param string|null $importedFrom the identifier of the template in the system it was imported from
     *
     * @throws RecurringRefused   when a rule refuses; nothing is stored
     * @throws UnknownLegalEntity also for a firm of another tenant
     */
    public function create(RecurringInput $input, bool $startPaused = false, ?string $importedFrom = null): RecurringInvoice
    {
        return $this->work->run(function () use ($input, $startPaused, $importedFrom): RecurringInvoice {
            $firm = $this->firms->get($input->firmId);
            $problems = $this->rules->check($input, $firm, null, $this->tenantClock->today());
            if ([] !== $problems) {
                throw new RecurringRefused($problems);
            }

            return $this->store($input, $firm, $startPaused, $importedFrom);
        });
    }

    /**
     * A template another system issued from until now, stored paused with what it issued
     * there: its first issue date may have passed, the count and the last date carry over,
     * and the next date goes on after the last one. A template whose end rule allows no
     * more is stored ended.
     *
     * @throws RecurringRefused   when a rule refuses; nothing is stored
     * @throws UnknownLegalEntity also for a firm of another tenant
     */
    public function import(RecurringInput $input, RecurringHistory $history): RecurringInvoice
    {
        return $this->work->run(function () use ($input, $history): RecurringInvoice {
            $firm = $this->firms->get($input->firmId);
            $problems = $this->rules->check($input, $firm, null, $this->tenantClock->today(), $history);
            if ([] !== $problems) {
                throw new RecurringRefused($problems);
            }

            $template = $this->store($input, $firm, true, $history->importedFrom);
            $template->carryOver($history->issuedCount, $history->lastOccurrence, $history->restartedOn);

            return $template;
        });
    }

    /**
     * @param int $version the version of the template when the form was opened
     *
     * @return RecurringInvoice the template as saved; anything loaded before is detached
     *
     * @throws RecurringRefused        when a rule refuses, when the template has ended, or when
     *                                 someone saved since the form was opened (field "version")
     * @throws UnknownRecurringInvoice also for a template of another tenant
     */
    public function edit(string $id, int $version, RecurringInput $input): RecurringInvoice
    {
        return $this->retry->run(fn (): RecurringInvoice => $this->work->run(function () use ($id, $version, $input): RecurringInvoice {
            $template = $this->templates->lock($id);
            if (RecurringState::Ended === $template->state()) {
                throw RecurringRefused::because('state', 'recurring.problem.ended');
            }
            if ($template->version() !== $version) {
                throw RecurringRefused::because('version', 'invoice.problem.changed_meanwhile');
            }
            $firm = $template->firm();
            $today = $this->tenantClock->today();
            $problems = $this->rules->check($input, $firm, $template, $today);
            if ([] !== $problems) {
                throw new RecurringRefused($problems);
            }
            $template->change($this->content($input, $firm), $today);
            $this->saveLines($template, $input);

            return $template;
        }));
    }

    private function store(RecurringInput $input, LegalEntity $firm, bool $startPaused, ?string $importedFrom): RecurringInvoice
    {
        $currency = $this->countries->for($firm->address()->countryCode)->homeCurrency();
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $template = new RecurringInvoice($firm, $currency, $this->content($input, $firm), $this->actor->userId(), $now, $startPaused, $importedFrom);
        $this->templates->add($template);
        foreach ($input->lines as $position => $line) {
            $this->templates->addLine(new RecurringInvoiceLine($template, $position, $line->text, $line->quantity, $line->unit, $line->price, $line->priceType, $line->treatment, $line->rateKind, $line->supplyCode, $line->discount));
        }

        return $template;
    }

    private function content(RecurringInput $input, LegalEntity $firm): RecurringContent
    {
        $customer = $this->parties->find($input->customerId) ?? throw new \LogicException('RecurringRules refuses an unknown customer.');
        $series = null === $input->seriesId ? null : $this->catalog->get($input->seriesId);
        if ($series instanceof Series && $series->firm()->id() !== $firm->id()) {
            throw new \LogicException('RecurringRules refuses a series of another firm.');
        }
        $account = PaymentMethod::BankTransfer === $input->paymentMethod && null !== $input->bankAccount ? Iban::fromString($input->bankAccount)->toString() : null;

        return new RecurringContent(
            $series,
            $customer,
            $input->paymentMethod,
            $account,
            $input->constantSymbol,
            $input->orderNumber,
            $input->description,
            $input->textBefore,
            $input->textAfter,
            $input->language,
            $input->recurrence,
            $input->taxPoint,
            $input->dueDays,
            $input->delivery,
            $input->name,
            $input->copyToMe,
            $input->copyToOthers,
        );
    }

    private function saveLines(RecurringInvoice $template, RecurringInput $input): void
    {
        $stored = [];
        foreach ($this->templates->lines($template) as $line) {
            $stored[$line->position()] = $line;
        }
        foreach ($input->lines as $position => $typed) {
            if (isset($stored[$position])) {
                $stored[$position]->revise($typed->text, $typed->quantity, $typed->unit, $typed->price, $typed->priceType, $typed->treatment, $typed->rateKind, $typed->supplyCode, $typed->discount);
            } else {
                $this->templates->addLine(new RecurringInvoiceLine($template, $position, $typed->text, $typed->quantity, $typed->unit, $typed->price, $typed->priceType, $typed->treatment, $typed->rateKind, $typed->supplyCode, $typed->discount));
            }
        }
        foreach ($stored as $position => $line) {
            if ($position >= \count($input->lines)) {
                $this->templates->removeLine($line);
            }
        }
    }
}
