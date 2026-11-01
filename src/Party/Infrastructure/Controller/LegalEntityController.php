<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Controller;

use App\Identity\Application\OwnerCheck;
use App\Party\Application\FirmImages;
use App\Party\Application\LegalEntities;
use App\Party\Application\LegalEntityInput;
use App\Party\Application\TransactionalWork;
use App\Party\Application\UnknownLegalEntity;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\VatStatus;
use App\Party\Infrastructure\Form\CountryOptions;
use App\Party\Infrastructure\Form\LegalEntitySubmission;
use App\Party\Infrastructure\Form\LegalEntitySubmissionReader;
use App\Shared\Infrastructure\Http\SubmissionTokens;
use App\Tenancy\Application\TenantContext;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The firms of the organisation, for its owner: list, form and archiving. The
 * form works like the contact form. A firm of another tenant is never found, so
 * it answers 404 exactly like a firm that does not exist.
 */
final class LegalEntityController extends AbstractController
{
    public const string FORM_TOKEN = 'firm_form';
    public const string ACTION_TOKEN = 'firm_action';

    private const array VIEWS = ['active', 'archived'];
    private const array IMAGE_TOO_LARGE = ['logo' => ['firm.error.image_size']];

    public function __construct(
        private readonly LegalEntities $firms,
        private readonly LegalEntitySubmissionReader $reader,
        private readonly SubmissionTokens $tokens,
        private readonly TransactionalWork $transaction,
        private readonly OwnerCheck $owner,
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
        private readonly FirmImages $images,
        private readonly TenantContext $tenant,
    ) {
    }

    #[Route('/settings/firms', name: 'app_firm_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyUnlessOwner();
        $view = $request->query->getString('view', 'active');
        $view = \in_array($view, self::VIEWS, true) ? $view : 'active';
        $firms = $this->firms->all();
        $archived = array_values(array_filter($firms, static fn (LegalEntity $firm): bool => $firm->isArchived()));
        $active = array_values(array_filter($firms, static fn (LegalEntity $firm): bool => !$firm->isArchived()));
        $shown = 'archived' === $view ? $archived : $active;

