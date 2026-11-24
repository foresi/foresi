<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Infrastructure\Controller;

use Foresi\Banking\Application\BankMovements;
use Foresi\Banking\Application\BankScreen;
use Foresi\Banking\Application\MessageRow;
use Foresi\Banking\Application\MovementFilter;
use Foresi\Banking\Application\MovementPage;
use Foresi\Banking\Application\MovementRow;
use Foresi\Banking\Application\Ownership;
use Foresi\Banking\Application\PairingChoice;
use Foresi\Banking\Application\PairingChoices;
use Foresi\Banking\Application\PairingOptions;
use Foresi\Banking\Application\PairingRefused;
use Foresi\Banking\Application\PairMovement;
use Foresi\Banking\Application\UnknownBankMovement;
use Foresi\Banking\Application\UnpairMovement;
use Foresi\Banking\Application\View\BankMovementLists;
use Foresi\Banking\Domain\BankMovement;
use Foresi\Banking\Domain\MessageTrust;
use Foresi\Banking\Domain\MovementState;
use Foresi\Banking\Domain\UnmatchedReason;
use Foresi\Banking\Infrastructure\Column\MovementColumns;
use Foresi\Banking\Infrastructure\View\BankMovementViews;
use Foresi\Identity\Application\StaffCheck;
use Foresi\Invoicing\Application\AllocationRequest;
use Foresi\Invoicing\Application\InvoicePayments;
use Foresi\Invoicing\Application\PaymentRefused;
use Foresi\Invoicing\Application\UnknownInvoice;
use Foresi\Invoicing\Domain\Payment;
use Foresi\Mail\Contract\AddressIssuer;
use Foresi\Shared\Application\View\FilterRows;
use Foresi\Shared\Application\View\ListView;
use Foresi\Shared\Application\View\ListViews;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Application\View\WorkingFilter;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Infrastructure\View\FilterSheet;
use Foresi\Ui\Column\RememberedDisplay;
use Foresi\Ui\Contract\ListDisplay;
use Foresi\Ui\Contract\WorkingDisplay;
use Foresi\Ui\Contract\WorkingState;
use Foresi\Ui\View\ViewBar;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The bank screen, for staff, as iDoklad's Finance, Bank: the incoming and
 * outgoing movements, the incoming ones no invoice was paired with, and the bank
 * mail on a tab of its own (InboundMessageController acts on it).
 *
 * Pairing by hand is a dialog over the list: the invoices the movement fits come
 * first, ticked and filled in as the matcher would, a search finds any other
 * unpaid one, and the parts must add up to the movement. It asks for no
 * confirmation: the flash after it offers Undo instead, for that pairing only.
 * A movement the bank did not vouch for may be forged, so its dialog warns,
 * ticks and fills in nothing, and pairs only with the box "checked in the bank"
 * ticked; PairMovement holds the same rule.
 * Unpairing from a row asks first, as it also stops the movement from pairing by
 * itself again. Each dialog is also a page of its own, which works without
 * JavaScript. An unknown movement answers 404.
 *
 * @phpstan-type Values array{invoices: list<string>, amounts: array<string, string>, q: string, settle: bool, checked: bool}
 */
final class BankMovementController extends AbstractController
{
    public const string ACTION_TOKEN = 'bank_movement';
    /** The parts of the screen's query an action carries back to it. */
    public const array LIST_QUERY = ['view', 'tab', 'q', 'page', WorkingFilter::PARAM, WorkingDisplay::COLUMNS, WorkingDisplay::DENSITY];
    /** The screen lists the newest booked first, and takes no other order. */
    private const string SORT = 'date';
    /** The pairing made just now, which the flash may undo. */
    private const string UNDO = 'bank.undo';
    /** How long Undo stays offered for a pairing; later, unpairing asks first. */
    private const int UNDO_SECONDS = 600;
    private const string PAIRED_FLASH = 'bank_paired';
    private const string FORM = 'form';
    /** The box a person ticks after finding an unverified payment in their internet banking. */
    private const string CHECKED = 'checked_in_bank';
    private const int MAX_TEXT = 100;

