<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Infrastructure\Controller;

use Foresi\Shared\Application\View\ListSlot;
use Foresi\Shared\Contract\RefusalKind;
use Foresi\Shared\Infrastructure\Http\TypedNumber;
use Foresi\Shared\Infrastructure\View\FilterSheet;
use Foresi\TaxesAndContributions\Application\AddStatusInput;
use Foresi\TaxesAndContributions\Application\ProfileReads;
use Foresi\TaxesAndContributions\Application\ProfileWrites;
use Foresi\TaxesAndContributions\Application\RemoveStatusInput;
use Foresi\TaxesAndContributions\Application\SetAuthorityAccountInput;
use Foresi\TaxesAndContributions\Application\SetYearInputsInput;
use Foresi\TaxesAndContributions\Application\TaxesAccess;
use Foresi\TaxesAndContributions\Application\TaxesRefused;
use Foresi\TaxesAndContributions\Application\View\ProfileStatusLists;
use Foresi\TaxesAndContributions\Contract\Authority;
use Foresi\TaxesAndContributions\Contract\AuthorityAccountView;
use Foresi\TaxesAndContributions\Contract\DeductionType;
use Foresi\TaxesAndContributions\Contract\ReturnRoute;
use Foresi\TaxesAndContributions\Infrastructure\Column\ProfileStatusColumns;
use Foresi\TaxesAndContributions\Infrastructure\Column\StatusRow;
use Foresi\TaxesAndContributions\Infrastructure\Screen\ProfileScreen;
use Foresi\Ui\View\ListTable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The owner profile under Money → Taxes and contributions: one line of the
 * statuses that hold, and "Statuses" in the page header opening their dated
 * list per kind in a dialog, where "Add a change" loads its form in place;
 * the inputs of a year as three forms in the side sheet, one per section; and the account of each
 * authority in a small dialog (docs/design/overlays.md). Only the owner sees
 * any of it (B7): a member is refused with 403.
 */
final class ProfileController extends AbstractController
{
    public const string ROUTE = 'app_taxes_profile';
    public const string TOKEN = 'taxes_profile';

    public function __construct(
        private readonly ProfileReads $reads,
        private readonly ProfileWrites $writes,
        private readonly ProfileScreen $screen,
        private readonly TaxesAccess $access,
        private readonly TranslatorInterface $translator,
        private readonly ProfileStatusLists $lists,
        private readonly ListTable $listTable,
        private readonly FilterSheet $filterSheet,
    ) {
    }

    #[Route('/taxes-and-contributions/profile', name: self::ROUTE, methods: ['GET'])]
    public function index(): Response
    {
        $profile = $this->reads()->profile();

        return $this->render('@TaxesAndContributions/profile/index.html.twig', [
            'summary' => $this->screen->statusSummary($profile),
            'years' => $this->screen->years($profile),
            'authorities' => $this->screen->authorities($profile),
        ]);
    }

    /**
     * The statuses as a dated list per kind: a small record that the page
     * header opens in a dialog, with "Add a change" in its header loading the
     * form in the same dialog. The same URL is a page.
     */
    #[Route('/taxes-and-contributions/profile/statuses', name: self::ROUTE.'_statuses', methods: ['GET'])]
    public function statuses(Request $request): Response
    {
        $profile = $this->reads()->profile();
        $slot = ListSlot::first($request->query->all());
        $list = $this->lists->list();
        // The builder's rows (spec D11) become the list's working filter, in its address.
        $built = $this->listTable->built($slot, $list);
        if (null !== $built) {
            return $this->redirectToRoute(self::ROUTE.'_statuses', $built, Response::HTTP_SEE_OTHER);
        }
        // One list for the whole dialog: its tables, one per kind, share its view and its columns.
        $records = $this->screen->statusRows($profile);
        $page = $this->listTable->of($slot, $list, ProfileStatusColumns::LIST, self::ROUTE.'_statuses', 'taxes-statuses', $records, $this->screen->statusRow(...), $this->screen->statusField(...), static fn (StatusRow $row): string => $row->value, $this->translator->trans('taxes.profile.search'));
        /** @var list<array<string, mixed>> $rows */
        $rows = $page->table['rows'];

        return $this->render('@TaxesAndContributions/profile/statuses.html.twig', [
            'statuses' => $page->with($this->filterSheet->of($list, $page->shown, $page->href, pinned: $page->pinned, slot: $slot)),
            'groups' => $this->screen->statusGroups($records, $rows),
        ]);
    }

