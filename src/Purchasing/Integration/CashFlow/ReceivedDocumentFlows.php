<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Integration\CashFlow;

use App\CashFlow\Contract\ActualFlow;
use App\CashFlow\Contract\ActualFlowSource;
use App\CashFlow\Contract\CashFlowSource;
use App\CashFlow\Contract\ExpectedFlow;
use App\CashFlow\Contract\FlowCategory;
use App\CashFlow\Contract\FlowCertainty;
use App\CashFlow\Contract\FlowDirection;
use App\CashFlow\Contract\FlowFlag;
use App\CashFlow\Contract\FlowSubject;
use App\Identity\Application\ApiTokenContext;
use App\Identity\Application\ApiTokenGrant;
use App\Identity\Domain\ApiScope;
use App\Purchasing\Application\Query\PayableDocument;
use App\Purchasing\Application\Query\ReceivedDocumentBalances;
use App\Purchasing\Application\Query\ReceivedDocumentSliceFilter;
use App\Purchasing\Domain\ReceivedDocument;
use App\Purchasing\Domain\ReceivedDocumentKind;
use App\Purchasing\Domain\ReceivedDocumentState;
use App\Purchasing\Infrastructure\Api\ReceivedDocumentApiController;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The recorded received documents of a firm in the forecast (C2): what is
 * still to pay, on the due date. A document without a due date is expected on
 * the day it was received, flagged so. A credit note brings its money back in.
 * Drafts and the trash stay out. As actuals, every recorded document counts,
 * paid or not, by its issue date and supplier (C13).
 */
final readonly class ReceivedDocumentFlows implements CashFlowSource, ActualFlowSource
{
    public const string KEY = 'purchasing.received_documents';
    private const int CHUNK = 200;

    public function __construct(
        private ReceivedDocumentBalances $documents,
        private ApiTokenContext $tokens,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function mayReadDetails(): bool
    {
        $grant = $this->tokens->grant();

        return !$grant instanceof ApiTokenGrant || $grant->allows(ApiScope::of('received_documents', false));
    }

    public function flows(string $firmId, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $today): iterable
    {
        $zone = $today->getTimezone();
        $last = $to->format('Y-m-d');
        foreach ($this->payable(new ReceivedDocumentSliceFilter(firmId: $firmId, state: ReceivedDocumentState::Recorded, unpaid: true)) as $payable) {
            $document = $payable->document;
            $remaining = $payable->balance->remaining;
            $due = $document->dueDate();
            $day = $due ?? $document->receivedDate() ?? $document->issueDate() ?? $today;
            if ($remaining->isZero() || $remaining->isNegative() || $day->format('Y-m-d') > $last) {
                continue;
            }
            yield new ExpectedFlow(
                self::KEY,
                $this->subject($document),
                $this->direction($document),
                $remaining,
                new \DateTimeImmutable($day->format('Y-m-d'), $zone),
                FlowCategory::Purchases,
                FlowCertainty::Document,
                $this->label($document),
                $due instanceof \DateTimeImmutable ? [] : [FlowFlag::NoDueDate],
                $document->supplierParty()?->id(),
            );
        }
    }

    public function actuals(string $firmId, \DateTimeImmutable $from, \DateTimeImmutable $to): iterable
    {
        [$first, $last] = [$from->format('Y-m-d'), $to->format('Y-m-d')];
        foreach ($this->payable(new ReceivedDocumentSliceFilter(firmId: $firmId, state: ReceivedDocumentState::Recorded)) as $payable) {
            $document = $payable->document;
            $day = $document->issueDate() ?? $document->receivedDate();
            if (!$day instanceof \DateTimeImmutable || $day->format('Y-m-d') < $first || $day->format('Y-m-d') > $last || $payable->balance->due->isZero()) {
                continue;
            }
            yield new ActualFlow(
                self::KEY,
                $this->subject($document),
                $this->direction($document),
                $payable->balance->due,
                new \DateTimeImmutable($day->format('Y-m-d'), $from->getTimezone()),
                counterpartyId: $document->supplierParty()?->id(),
            );
        }
    }

    /**
     * @return iterable<PayableDocument>
     */
    private function payable(ReceivedDocumentSliceFilter $filter): iterable
    {
        $after = null;
        do {
            $slice = $this->documents->slice($filter, $after, self::CHUNK);
            yield from $slice->items;
            $after = $slice->hasMore ? $slice->lastId() : null;
        } while (null !== $after);
    }

    private function direction(ReceivedDocument $document): FlowDirection
    {
        return ReceivedDocumentKind::CreditNote === $document->kind() ? FlowDirection::In : FlowDirection::Out;
    }

    private function label(ReceivedDocument $document): string
    {
        return trim(\sprintf('%s %s', '' === $document->supplierNumber() ? ($document->evidenceNumber() ?? '') : $document->supplierNumber(), $document->supplier()->name));
    }

    private function subject(ReceivedDocument $document): FlowSubject
    {
        return new FlowSubject('received_document', $document->id(), $this->urls->generate(ReceivedDocumentApiController::SHOW, ['documentId' => $document->id()], UrlGeneratorInterface::ABSOLUTE_PATH));
    }
}
