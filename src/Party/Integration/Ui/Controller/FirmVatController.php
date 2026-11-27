<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Integration\Ui\Controller;

use Foresi\Instance\Application\InstanceClock;
use Foresi\Party\Application\AddVatStatusInput;
use Foresi\Party\Application\InstanceFirm;
use Foresi\Party\Application\RemoveVatStatusInput;
use Foresi\Party\Application\VatStatusRefused;
use Foresi\Party\Application\VatStatusWrites;
use Foresi\Party\Application\View\FirmVatLists;
use Foresi\Party\Contract\FirmVatStatuses;
use Foresi\Party\Contract\VatPeriodLength;
use Foresi\Party\Contract\VatStretch;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\VatStatus;
use Foresi\Party\Integration\Ui\Column\FirmVatColumns;
use Foresi\Party\Integration\Ui\Column\VatStatusRow;
use Foresi\Party\Integration\Ui\View\VatStretchLabel;
use Foresi\Shared\Application\ActorRole;
use Foresi\Shared\Application\View\ListSlot;
use Foresi\Shared\Application\View\ListTables;
use Foresi\Shared\Contract\RefusalKind;
use Foresi\Shared\Infrastructure\View\FilterSheet;
use Foresi\Ui\Contract\WorkingState;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The VAT statuses of the firm over time, for its owner, as one more tab of
 * the firm: a dated list, "Add a change" in the page header opening the form in
 * a dialog, and removing a status from its row menu. The change is one choice,
 * a payer with its period length or another status, so no script swaps the
 * fields, as the statuses of Taxes and contributions.
 */
final class FirmVatController extends AbstractController
{
    public const string FORM_TOKEN = 'firm_vat_status';
    public const string ACTION_TOKEN = 'firm_vat_action';

    /** The choices of the form: status, then the period length of a payer. */
    private const array CHANGES = [
        'registered:month' => [VatStatus::Registered, VatPeriodLength::Month],
        'registered:quarter' => [VatStatus::Registered, VatPeriodLength::Quarter],
        'not_registered' => [VatStatus::NotRegistered, null],
        'identified_person' => [VatStatus::IdentifiedPerson, null],
    ];

    /**
     * @param ListTables<WorkingState> $listTable
     */
    public function __construct(
        private readonly InstanceFirm $instanceFirm,
        private readonly FirmVatStatuses $statuses,
        private readonly VatStatusWrites $writes,
        private readonly ActorRole $role,
        private readonly TranslatorInterface $translator,
        private readonly InstanceClock $clock,
        private readonly VatStretchLabel $labels,
        private readonly FirmVatLists $lists,
        private readonly ListTables $listTable,
        private readonly FilterSheet $filterSheet,
    ) {
    }

    #[Route('/settings/firm/vat', name: 'app_firm_vat', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyUnlessOwner();
        $firm = $this->instanceFirm->find();
        if (!$firm instanceof LegalEntity) {
            return $this->redirectToRoute('app_firm');
        }
        $slot = ListSlot::first($request->query->all());
        $list = $this->lists->list();
        // The builder's rows (spec D11) become the list's working filter, in its address.
        $built = $this->listTable->built($slot, $list);
        if (null !== $built) {
            return $this->redirectToRoute('app_firm_vat', $built, Response::HTTP_SEE_OTHER);
        }
        $stretches = $this->statuses->stretches();
        $current = $this->statuses->on($this->clock->today()) ?? $stretches[0] ?? null;
        $single = 1 === \count($stretches);
        $page = $this->listTable->of($slot, $list, FirmVatColumns::LIST, 'app_firm_vat', 'firm-vat', $this->records($stretches, $current, $request->getLocale()), fn (VatStatusRow $row, WorkingState $working): array => $this->row($row, $working, $single), $this->valueOf(...), static fn (VatStatusRow $row): string => $row->label, $this->translator->trans('firm.vat.search'));