    public function __construct(
        private readonly BankScreen $screen,
        private readonly BankMovements $movements,
        private readonly PairingChoices $choices,
        private readonly PairMovement $pairing,
        private readonly UnpairMovement $unpairing,
        private readonly InvoicePayments $payments,
        private readonly BankText $text,
        private readonly StaffCheck $staff,
        private readonly TranslatorInterface $translator,
        private readonly AddressIssuer $addresses,
        private readonly Ownership $ownership,
        private readonly ClockInterface $clock,
        private readonly BankMovementViews $views,
        private readonly MovementColumns $movementColumns,
        private readonly RememberedDisplay $rememberedDisplay,
        private readonly ViewBar $viewBar,
        private readonly FilterSheet $filterSheet,
    ) {
    }

    #[Route('/bank', name: 'app_bank_movements', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyUnlessStaff();
        // The builder's rows (spec D11) become the list's working filter, in its address.
        if ($request->query->has(FilterRows::MARKER)) {
            return $this->redirectToRoute('app_bank_movements', $this->builtQuery($request->query), Response::HTTP_SEE_OTHER);
        }
        [$list, $views, $view, $shown] = $this->views->of($request->query);
        $filterParam = WorkingFilter::param($shown, $view->filter);
        $text = trim(mb_substr($request->query->getString('q'), 0, self::MAX_TEXT));
        $movement = $request->query->getString('movement');
        $page = $this->screen->page(new MovementFilter(
            $shown,
            $text,
            max(1, $request->query->getInt('page', 1)),
            1 === preg_match('/^'.Requirement::UUID.'$/D', $movement) ? $movement : null,
            $this->counted($views),
        ));
        $mail = $page->mail;
        // The bank mail is not searched: its tab has no search field.
        $text = $mail ? '' : $text;
        $query = array_filter(['view' => $view->isAll() ? null : $view->key, 'q' => '' === $text ? null : $text, WorkingFilter::PARAM => $filterParam], static fn (?string $value): bool => null !== $value);
        $context = [MovementColumns::ACCOUNT_COLUMN => \count($page->accounts) > 1];
        // The quick filters, columns and density changed in the Settings sheet, kept in the address next to the view and for the person (spec D10, D-display-3).
        $working = $this->rememberedDisplay->read(MovementColumns::LIST, $context, $list->key, $view, $request->query->all(), ListDisplay::DENSE, filters: $list);
        $query += $working->query;
        $here = http_build_query(1 === $page->page ? $query : $query + ['page' => (string) $page->page]);
        $bar = $this->viewBar->of($list, $views, $view, $shown, self::SORT, self::SORT, $working, 'app_bank_movements', '' === $text ? [] : ['q' => $text]);