        return $this->render('party/legal_entity/index.html.twig', [
            'firstRun' => [] === $firms,
            'tabs' => $this->tabs($view, \count($active), \count($archived)),
            'columns' => array_map(fn (string $key): array => ['key' => $key, 'label' => $this->translator->trans('firm.column.'.$key)], ['name', 'registration', 'vat', 'vat_status']),
            'rows' => array_map($this->row(...), $shown),
            'total' => \count($shown),
        ]);
    }

    #[Route('/settings/firms/new', name: 'app_firm_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyUnlessOwner();
        if (!$request->isMethod('POST')) {
            return $this->form($request, null, $this->defaults(), [], $this->tokens->issue(), Response::HTTP_OK);
        }

        if ($this->bodyWasDropped($request)) {
            return $this->form($request, null, $this->defaults(), self::IMAGE_TOO_LARGE, $this->tokens->issue(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->checkToken($request, self::FORM_TOKEN);
        $token = $request->request->getString('_submission');
        if ($this->tokens->isIssued($token) && null !== $this->tokens->createdId($token)) {
            // The same submission again: it is in the list already.
            return $this->redirectToRoute('app_firm_index');
        }

        $submission = $this->reader->read($request);
        if (!$this->tokens->isIssued($token)) {
            $token = $this->tokens->issue();
            $submission = new LegalEntitySubmission($submission->values, ['form' => ['party.error.expired']] + $submission->errors, null);
        }

        return $this->handle($request, null, $submission, $token);
    }

    #[Route('/settings/firms/{id}/edit', name: 'app_firm_edit', requirements: ['id' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        $this->denyUnlessOwner();
        $firm = $this->firm($id);
        if (!$request->isMethod('POST')) {
            return $this->form($request, $firm, $this->valuesOf($firm), [], '', Response::HTTP_OK);
        }
        if ($this->bodyWasDropped($request)) {
            return $this->form($request, $firm, $this->valuesOf($firm), self::IMAGE_TOO_LARGE, '', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->checkToken($request, self::FORM_TOKEN);

        return $this->handle($request, $firm, $this->reader->read($request), '');
    }

    #[Route('/settings/firms/{id}/archive', name: 'app_firm_archive', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function archive(string $id, Request $request): RedirectResponse
    {
        $this->denyUnlessOwner();
        $this->checkToken($request, self::ACTION_TOKEN);
        $firm = $this->firm($id);
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $archived = $this->transaction->run(function () use ($firm, $now): bool {
            // Under a lock: two requests must not each archive one of the last two firms.
            if ([$firm] === $this->firms->lockActive()) {
                return false;
            }
            $firm->archive($now);

            return true;
        });
        $this->addFlash($archived ? 'success' : 'error', $this->translator->trans($archived ? 'firm.flash.archived' : 'firm.error.last_active'));

        return $this->redirectToRoute('app_firm_index');
    }

    private function handle(Request $request, ?LegalEntity $firm, LegalEntitySubmission $submission, string $token): Response
    {
        $input = $submission->input;
        if (!$submission->valid() || !$input instanceof LegalEntityInput) {
            return $this->form($request, $firm, $submission->values, $submission->errors, $token, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($this->numberIsTaken($input, $firm)) {
            return $this->form($request, $firm, $submission->values, ['registrationNumber' => ['firm.error.registration_taken']], $token, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Stored before the transaction: a file nothing points to does no harm, and files are never deleted.
        $logo = $this->imageName($submission->logo, '1' === $submission->values['removeLogo'], $firm?->logo());
        $stamp = $this->imageName($submission->stamp, '1' === $submission->values['removeStamp'], $firm?->stamp());

        try {
            if ($firm instanceof LegalEntity) {
                $this->transaction->run(static function () use ($firm, $input, $logo, $stamp): void {
                    $firm->update($input->name, $input->registrationNumber, $input->vatId, $input->address, $input->vatStatus, $input->registerEntry, $input->bankAccounts);
                    $firm->changeLogo($logo);
                    $firm->changeStamp($stamp);
                });
            } else {
                $created = new LegalEntity($input->name, $input->registrationNumber, $input->vatId, $input->address, $input->vatStatus, $input->registerEntry, $input->bankAccounts);
                $created->changeLogo($logo);
                $created->changeStamp($stamp);
                $this->transaction->run(fn () => $this->firms->add($created));
                $this->tokens->complete($token, $created->id());
            }
        } catch (UniqueConstraintViolationException) {
            // Another request took the number after the check above.
            return $this->form($request, $firm, $submission->values, ['registrationNumber' => ['firm.error.registration_taken']], $token, Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\DomainException) {
            return $this->form($request, $firm, $submission->values, ['form' => ['firm.error.save_failed']], $token, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash('success', $this->translator->trans($firm instanceof LegalEntity ? 'firm.flash.updated' : 'firm.flash.created'));

        return $this->redirectToRoute('app_firm_index');
    }

    /**
     * A body over post_max_size reaches the controller empty: no token, no file.
     * Only an image can be that large on this form, so it is told like one.
     */
    private function bodyWasDropped(Request $request): bool
    {
        return 0 === $request->request->count() && 0 === $request->files->count() && $request->server->getInt('CONTENT_LENGTH') > 0;
    }

    /**
     * An upload wins over removing, and without either the image stays.
     */
    private function imageName(?string $upload, bool $remove, ?string $current): ?string
    {
        if (null !== $upload) {
            return $this->images->store($this->tenant->current(), $upload);
        }

        return $remove ? null : $current;
    }

    /**
     * An archived firm holds no number, whatever is entered for it.
     */
    private function numberIsTaken(LegalEntityInput $input, ?LegalEntity $firm): bool
    {
        if ($firm instanceof LegalEntity && $firm->isArchived()) {
            return false;
        }

        return array_any($this->firms->all(), static fn (LegalEntity $other): bool => $other !== $firm && $other->holdsRegistrationNumber($input->registrationNumber));
    }

    /**
     * @param array<string, string>                                      $values
     * @param array<string, array{0: string, 1?: array<string, string>}> $errors
     */
    private function form(Request $request, ?LegalEntity $firm, array $values, array $errors, string $token, int $status): Response
    {
        return $this->render('party/legal_entity/form.html.twig', [
            'firm' => $firm,
            'values' => $values,
            'errors' => array_map(fn (array $error): string => $this->translator->trans($error[0], $error[1] ?? []), $errors),
            'token' => $token,
            'statuses' => $this->statusOptions(),
            'countries' => CountryOptions::options($request->getLocale()),
            'action' => $firm instanceof LegalEntity ? $this->generateUrl('app_firm_edit', ['id' => $firm->id()]) : $this->generateUrl('app_firm_new'),
            'cancel' => $this->generateUrl('app_firm_index'),
        ], new Response(status: $status));
    }

    /**
     * @return array<string, string>
     */
    private function statusOptions(): array
    {
        $statuses = [];
        foreach (VatStatus::cases() as $status) {
            $statuses[$status->value] = $this->translator->trans('firm.vat_status.'.$status->value);
        }

        return $statuses;
    }

    /**
     * @return array<string, string>
     */
    private function defaults(): array
    {
        return [
            'name' => '',
            'registrationCountry' => 'CZ',
            'registrationNumber' => '',
            'vatStatus' => VatStatus::Registered->value,
            'vatId' => '',
            'street' => '',
            'city' => '',
            'postalCode' => '',
            'countryCode' => 'CZ',
            'registerEntry' => '',
            'bankAccounts' => '',
            'removeLogo' => '',
            'removeStamp' => '',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function valuesOf(LegalEntity $firm): array
    {
        return [
            'name' => $firm->name(),
            'registrationCountry' => $firm->registrationNumber()->countryCode,
            'registrationNumber' => $firm->registrationNumber()->value,
            'vatStatus' => $firm->vatStatus()->value,
            'vatId' => $firm->vatId()?->toString() ?? '',
            'street' => $firm->address()->street,
            'city' => $firm->address()->city,
            'postalCode' => $firm->address()->postalCode,
            'countryCode' => $firm->address()->countryCode,
            'registerEntry' => $firm->registerEntry(),
            'bankAccounts' => implode("\n", $firm->bankAccounts()),
            'removeLogo' => '',
            'removeStamp' => '',
        ];
    }

    /**
     * @return list<array{label: string, href: string, count: int, active: bool}>
     */
    private function tabs(string $view, int $active, int $archived): array
    {
        $tabs = [];
        foreach (['active' => $active, 'archived' => $archived] as $key => $count) {
            $tabs[] = [
                'label' => $this->translator->trans('firm.view.'.$key),
                'href' => $this->generateUrl('app_firm_index', ['view' => 'active' === $key ? null : $key]),
                'count' => $count,
                'active' => $key === $view,
            ];
        }

        return $tabs;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(LegalEntity $firm): array
    {
        return [
            'id' => $firm->id(),
            'label' => $firm->name(),
            'href' => $this->generateUrl('app_firm_edit', ['id' => $firm->id()]),
            'cells' => [
                'name' => $firm->name(),
                'registration' => $firm->registrationNumber()->value,
                'vat' => $firm->vatId()?->toString(),
                'vat_status' => $this->translator->trans('firm.vat_status.'.$firm->vatStatus()->value),
            ],
        ];
    }

    private function firm(string $id): LegalEntity
    {
        try {
            return $this->firms->get($id);
        } catch (UnknownLegalEntity) {
            throw new NotFoundHttpException();
        }
    }

    private function denyUnlessOwner(): void
    {
        if (!$this->owner->isOwner()) {
            throw new AccessDeniedHttpException('Only the owner manages firms.');
        }
    }

    private function checkToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
