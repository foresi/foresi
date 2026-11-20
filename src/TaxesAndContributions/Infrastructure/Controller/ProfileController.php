<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Infrastructure\Controller;

use Foresi\Shared\Application\RefusalKind;
use Foresi\Shared\Infrastructure\Http\TypedNumber;
use Foresi\TaxesAndContributions\Application\AddStatusInput;
use Foresi\TaxesAndContributions\Application\ProfileReads;
use Foresi\TaxesAndContributions\Application\ProfileWrites;
use Foresi\TaxesAndContributions\Application\RemoveStatusInput;
use Foresi\TaxesAndContributions\Application\SetAuthorityAccountInput;
use Foresi\TaxesAndContributions\Application\SetYearInputsInput;
use Foresi\TaxesAndContributions\Application\TaxesAccess;
use Foresi\TaxesAndContributions\Application\TaxesRefused;
use Foresi\TaxesAndContributions\Contract\Authority;
use Foresi\TaxesAndContributions\Contract\AuthorityAccountView;
use Foresi\TaxesAndContributions\Contract\DeductionType;
use Foresi\TaxesAndContributions\Contract\ReturnRoute;
use Foresi\TaxesAndContributions\Infrastructure\Screen\ProfileScreen;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The owner profile under Money → Taxes and contributions: the statuses as a
 * dated list per kind, with "Add a change" in the page header opening a dialog;
 * the inputs of a year as a form in the side sheet; and the account of each
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
    ) {
    }

    #[Route('/taxes-and-contributions/profile', name: self::ROUTE, methods: ['GET'])]
    public function index(): Response
    {
        $profile = $this->reads()->profile();

        return $this->render('@TaxesAndContributions/profile/index.html.twig', [
            'columns' => $this->screen->statusColumns(),
            'groups' => $this->screen->statusGroups($profile),
            'years' => $this->screen->years($profile),
            'authorities' => $this->screen->authorities($profile),
        ]);
    }

    #[Route('/taxes-and-contributions/profile/statuses/new', name: self::ROUTE.'_status_new', methods: ['GET', 'POST'])]
    public function newStatus(Request $request): Response
    {
        $this->denyUnlessOwner();
        $values = ['change' => 'vat_payer:yes', 'validFrom' => '', 'base' => ''];
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

        return $this->redirectToRoute(self::ROUTE);
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

        return $this->redirectToRoute(self::ROUTE);
    }

    #[Route('/taxes-and-contributions/profile/years/{year}', name: self::ROUTE.'_year', requirements: ['year' => '[0-9]{1,4}'], methods: ['GET', 'POST'])]
    public function year(Request $request, int $year): Response
    {
        if ($year < ProfileWrites::FIRST_YEAR || $year > ProfileWrites::LAST_YEAR) {
            throw new NotFoundHttpException();
        }
        $inputs = $this->reads()->year($year);
        if (!$request->isMethod('POST')) {
            return $this->yearForm($year, $this->screen->yearValues($inputs), [], Response::HTTP_OK);
        }

        $this->checkToken($request);
        $locale = $request->getLocale();
        $form = $request->request->all();
        $text = static fn (string $name): string => \is_string($form[$name] ?? null) ? trim($form[$name]) : '';
        $amount = static fn (string $value): ?string => '' === $value ? null : TypedNumber::decimal($value, $locale);
        $children = [];
        $childValues = [];
        foreach (\is_array($form['children'] ?? null) ? $form['children'] : [] as $child) {
            if (!\is_array($child)) {
                continue;
            }
            $row = [
                'label' => \is_string($child['label'] ?? null) ? trim($child['label']) : '',
                'birthDate' => \is_string($child['birthDate'] ?? null) ? trim($child['birthDate']) : '',
                'order' => \is_string($child['order'] ?? null) ? $child['order'] : '1',
                'ztpP' => '1' === ($child['ztpP'] ?? null),
                'months' => \is_string($child['months'] ?? null) ? trim($child['months']) : '',
            ];
            $childValues[] = $row;
            if ('' === $row['label'] && '' === $row['birthDate']) {
                continue;
            }
            $children[] = [
                'label' => $row['label'],
                'birth_date' => $row['birthDate'],
                'order' => (int) $row['order'],
                'ztp_p' => $row['ztpP'],
                'months' => 1 === preg_match('/^[0-9]{1,2}\z/', $row['months']) ? (int) $row['months'] : -1,
            ];
        }
        $deductionValues = [];
        $deductions = [];
        $sent = \is_array($form['deductions'] ?? null) ? $form['deductions'] : [];
        foreach (DeductionType::cases() as $type) {
            $value = \is_string($sent[$type->value] ?? null) ? trim($sent[$type->value]) : '';
            $deductionValues[$type->value] = $value;
            if ('' !== $value) {
                $deductions[] = ['type' => $type->value, 'amount' => TypedNumber::decimal($value, $locale)];
            }
        }
        $methods = array_values(array_filter(\is_array($form['expenseMethods'] ?? null) ? $form['expenseMethods'] : [], \is_string(...)));
        $values = [
            'expenseMethods' => $methods,
            'chosenMethod' => $text('chosenMethod'),
            'children' => [...$childValues, ...([] === $childValues ? [['label' => '', 'birthDate' => '', 'order' => '1', 'ztpP' => false, 'months' => '12']] : [])],
            'spouseMonths' => $text('spouseMonths'),
            'spouseZtpP' => '1' === $text('spouseZtpP'),
            'spouseLowIncome' => '1' === $text('spouseLowIncome'),
            'deductions' => $deductionValues,
        ];
        foreach (['employmentBase', 'employmentTaxWithheld', 'rentalBase', 'otherBase', 'lastKnownTax', 'previousSocialBase', 'previousHealthBase', 'previousOverviewFiledOn', 'returnRoute', 'activityStart', 'averageWageOverride'] as $name) {
            $values[$name] = $text($name);
        }
        if ('' !== $values['spouseMonths'] && 1 !== preg_match('/^[0-9]{1,2}\z/', $values['spouseMonths'])) {
            return $this->yearForm($year, $values, ['spouseMonths' => 'taxes.profile.error.months_invalid'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $this->writes->setYear(new SetYearInputsInput(
                (string) $year,
                $methods,
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
            ));
        } catch (TaxesRefused $refused) {
            $this->denyIfForbidden($refused);

            return $this->yearForm($year, $values, [$refused->field() ?? 'form' => 'taxes.profile.error.'.$refused->reason()], Response::HTTP_UNPROCESSABLE_ENTITY);
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
     * @param array<string, mixed>  $values
     * @param array<string, string> $errors
     */
    private function yearForm(int $year, array $values, array $errors, int $status): Response
    {
        return $this->render('@TaxesAndContributions/profile/year.html.twig', [
            'year' => $year,
            'values' => $values,
            'errors' => $errors,
            'methods' => $this->screen->methodChoices(),
            'deductionTypes' => array_map(static fn (DeductionType $type): string => $type->value, DeductionType::cases()),
            'routes' => array_map(static fn (ReturnRoute $route): string => $route->value, ReturnRoute::cases()),
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