    #[Route('/taxes-and-contributions/profile/statuses/new', name: self::ROUTE.'_status_new', methods: ['GET', 'POST'])]
    public function newStatus(Request $request): Response
    {
        $this->denyUnlessOwner();
        $values = ['change' => 'activity:main', 'validFrom' => '', 'base' => ''];
        if (!$request->isMethod('POST')) {
            return $this->statusForm($values, [], Response::HTTP_OK);
        }

        $this->checkToken($request);
        $values = [
            'change' => $request->request->getString('change'),
            'validFrom' => trim($request->request->getString('validFrom')),
            'base' => trim($request->request->getString('base')),
        ];
        [$kind, $value] = array_pad(explode(':', $values['change'], 2), 2, '');
        try {
            $status = $this->writes->addStatus(new AddStatusInput($kind, $value, $values['validFrom'], '' === $values['base'] ? null : TypedNumber::decimal($values['base'], $request->getLocale())));
        } catch (TaxesRefused $refused) {
            $this->denyIfForbidden($refused);
            $field = match ($refused->field()) {
                'base' => 'base',
                'kind', 'value' => 'change',
                default => 'validFrom',
            };

            return $this->statusForm($values, [$field => 'taxes.profile.error.'.$refused->reason()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('success', $this->translator->trans('taxes.profile.status.flash_added', ['value' => $this->screen->statusValue($status)]));

        return $this->redirectToRoute(self::ROUTE.'_statuses');
    }

    #[Route('/taxes-and-contributions/profile/statuses/{id}/remove', name: self::ROUTE.'_status_remove', requirements: ['id' => '[^/]{1,64}'], methods: ['POST'])]
    public function removeStatus(Request $request, string $id): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $this->denyUnlessOwner();
        $this->checkToken($request);
        try {
            $this->writes->removeStatus(new RemoveStatusInput($id));
        } catch (TaxesRefused $refused) {
            $this->denyIfForbidden($refused);

            throw new NotFoundHttpException($refused->getMessage(), $refused);
        }
        $this->addFlash('success', $this->translator->trans('taxes.profile.status.flash_removed'));

        return $this->redirectToRoute(self::ROUTE.'_statuses');
    }

    #[Route('/taxes-and-contributions/profile/years/{year}/{section}', name: self::ROUTE.'_year', requirements: ['year' => '[0-9]{1,4}', 'section' => 'expenses|credits|other'], defaults: ['section' => ProfileScreen::SECTION_EXPENSES], methods: ['GET', 'POST'])]
    public function year(Request $request, int $year, string $section): Response
    {
        if ($year < ProfileWrites::FIRST_YEAR || $year > ProfileWrites::LAST_YEAR) {
            throw new NotFoundHttpException();
        }
        $values = $this->screen->yearValues($this->reads()->year($year));
        if (!$request->isMethod('POST')) {
            return $this->yearForm($year, $section, $values, [], Response::HTTP_OK);
        }

        $this->checkToken($request);
        $form = $request->request->all();
        $text = static fn (string $name): string => \is_string($form[$name] ?? null) ? trim($form[$name]) : '';
        // The form sends one section; the others keep what the year has.
        if (ProfileScreen::SECTION_EXPENSES === $section) {
            $values['expenseMethods'] = array_values(array_filter(\is_array($form['expenseMethods'] ?? null) ? $form['expenseMethods'] : [], \is_string(...)));
            $values['chosenMethod'] = $text('chosenMethod');
        } elseif (ProfileScreen::SECTION_CREDITS === $section) {
            $values['children'] = $this->childRows($form['children'] ?? null);
            $values['spouseMonths'] = $text('spouseMonths');
            $values['spouseZtpP'] = '1' === $text('spouseZtpP');
            $values['spouseLowIncome'] = '1' === $text('spouseLowIncome');
            $values['deductions'] = $this->deductionRows($form['deductions'] ?? null);
        } else {
            $values['employmentBase'] = $text('employmentBase');
            $values['employmentTaxWithheld'] = $text('employmentTaxWithheld');
            $values['rentalBase'] = $text('rentalBase');
            $values['otherBase'] = $text('otherBase');
            $values['lastKnownTax'] = $text('lastKnownTax');
            $values['previousSocialBase'] = $text('previousSocialBase');
            $values['previousHealthBase'] = $text('previousHealthBase');
            $values['previousOverviewFiledOn'] = $text('previousOverviewFiledOn');
            $values['previousActivityMonths'] = $text('previousActivityMonths');
            $values['returnRoute'] = $text('returnRoute');
            $values['activityStart'] = $text('activityStart');
            $values['averageWageOverride'] = $text('averageWageOverride');
        }
        // Add a child and Remove answer with the form again, nothing saved yet (as an invoice's lines).
        $action = $request->request->getString('_action');
        if (ProfileScreen::SECTION_CREDITS === $section && 'add_child' === $action) {
            $values['children'][] = ['label' => '', 'birthDate' => '', 'order' => '', 'ztpP' => false, 'months' => '12', 'new' => true];

            return $this->yearForm($year, $section, $values, [], Response::HTTP_OK);
        }
        if (ProfileScreen::SECTION_CREDITS === $section && 1 === preg_match('/^remove_child:([0-9]{1,2})\z/', $action, $match)) {
            unset($values['children'][(int) $match[1]]);
            $values['children'] = array_values($values['children']);

            return $this->yearForm($year, $section, $values, [], Response::HTTP_OK);
        }
        if ('' !== $values['previousActivityMonths'] && 1 !== preg_match('/^[0-9]{1,2}\z/', $values['previousActivityMonths'])) {
            return $this->yearForm($year, $section, $values, ['previousActivityMonths' => 'taxes.profile.error.activity_months_invalid'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ('' !== $values['spouseMonths'] && 1 !== preg_match('/^[0-9]{1,2}\z/', $values['spouseMonths'])) {
            return $this->yearForm($year, $section, $values, ['spouseMonths' => 'taxes.profile.error.months_invalid'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $locale = $request->getLocale();
        $amount = static fn (string $value): ?string => '' === $value ? null : TypedNumber::decimal($value, $locale);
        $children = [];
        foreach ($values['children'] as $child) {
            // A child left without a name and a birth date is not saved.
            if ('' === $child['label'] && '' === $child['birthDate']) {
                continue;
            }
            $months = '' === $child['months'] ? '12' : $child['months'];
            $children[] = [
                'label' => $child['label'],
                'birth_date' => $child['birthDate'],
                'order' => '' === $child['order'] ? null : (int) $child['order'],
                'ztp_p' => $child['ztpP'],
                'months' => 1 === preg_match('/^[0-9]{1,2}\z/', $months) ? (int) $months : -1,
            ];
        }
        $deductions = [];
        foreach ($values['deductions'] as $type => $value) {
            if ('' !== $value) {
                $deductions[] = ['type' => $type, 'amount' => TypedNumber::decimal($value, $locale)];
            }
        }
        try {
            $this->writes->setYear(new SetYearInputsInput(
                (string) $year,
                $values['expenseMethods'],
                '' === $values['chosenMethod'] ? null : $values['chosenMethod'],
                $children,
                '' === $values['spouseMonths'] ? null : (int) $values['spouseMonths'],
                $values['spouseZtpP'],
                $values['spouseLowIncome'],
                $deductions,
                $amount($values['employmentBase']),
                $amount($values['employmentTaxWithheld']),
                $amount($values['rentalBase']),
                $amount($values['otherBase']),
                $amount($values['lastKnownTax']),
                $amount($values['previousSocialBase']),
                $amount($values['previousHealthBase']),
                '' === $values['previousOverviewFiledOn'] ? null : $values['previousOverviewFiledOn'],
                '' === $values['returnRoute'] ? ReturnRoute::Electronic->value : $values['returnRoute'],
                '' === $values['activityStart'] ? null : $values['activityStart'],
                $amount($values['averageWageOverride']),
                '' === $values['previousActivityMonths'] ? null : (int) $values['previousActivityMonths'],
            ));
        } catch (TaxesRefused $refused) {
            $this->denyIfForbidden($refused);
            $field = $refused->field();

            return $this->yearForm($year, $section, $values, [null !== $field && \in_array($field, ProfileScreen::SECTIONS[$section], true) ? $field : 'form' => 'taxes.profile.error.'.$refused->reason()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('success', $this->translator->trans('taxes.profile.year.flash', ['year' => $year]));

        return $this->redirectToRoute(self::ROUTE);
    }

    #[Route('/taxes-and-contributions/profile/authorities/{authority}', name: self::ROUTE.'_authority', requirements: ['authority' => '[a-z_]{1,20}'], methods: ['GET', 'POST'])]
    public function authority(Request $request, string $authority): Response
    {
        $known = Authority::tryFrom($authority) ?? throw new NotFoundHttpException();
        $current = array_find($this->reads()->profile()->authorities, static fn (AuthorityAccountView $set): bool => $set->authority === $known->value);
        $values = $current instanceof AuthorityAccountView ? ['account' => $current->account, 'variableSymbol' => $current->variableSymbol] : ['account' => '', 'variableSymbol' => ''];
        if (!$request->isMethod('POST')) {
            return $this->authorityForm($known, $values, [], Response::HTTP_OK);
        }

        $this->checkToken($request);
        $values = ['account' => trim($request->request->getString('account')), 'variableSymbol' => trim($request->request->getString('variableSymbol'))];
        try {
            $this->writes->setAuthority(new SetAuthorityAccountInput($known->value, $values['account'], $values['variableSymbol']));
        } catch (TaxesRefused $refused) {
            $this->denyIfForbidden($refused);

            return $this->authorityForm($known, $values, [$refused->field() ?? 'account' => 'taxes.profile.error.'.$refused->reason()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('success', $this->translator->trans('taxes.profile.authority.flash', ['authority' => $this->translator->trans('taxes.authority.'.$known->value)]));

        return $this->redirectToRoute(self::ROUTE);
    }

    private function reads(): ProfileReads
    {
        $this->denyUnlessOwner();

        return $this->reads;
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function statusForm(array $values, array $errors, int $status): Response
    {
        return $this->render('@TaxesAndContributions/profile/status.html.twig', [
            'values' => $values,
            'errors' => $errors,
            'choices' => $this->screen->statusChoices(),
        ], new Response(status: $status));
    }

    /**
     * The children as the form sent them, a row for each, filled or not.
     *
     * @return list<array{label: string, birthDate: string, order: string, ztpP: bool, months: string}>
     */
    private function childRows(mixed $sent): array
    {
        $rows = [];
        foreach (\is_array($sent) ? $sent : [] as $child) {
            if (!\is_array($child)) {
                continue;
            }
            $order = \is_string($child['order'] ?? null) ? $child['order'] : '';
            $rows[] = [
                'label' => \is_string($child['label'] ?? null) ? trim($child['label']) : '',
                'birthDate' => \is_string($child['birthDate'] ?? null) ? trim($child['birthDate']) : '',
                'order' => \in_array($order, ['1', '2', '3'], true) ? $order : '',
                'ztpP' => '1' === ($child['ztpP'] ?? null),
                'months' => \is_string($child['months'] ?? null) ? trim($child['months']) : '12',
            ];
        }

        return $rows;
    }

    /**
     * The amount of each deduction as typed, empty when none.
     *
     * @return array<string, string>
     */
    private function deductionRows(mixed $sent): array
    {
        $sent = \is_array($sent) ? $sent : [];
        $rows = [];
        foreach (DeductionType::cases() as $type) {
            $rows[$type->value] = \is_string($sent[$type->value] ?? null) ? trim($sent[$type->value]) : '';
        }

        return $rows;
    }

    /**
     * @param array<string, mixed>  $values
     * @param array<string, string> $errors
     */
    private function yearForm(int $year, string $section, array $values, array $errors, int $status): Response
    {
        return $this->render('@TaxesAndContributions/profile/year.html.twig', [
            'year' => $year,
            'section' => $section,
            'values' => $values,
            'errors' => $errors,
            'methods' => $this->screen->methodChoices(),
            'deductionTypes' => array_map(static fn (DeductionType $type): string => $type->value, DeductionType::cases()),
            'routes' => array_map(static fn (ReturnRoute $route): string => $route->value, ReturnRoute::cases()),
            'maxChildren' => ProfileWrites::MAX_CHILDREN,
        ], new Response(status: $status));
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function authorityForm(Authority $authority, array $values, array $errors, int $status): Response
    {
        return $this->render('@TaxesAndContributions/profile/authority.html.twig', [
            'authority' => $authority->value,
            'values' => $values,
            'errors' => $errors,
        ], new Response(status: $status));
    }

    private function denyUnlessOwner(): void
    {
        if (!$this->access->isOwner()) {
            throw new AccessDeniedHttpException('Only the owner of the organisation sees the taxes and contributions.');
        }
    }

    private function denyIfForbidden(TaxesRefused $refused): void
    {
        if (RefusalKind::Forbidden === $refused->kind()) {
            throw new AccessDeniedHttpException();
        }
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