        return $this->render('@Banking/movements.html.twig', [
            'page' => $page,
            'mail' => $mail,
            'caption' => $view->translated ? $this->translator->trans($view->name) : $view->name,
            'view' => $view->isAll() ? '' : $view->key,
            'working' => $filterParam ?? '',
            ...$this->filterSheet->of($list, $shown, fn (ViewFilter $next): string => $this->generateUrl('app_bank_movements', [WorkingFilter::PARAM => WorkingFilter::param($next, $view->filter)] + array_diff_key($query, [WorkingFilter::PARAM => true])), pinned: $working->pinned),
            ...$bar,
            'text' => $text,
            ...$this->tabs($views, $view->key, $page, $query, $bar['viewBar']['modified']),
            'columns' => $mail ? $this->messageColumns(\count($page->accounts) > 1) : $working->display->table(),
            'rows' => $mail
                ? array_map(fn (MessageRow $row): array => $this->messageRow($row, \count($page->accounts) > 1, $here), $page->messages)
                : array_map(fn (MovementRow $row): array => $this->row($row, $working, $here), $page->rows),
            'density' => $working->tableDensity(),
            'picker' => $this->picker($working, $query),
            'workingQuery' => $working->query,
            'baseUrl' => $this->generateUrl('app_bank_movements', $query),
            'filtered' => '' !== $text || null !== $filterParam,
            'paired' => $this->pairedFlash($request),
            'noAccounts' => [] === $page->accounts,
        ]);
    }

    #[Route('/bank/movements/{id}/pair', name: 'app_bank_movement_pair', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function pair(string $id, Request $request): Response
    {
        $this->denyUnlessStaff();
        $movement = $this->movement($id);
        $post = $request->isMethod('POST');
        $return = $post ? $request->request->getString('return') : $request->query->getString('return');
        if ($post) {
            $this->checkToken($request);
        }
        $refusal = $this->unpairable($movement);
        if (null !== $refusal) {
            $this->addFlash('error', $this->translator->trans('bank.movement.refused.'.$refusal));

            return $this->toList($return);
        }
        if (!$post) {
            $options = $this->choices->of($movement);

            return $this->pairPage($movement, $options, $this->prefilled($movement, $options), [], $return, Response::HTTP_OK);
        }

        $values = $this->posted($request);
        $options = $this->choices->of($movement, $values['q'], $values['invoices']);
        if ($request->request->has('search')) {
            return $this->pairPage($movement, $options, $values, [], $return, Response::HTTP_OK);
        }
        [$allocations, $errors] = $this->allocations($movement, $options, $values);
        if ($this->checkFirst($movement) && !$values['checked']) {
            $errors[self::CHECKED] = $this->translator->trans('bank.movement.pair.error.checked');
        }
        if ([] !== $errors || [] === $allocations) {
            return $this->pairPage($movement, $options, $values, $errors, $return, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $payment = $this->pairing->pair($id, $allocations, $values['checked']);
        } catch (PairingRefused $refused) {
            $this->addFlash('error', $this->translator->trans('bank.movement.refused.'.$refused->reason));

            return $this->toList($return);
        } catch (PaymentRefused $refused) {
            return $this->pairPage($movement, $options, $values, [self::FORM => $this->translator->trans('invoice.payment.refused.'.$refused->reason, ['max' => Payment::MAX_NOTE])], $return, Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (UnknownInvoice) {
            return $this->pairPage($movement, $options, $values, [self::FORM => $this->translator->trans('bank.movement.pair.error.unknown_invoice')], $return, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $numbers = implode(', ', $this->payments->invoiceNumbersOf($payment->id()));
        $request->getSession()->set(self::UNDO, ['movement' => $id, 'payment' => $payment->id(), 'at' => $this->clock->now()->getTimestamp()]);
        $this->addFlash(self::PAIRED_FLASH, ['text' => $this->translator->trans('bank.movement.flash.paired', ['numbers' => $numbers]), 'movement' => $id, 'return' => $return]);

        return $this->toList($return);
    }

    #[Route('/bank/movements/{id}/unpair', name: 'app_bank_movement_unpair', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function unpair(string $id, Request $request): Response
    {
        $this->denyUnlessStaff();
        $movement = $this->movement($id);
        $post = $request->isMethod('POST');
        $return = $post ? $request->request->getString('return') : $request->query->getString('return');
        if ($post) {
            $this->checkToken($request);
        }
        $paymentId = $movement->paymentId();
        if (MovementState::Paired !== $movement->state() || null === $paymentId) {
            $this->addFlash('warning', $this->translator->trans('bank.movement.refused.not_paired'));

            return $this->toList($return);
        }
        $numbers = array_values($this->payments->invoiceNumbersOf($paymentId));
        if (!$post) {
            return $this->render('@Banking/unpair.html.twig', [
                'movement' => $movement,
                'amount' => $this->text->amount($movement->amount()),
                'numbers' => $numbers,
                'return' => $return,
                'cancel' => $this->listUrl($return),
            ]);
        }

        return $this->unpaired($id, $numbers, $return);
    }

    /**
     * Unpairs without asking, from the flash after a pairing made by hand, and only
     * that pairing, for ten minutes: one made by the matcher or earlier is unpaired
     * after the confirmation.
     */
    #[Route('/bank/movements/{id}/undo', name: 'app_bank_movement_undo', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function undo(string $id, Request $request): RedirectResponse
    {
        $this->denyUnlessStaff();
        $movement = $this->movement($id);
        $this->checkToken($request);
        $return = $request->request->getString('return');
        $session = $request->getSession();
        $undo = $session->get(self::UNDO);
        $paymentId = $movement->paymentId();
        $at = \is_array($undo) && \is_int($undo['at'] ?? null) ? $undo['at'] : null;
        $fresh = null !== $at && $this->clock->now()->getTimestamp() - $at <= self::UNDO_SECONDS;
        if (!\is_array($undo) || !$fresh || ($undo['movement'] ?? null) !== $id || null === $paymentId || ($undo['payment'] ?? null) !== $paymentId) {
            $this->addFlash('warning', $this->translator->trans('bank.movement.flash.undo_gone'));

            return $this->toList($return);
        }
        $session->remove(self::UNDO);

        return $this->unpaired($id, array_values($this->payments->invoiceNumbersOf($paymentId)), $return);
    }

    /**
     * @param list<string> $numbers the invoices the payment paid, read before it is cancelled
     */
    private function unpaired(string $id, array $numbers, string $return): RedirectResponse
    {
        try {
            $this->unpairing->unpair($id);
        } catch (PairingRefused) {
            $this->addFlash('warning', $this->translator->trans('bank.movement.refused.not_paired'));

            return $this->toList($return);
        }
        $this->addFlash('success', $this->translator->trans('bank.movement.flash.unpaired', ['count' => \count($numbers), 'numbers' => implode(', ', $numbers)]));

        return $this->toList($return);
    }

    /**
     * What the dialog opens with: as the matcher would pair it, or nothing at all
     * for a movement to check in the bank first, so a person types what they found.
     *
     * @return Values
     */
    private function prefilled(BankMovement $movement, PairingOptions $options): array
    {
        if ($this->checkFirst($movement)) {
            return ['invoices' => [], 'amounts' => [], 'q' => '', 'settle' => false, 'checked' => false];
        }

        return [
            'invoices' => array_keys($options->prefill),
            'amounts' => array_map($this->text->plain(...), $options->prefill) + $this->defaults($movement, $options),
            'q' => '',
            'settle' => false,
            'checked' => false,
        ];
    }

    /**
     * Whether the bank did not vouch for the movement: from an unverified message,
     * or one recorded anyway. A person checks it in their internet banking first.
     */
    private function checkFirst(BankMovement $movement): bool
    {
        return MessageTrust::Verified !== $movement->trust();
    }

    /**
     * What an invoice not filled in gets once ticked without JavaScript: what it has
     * left, up to the whole movement. Nothing for a movement to check in the bank first.
     *
     * @return array<string, string>
     */
    private function defaults(BankMovement $movement, PairingOptions $options): array
    {
        if ($this->checkFirst($movement)) {
            return [];
        }
        $amounts = [];
        foreach ([...$options->suggested, ...$options->others] as $choice) {
            $remaining = $choice->candidate->remaining;
            $amounts[$choice->candidate->invoiceId] = $this->text->plain(Money::ofMinor(min($remaining->minor(), $movement->amount()->minor()), $remaining->currency()));
        }

        return $amounts;
    }

    /**
     * @return Values
     */
    private function posted(Request $request): array
    {
        $invoices = array_values(array_unique(array_filter($request->request->all('invoices'), static fn (mixed $id): bool => \is_string($id) && 1 === preg_match('/^'.Requirement::UUID.'$/D', $id))));
        $amounts = [];
        foreach ($request->request->all('amounts') as $invoiceId => $amount) {
            if (\is_string($amount)) {
                $amounts[(string) $invoiceId] = trim(mb_substr($amount, 0, 32));
            }
        }

        return [
            'invoices' => $invoices,
            'amounts' => $amounts,
            'q' => trim(mb_substr($request->request->getString('q'), 0, self::MAX_TEXT)),
            'settle' => $request->request->getBoolean('settle'),
            'checked' => $request->request->getBoolean(self::CHECKED),
        ];
    }

    /**
     * The parts as typed, checked to add up to the movement, for invoices the dialog
     * offered only. A small difference on one invoice is written off only when the
     * firm allows it and the box is ticked; PairMovement checks it again under the
     * lock of the invoice, and refuses an invoice paid meanwhile.
     *
     * @param Values $values
     *
     * @return array{list<AllocationRequest>, array<string, string>}
     */
    private function allocations(BankMovement $movement, PairingOptions $options, array $values): array
    {
        $total = $movement->amount();
        if ([] === $values['invoices']) {
            return [[], [self::FORM => $this->translator->trans('bank.movement.pair.error.none')]];
        }
        // Only what the dialog offered: unpaid invoices of the firm in the movement's currency.
        if (array_any($values['invoices'], static fn (string $invoiceId): bool => !$options->choice($invoiceId) instanceof PairingChoice)) {
            return [[], [self::FORM => $this->translator->trans('bank.movement.pair.error.unknown_invoice')]];
        }
        $errors = [];
        $allocations = [];
        $sum = 0;
        foreach ($values['invoices'] as $invoiceId) {
            $amount = $this->text->typed($values['amounts'][$invoiceId] ?? '', $total->currency());
            if (!$amount instanceof Money) {
                $errors['amount-'.$invoiceId] = $this->translator->trans('bank.movement.pair.error.amount');

                continue;
            }
            $sum += $amount->minor();
            $allocations[] = new AllocationRequest($invoiceId, $amount);
        }
        if ([] !== $errors) {
            return [[], $errors];
        }
        if ($sum !== $total->minor()) {
            return [[], [self::FORM => $this->translator->trans('bank.movement.pair.error.sum', ['sum' => $this->text->amount(Money::ofMinor($sum, $total->currency())), 'amount' => $this->text->amount($total)])]];
        }
        if (!$values['settle']) {
            return [$allocations, []];
        }

        $choice = 1 === \count($allocations) ? $options->choice($allocations[0]->invoiceId) : null;
        $difference = $choice instanceof PairingChoice ? $choice->candidate->remaining->minor() - $total->minor() : 0;
        if (null === $options->settlementLimit || !$choice instanceof PairingChoice || 0 === $difference || abs($difference) > $options->settlementLimit) {
            return [[], ['settle' => $this->translator->trans('bank.movement.pair.error.settle')]];
        }

        return [[new AllocationRequest($allocations[0]->invoiceId, $total, Money::ofMinor($difference, $total->currency()))], []];
    }

    /**
     * @param Values                $values
     * @param array<string, string> $errors by field; "form" for one that names none
     */
    private function pairPage(BankMovement $movement, PairingOptions $options, array $values, array $errors, string $return, int $status): Response
    {
        $total = $movement->amount();
        $sum = 0;
        foreach ($values['invoices'] as $invoiceId) {
            $sum += $this->text->typed($values['amounts'][$invoiceId] ?? '', $total->currency())?->minor() ?? 0;
        }
        $line = fn (PairingChoice $choice): array => $this->choiceLine($choice, $values, $errors, $movement);

        return $this->render('@Banking/pair.html.twig', [
            'movement' => $movement,
            'amount' => $this->text->amount($total),
            'amountMinor' => $total->minor(),
            'unit' => $this->text->unit($total->currency()),
            'booked' => $movement->bookedOn()->format($this->translator->trans('format.date')),
            'partner' => implode(' · ', array_filter([$movement->counterName(), $movement->counterAccount() ?? $movement->counterIban()], static fn (?string $part): bool => null !== $part && '' !== $part)),
            'suggested' => array_map($line, $options->suggested),
            'others' => array_map($line, $options->others),
            'values' => $values,
            'errors' => $errors,
            'return' => $return,
            'cancel' => $this->listUrl($return),
            'sum' => $this->text->amount(Money::ofMinor($sum, $total->currency())),
            'limit' => $options->settlementLimit,
            'settleText' => null === $options->settlementLimit ? null : $this->translator->trans('bank.movement.pair.settle_help', ['limit' => $this->text->amount(Money::ofMinor($options->settlementLimit, $total->currency()))]),
            'searched' => '' !== $values['q'],
            'checkFirst' => $this->checkFirst($movement),
        ], new Response(status: $status));
    }

    /**
     * @param Values                $values
     * @param array<string, string> $errors
     *
     * @return array<string, mixed>
     */
    private function choiceLine(PairingChoice $choice, array $values, array $errors, BankMovement $movement): array
    {
        $candidate = $choice->candidate;
        $id = $candidate->invoiceId;
        $amounts = $values['amounts'] + $this->defaults($movement, new PairingOptions([$choice], [], [], null));

        return [
            'id' => $id,
            'number' => $candidate->number,
            'customer' => $candidate->customerName,
            'due' => $this->translator->trans('bank.movement.pair.due', ['date' => $candidate->dueDate->format($this->translator->trans('format.date'))]),
            'remaining' => $this->text->amount($candidate->remaining),
            'remainingMinor' => $candidate->remaining->minor(),
            'reason' => null === $choice->reason ? null : $this->translator->trans('bank.movement.pair.reason.'.$choice->reason),
            'checked' => \in_array($id, $values['invoices'], true),
            'value' => $amounts[$id] ?? '',
            'error' => $errors['amount-'.$id] ?? null,
            'label' => $this->translator->trans('bank.movement.pair.amount_label', ['number' => $candidate->number]),
        ];
    }

    private function refundsOffered(BankMovement $movement): bool
    {
        $options = $this->choices->of($movement);

        return [] !== $options->suggested || [] !== $options->others;
    }

    private function unpairable(BankMovement $movement): ?string
    {
        return match (true) {
            MovementState::Paired === $movement->state() => PairingRefused::ALREADY_PAIRED,
            MovementState::PossibleDuplicate === $movement->state() => PairingRefused::POSSIBLE_DUPLICATE,
            MovementState::OwnTransfer === $movement->state() => PairingRefused::OWN_TRANSFER,
            UnmatchedReason::Rejected === $movement->unmatchedReason(), MessageTrust::Failed === $movement->trust() => PairingRefused::REJECTED,
            // Outgoing money pairs only as a refund, so only while something is to refund.
            !$movement->isIncoming() && !$this->refundsOffered($movement) => PairingRefused::NOT_INCOMING,
            default => null,
        };
    }

    /**
     * @return array{text: string, movement: string, return: string}|null
     */
    private function pairedFlash(Request $request): ?array
    {
        $session = $request->getSession();
        if (!$session instanceof FlashBagAwareSessionInterface) {
            return null;
        }
        $flashes = $session->getFlashBag()->get(self::PAIRED_FLASH);
        $last = [] === $flashes ? null : $flashes[array_key_last($flashes)];
        if (!\is_array($last) || !\is_string($last['text'] ?? null) || !\is_string($last['movement'] ?? null)) {
            return null;
        }

        return ['text' => $last['text'], 'movement' => $last['movement'], 'return' => \is_string($last['return'] ?? null) ? $last['return'] : ''];
    }

    /**
     * The views that show a count, by key: the primary tabs alone (spec D9).
     *
     * @param list<ListView> $views
     *
     * @return array<string, ViewFilter>
     */
    private function counted(array $views): array
    {
        $counted = [];
        foreach (ListViews::placed($views)[0] as $tab) {
            if ($tab->showCount) {
                $counted[$tab->key] = $tab->filter;
            }
        }

        return $counted;
    }

    /**
     * The list's address after the builder's Apply: what the sheet sent, its rows read
     * into the working filter, which is left out when it is the view's own.
     *
     * @param InputBag<string> $input
     *
     * @return array<string, mixed>
     */
    private function builtQuery(InputBag $input): array
    {
        [$list, , $view] = $this->views->of($input);
        $query = array_diff_key($input->all(), [FilterRows::PARAM => true, FilterRows::MARKER => true, WorkingFilter::PARAM => true, 'page' => true, 'tab' => true, BankMovementLists::ACCOUNT => true]);

        return array_filter($query + ['view' => $view->isAll() ? null : $view->key, WorkingFilter::PARAM => WorkingFilter::param(FilterRows::parse($input->all(FilterRows::PARAM), $list), $view->filter)], static fn (mixed $value): bool => null !== $value && '' !== $value);
    }

    /**
     * The tabs of the screen and the other views of its Settings sheet (spec D7): All and the primary
     * views as tabs, with the count of those that show one; the other views in the
     * menu. A view of the bank mail shows its count only while a message waits.
     *
     * @param list<ListView>        $views
     * @param array<string, string> $query
     *
     * @return array{tabs: list<array<string, mixed>>, moreViews: list<array<string, mixed>>}
     */
    private function tabs(array $views, string $active, MovementPage $page, array $query, bool $modified): array
    {
        [$primary, $overflow] = ListViews::placed($views);
        $link = function (ListView $view, bool $count) use ($active, $page, $query, $modified): array {
            $counted = $count && $view->showCount ? ($page->counts[$view->key] ?? null) : null;

            return [
                'label' => $view->translated ? $this->translator->trans($view->name) : $view->name,
                // A view opens with its own filter and columns: the changed ones belong to the view they were changed on (spec D10).
                'href' => $this->generateUrl('app_bank_movements', ['view' => $view->isAll() ? null : $view->key] + array_diff_key($query, ['view' => true, 'page' => true, WorkingFilter::PARAM => true] + array_flip(WorkingDisplay::KEYS))),
                'count' => 0 === $counted && BankMovementLists::showsMail($view->filter) ? null : $counted,
                'active' => $view->key === $active,
                'modified' => $modified && $view->key === $active,
                'private' => $view->private,
            ];
        };

        return ['tabs' => array_map(static fn (ListView $view): array => $link($view, true), $primary), 'moreViews' => array_map(static fn (ListView $view): array => $link($view, false), $overflow)];
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    private function picker(WorkingState $working, array $query): array
    {
        $kept = array_diff_key($query, array_flip(WorkingDisplay::KEYS));

        return [
            'keep' => $kept,
            'columns' => $working->picker(),
            'density' => $working->density,
            // An empty density names the view's own display, which forgets the one remembered (D-display-3).
            'resetHref' => $this->generateUrl('app_bank_movements', $kept + [WorkingDisplay::DENSITY => '']),
            'modified' => $working->modified,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function messageColumns(bool $severalAccounts): array
    {
        // The sender is in the message itself: the subject and the reason need the room.
        $columns = [$this->column('received'), $this->column('subject', phone: false, truncate: true)];
        if ($severalAccounts) {
            $columns[] = $this->column('account', phone: false, wide: true, truncate: true);
        }
        $columns[] = $this->column('status', phone: false);
        $columns[] = $this->column('reason', phone: false, truncate: true);

        return $columns;
    }

    /**
     * @return array<string, mixed>
     */
    private function column(string $key, string $align = 'left', bool $phone = true, bool $wide = false, bool $truncate = false): array
    {
        return ['key' => $key, 'label' => $this->translator->trans('bank.movement.column.'.$key), 'align' => $align, 'phone' => $phone, 'wide' => $wide, 'truncate' => $truncate];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(MovementRow $row, WorkingState $working, string $return): array
    {
        $date = $this->movementColumns->date($row);
        $amount = $this->movementColumns->amount($row);
        $state = $this->text->state($row->state, $row->reason);

        return [
            'id' => $row->id,
            'label' => $date.' · '.$amount,
            'secondary' => implode(' · ', array_filter([$row->counterName, $row->variableSymbol, [] === $row->invoices ? $state['text'] : $this->translator->trans('bank.movement.list.paired_with', ['numbers' => implode(', ', $row->invoices)])], static fn (?string $part): bool => null !== $part && '' !== $part)),
            'cells' => $working->display->cells($row),
            'actions' => $this->actions($row, $return),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function actions(MovementRow $row, string $return): array
    {
        $id = ['id' => $row->id, 'return' => '' === $return ? null : $return];
        $actions = [];
        if ($row->isPairable()) {
            $actions[] = ['label' => $this->translator->trans('bank.movement.action.pair'), 'href' => $this->generateUrl('app_bank_movement_pair', $id), 'method' => 'GET', 'icon' => 'link-2', 'dialog' => true];
        }
        if (MovementState::Paired === $row->state) {
            $actions[] = ['label' => $this->translator->trans('bank.movement.action.unpair'), 'href' => $this->generateUrl('app_bank_movement_unpair', $id), 'method' => 'GET', 'dialog' => true];
        }
        if (null !== $row->inboundMessageId) {
            $actions[] = ['label' => $this->translator->trans('bank.movement.action.message'), 'href' => $this->generateUrl('app_bank_message', ['id' => $row->inboundMessageId]), 'method' => 'GET', 'dialog' => true];
        }

        return $actions;
    }

    /**
     * @return array<string, mixed>
     */
    private function messageRow(MessageRow $row, bool $severalAccounts, string $return): array
    {
        $received = $this->text->time($row->receivedAt);
        $status = $this->text->status($row->status, $row->stuck);
        $reason = $this->text->reason($row->status, $row->failure, $row->stuck);
        // Only the owner sees the inbound address, which a forwarding confirmation quotes.
        $subject = $this->addresses->masked($row->subject);
        $cells = [
            'received' => $received,
            'subject' => $subject,
            'status' => $status,
            'reason' => $reason,
        ];
        if ($severalAccounts) {
            $cells['account'] = $this->text->account($row->accountLabel, $row->accountIban);
        }
        $id = ['id' => $row->id];
        $fields = ['return' => $return];
        $actions = [['label' => $this->translator->trans('bank.message.action.view'), 'href' => $this->generateUrl('app_bank_message', $id), 'method' => 'GET', 'dialog' => true]];
        if ($row->canRetry()) {
            $actions[] = ['label' => $this->translator->trans('bank.message.action.retry'), 'href' => $this->generateUrl('app_bank_message_retry', $id), 'method' => 'POST', 'icon' => 'refresh-cw', 'fields' => $fields];
        }
        if ($row->canAccept() && $this->ownership->isOwner()) {
            $actions[] = ['label' => $this->translator->trans('bank.message.action.accept'), 'href' => $this->generateUrl('app_bank_message_accept', $id + ['return' => '' === $return ? null : $return]), 'method' => 'GET', 'dialog' => true];
        }
        if ($row->canDismiss()) {
            $actions[] = ['label' => $this->translator->trans('bank.message.action.dismiss'), 'href' => $this->generateUrl('app_bank_message_dismiss', $id + ['return' => '' === $return ? null : $return]), 'method' => 'GET', 'dialog' => true];
        }

        return [
            'id' => $row->id,
            'label' => $subject,
            'secondary' => implode(' · ', array_filter([$subject, $status['text'], $reason], static fn (string $part): bool => '' !== $part)),
            'cells' => $cells,
            'actions' => $actions,
        ];
    }

    private function movement(string $id): BankMovement
    {
        try {
            return $this->movements->get($id);
        } catch (UnknownBankMovement) {
            throw new NotFoundHttpException();
        }
    }

    private function toList(string $return): RedirectResponse
    {
        return $this->redirect($this->listUrl($return));
    }

    /**
     * Only the known parts of the query come back: the value travelled through the browser.
     */
    private function listUrl(string $return): string
    {
        parse_str($return, $query);

        return $this->generateUrl('app_bank_movements', array_filter(array_intersect_key($query, array_flip(self::LIST_QUERY)), \is_string(...)));
    }

    private function denyUnlessStaff(): void
    {
        if (!$this->staff->isStaff()) {
            throw new AccessDeniedHttpException('Only staff work with bank movements.');
        }
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::ACTION_TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