        return $this->render('@Party/legal_entity/vat.html.twig', [
            'firm' => $firm,
            'today' => $this->label($current),
            'undated' => [] !== $stretches && !$stretches[0]->validFrom instanceof \DateTimeImmutable,
            'statuses' => $page->with($this->filterSheet->of($list, $page->shown, $page->href, pinned: $page->pinned, slot: $slot)),
        ]);
    }

    #[Route('/settings/firm/vat/new', name: 'app_firm_vat_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyUnlessOwner();
        $firm = $this->instanceFirm->find();
        if (!$firm instanceof LegalEntity) {
            return $this->redirectToRoute('app_firm');
        }
        $values = ['change' => 'registered:month', 'validFrom' => ''];
        if (!$request->isMethod('POST')) {
            return $this->form($firm, $values, [], Response::HTTP_OK);
        }

        $this->checkToken($request, self::FORM_TOKEN);
        $values = ['change' => $request->request->getString('change'), 'validFrom' => trim($request->request->getString('validFrom'))];
        $change = self::CHANGES[$values['change']] ?? null;
        if (null === $change) {
            return $this->form($firm, $values, ['change' => 'firm.vat.error.status_invalid'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $stretch = $this->writes->add(new AddVatStatusInput($change[0]->value, $values['validFrom'], $change[1]?->value));
        } catch (VatStatusRefused $refused) {
            $this->denyIfForbidden($refused);
            $field = match ($refused->reason()) {
                'date_invalid', 'same_day' => 'validFrom',
                'vat_id_missing', 'not_found' => 'form',
                default => 'change',
            };

            return $this->form($firm, $values, [$field => 'firm.vat.error.'.$refused->reason()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('success', $this->translator->trans('firm.vat.flash.added', ['status' => $this->labels->of($stretch)]));

        return $this->redirectToRoute('app_firm_vat');
    }

    #[Route('/settings/firm/vat/{id}/remove', name: 'app_firm_vat_remove', requirements: ['id' => '[^/]{1,64}'], methods: ['POST'])]
    public function remove(Request $request, string $id): RedirectResponse
    {
        $this->denyUnlessOwner();
        $this->checkToken($request, self::ACTION_TOKEN);
        try {
            $this->writes->remove(new RemoveVatStatusInput($id));
        } catch (VatStatusRefused $refused) {
            $this->denyIfForbidden($refused);
            if (RefusalKind::NotFound === $refused->kind()) {
                throw new NotFoundHttpException($refused->getMessage(), $refused);
            }
            $this->addFlash('error', $this->translator->trans('firm.vat.error.'.$refused->reason()));

            return $this->redirectToRoute('app_firm_vat');
        }
        $this->addFlash('success', $this->translator->trans('firm.vat.flash.removed'));

        return $this->redirectToRoute('app_firm_vat');
    }

    /**
     * @param list<VatStretch> $stretches oldest first
     *
     * @return list<VatStatusRow>
     */
    private function records(array $stretches, ?VatStretch $current, string $locale): array
    {
        $rows = [];
        foreach ($stretches as $index => $stretch) {
            $next = $stretches[$index + 1] ?? null;
            $rows[] = new VatStatusRow(
                $stretch->id,
                $this->labels->of($stretch),
                $stretch->validFrom instanceof \DateTimeImmutable ? $this->day($stretch->validFrom, $locale) : null,
                $next?->validFrom instanceof \DateTimeImmutable ? $this->day($next->validFrom->modify('-1 day'), $locale) : null,
                $stretch->id === $current?->id,
            );
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(VatStatusRow $row, WorkingState $working, bool $single): array
    {
        return [
            'id' => $row->id,
            'label' => $row->label,
            'secondary' => $row->from ?? $this->translator->trans('firm.vat.from_start'),
            'cells' => $working->display->cells($row),
            // The last status stays: the firm always has one.
            'actions' => $single ? [] : [[
                'label' => $this->translator->trans('firm.vat.remove'),
                'href' => $this->generateUrl('app_firm_vat_remove', ['id' => $row->id]),
                'method' => 'POST',
                'icon' => 'trash-2',
                'confirm' => [
                    'title' => $this->translator->trans('firm.vat.remove_title', ['status' => $row->label]),
                    'text' => $this->translator->trans('firm.vat.remove_text'),
                    'accept' => $this->translator->trans('firm.vat.remove'),
                    'tone' => 'danger',
                ],
            ]],
        ];
    }

    /**
     * The value of a field of the list on a status, as RowConditions compares it.
     */
    private function valueOf(VatStatusRow $row, string $field): mixed
    {
        return match ($field) {
            FirmVatLists::STATUS => $row->label,
            FirmVatLists::CURRENT => $row->current,
            default => null,
        };
    }

    private function label(?VatStretch $stretch): ?string
    {
        return $stretch instanceof VatStretch ? $this->labels->of($stretch) : null;
    }

    private function day(\DateTimeImmutable $day, string $locale): string
    {
        return (string) new \IntlDateFormatter($locale, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE, $day->getTimezone())->format($day);
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors field => translation key
     */
    private function form(LegalEntity $firm, array $values, array $errors, int $status): Response
    {
        $changes = [];
        foreach (self::CHANGES as $key => [$vatStatus, $length]) {
            $changes[$key] = $this->labels->status($vatStatus, $length);
        }

        return $this->render('@Party/legal_entity/vat_status.html.twig', [
            'firm' => $firm,
            'values' => $values,
            'errors' => array_map(fn (string $key): string => $this->translator->trans($key), $errors),
            'changes' => $changes,
        ], new Response(status: $status));
    }

    private function denyIfForbidden(VatStatusRefused $refused): void
    {
        if (RefusalKind::Forbidden === $refused->kind()) {
            throw new AccessDeniedHttpException($refused->getMessage(), $refused);
        }
    }

    private function denyUnlessOwner(): void
    {
        if (!$this->role->isOwner()) {
            throw new AccessDeniedHttpException('Only the owner manages the firm.');
        }
    }

    private function checkToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
