<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Integration\Ui\Controller;

use Foresi\Instance\Application\InstanceClock;
use Foresi\Party\Application\FirmAlreadyExists;
use Foresi\Party\Application\FirmImages;
use Foresi\Party\Application\InstanceFirm;
use Foresi\Party\Application\LegalEntityInput;
use Foresi\Party\Application\VatStatusWrites;
use Foresi\Party\Contract\FirmVatStatuses;
use Foresi\Party\Contract\VatStretch;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\VatStatus;
use Foresi\Party\Infrastructure\Form\CountryOptions;
use Foresi\Party\Integration\Ui\Form\LegalEntitySubmission;
use Foresi\Party\Integration\Ui\Form\LegalEntitySubmissionReader;
use Foresi\Party\Integration\Ui\View\VatStretchLabel;
use Foresi\Shared\Application\ActorRole;
use Foresi\Shared\Application\TransactionalWork;
use Foresi\Shared\Infrastructure\Http\SubmissionTokens;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The firm of the instance, for its owner: one page that sets it up on the
 * first run and edits it after. The form works like the contact form.
 */
final class LegalEntityController extends AbstractController
{
    public const string FORM_TOKEN = 'firm_form';

    private const array IMAGE_TOO_LARGE = ['logo' => ['firm.error.image_size']];

    public function __construct(
        private readonly LegalEntitySubmissionReader $reader,
        private readonly SubmissionTokens $tokens,
        private readonly TransactionalWork $transaction,
        private readonly ActorRole $role,
        private readonly TranslatorInterface $translator,
        private readonly FirmImages $images,
        private readonly InstanceFirm $instanceFirm,
        private readonly VatStatusWrites $vatStatuses,
        private readonly FirmVatStatuses $vatStretches,
        private readonly InstanceClock $clock,
        private readonly VatStretchLabel $vatLabel,
    ) {
    }

    /**
     * The one firm of the instance (ADR 0039). Before the first-run setup there
     * is none, and the page sets it up; after that it edits it.
     */
    #[Route('/settings/firm', name: 'app_firm', methods: ['GET', 'POST'])]
    public function settings(Request $request): Response
    {
        $this->denyUnlessOwner();
        $firm = $this->instanceFirm->find();

        return $firm instanceof LegalEntity ? $this->edit($firm, $request) : $this->setUp($request);
    }

    /**
     * The firm settings lived under /settings/firms with the firm's id in the
     * path while an instance could hold several firms. Old bookmarks and links
     * land on the same page without the id; 308 keeps the method of a form
     * sent from a page opened before the change.
     */
    #[Route('/settings/firms/{rest}', name: 'app_firm_legacy', requirements: ['rest' => '.*'], defaults: ['rest' => ''], methods: ['GET', 'HEAD', 'POST'])]
    public function legacy(string $rest, Request $request): RedirectResponse
    {
        $parts = '' === $rest ? [] : explode('/', $rest);
        if ([] !== $parts && 1 === preg_match('#^'.Requirement::UUID_V7.'$#D', $parts[0])) {
            array_shift($parts);
        }
        // The list, the new-firm screen and a firm's own form are all the firm page now.
        if (\in_array($parts, [[], ['new'], ['edit']], true)) {
            $parts = [];
        }
        $query = $request->getQueryString();

        return new RedirectResponse('/settings/firm'.([] === $parts ? '' : '/'.implode('/', $parts)).(null === $query ? '' : '?'.$query), Response::HTTP_PERMANENTLY_REDIRECT);
    }

    private function setUp(Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->form($request, null, $this->defaults(), [], $this->tokens->issue(), Response::HTTP_OK);
        }

        if ($this->bodyWasDropped($request)) {
            return $this->form($request, null, $this->defaults(), self::IMAGE_TOO_LARGE, $this->tokens->issue(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->checkToken($request);
        $token = $request->request->getString('_submission');
        $submission = $this->reader->read($request);
        if (!$this->tokens->isIssued($token)) {
            $token = $this->tokens->issue();
            $submission = new LegalEntitySubmission($submission->values, ['form' => ['party.error.expired']] + $submission->errors, null);
        }

        return $this->handle($request, null, $submission, $token);
    }

    private function edit(LegalEntity $firm, Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->form($request, $firm, $this->valuesOf($firm), [], '', Response::HTTP_OK);
        }
        if ($this->bodyWasDropped($request)) {
            return $this->form($request, $firm, $this->valuesOf($firm), self::IMAGE_TOO_LARGE, '', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->checkToken($request);
        $token = $request->request->getString('_submission');
        if ('' !== $token) {
            // A setup form sent after the firm was set up: again after it saved the firm, or
            // from another tab, which would have been a second firm.
            return null !== $this->tokens->createdId($token) ? $this->redirectToRoute('app_firm') : $this->refuseAnotherFirm();
        }

        return $this->handle($request, $firm, $this->reader->read($request, $firm->vatStatus()), '');
    }

    private function handle(Request $request, ?LegalEntity $firm, LegalEntitySubmission $submission, string $token): Response
    {
        $input = $submission->input;
        if (!$submission->valid() || !$input instanceof LegalEntityInput) {
            return $this->form($request, $firm, $submission->values, $submission->errors, $token, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Stored before the transaction: a file nothing points to does no harm, and files are never deleted.
        $logo = $this->imageName($submission->logo, '1' === $submission->values['removeLogo'], $firm?->logo());
        $stamp = $this->imageName($submission->stamp, '1' === $submission->values['removeStamp'], $firm?->stamp());

        try {
            if ($firm instanceof LegalEntity) {
                $this->transaction->run(static function () use ($firm, $input, $logo, $stamp): void {
                    $firm->update($input->name, $input->registrationNumber, $input->vatId, $input->address, $input->vatStatus, $input->registerEntry);
                    $firm->changeLogo($logo);
                    $firm->changeStamp($stamp);
                });
            } else {
                $created = new LegalEntity($input->name, $input->registrationNumber, $input->vatId, $input->address, $input->vatStatus, $input->registerEntry);
                $created->changeLogo($logo);
                $created->changeStamp($stamp);
                $this->transaction->run(function () use ($created, $input): void {
                    $this->instanceFirm->create($created);
                    $this->vatStatuses->start($created, $input->vatValidFrom);
                });
                $this->tokens->complete($token, $created->id());
            }
        } catch (FirmAlreadyExists) {
            // Another request set up the firm after the check in new().
            return $this->refuseAnotherFirm();
        } catch (\DomainException) {
            return $this->form($request, $firm, $submission->values, ['form' => ['firm.error.save_failed']], $token, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash('success', $this->translator->trans($firm instanceof LegalEntity ? 'firm.flash.updated' : 'firm.flash.created'));

        return $this->redirectToRoute('app_firm');
    }

    private function refuseAnotherFirm(): RedirectResponse
    {
        $this->addFlash('error', $this->translator->trans('firm.error.one_per_instance'));

        return $this->redirectToRoute('app_firm');
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
            return $this->images->store($upload);
        }

        return $remove ? null : $current;
    }

    /**
     * @param array<string, string>                                      $values
     * @param array<string, array{0: string, 1?: array<string, string>}> $errors
     */
    private function form(Request $request, ?LegalEntity $firm, array $values, array $errors, string $token, int $status): Response
    {
        $bankUrl = $this->bankUrl();

        return $this->render('@Party/legal_entity/form.html.twig', [
            'firm' => $firm,
            'values' => $values,
            'errors' => array_map(fn (array $error): string => $this->translator->trans($error[0], $error[1] ?? []), $errors),
            'token' => $token,
            'statuses' => $this->statusOptions(),
            'countries' => CountryOptions::options($request->getLocale()),
            'action' => $this->generateUrl('app_firm'),
            'cancel' => $this->generateUrl('app_firm'),
            // The firm's bank accounts are kept on the bank tab of Banking, when it is installed;
            // a new firm gets them once it is saved.
            'banking' => null !== $bankUrl,
            'bankUrl' => $firm instanceof LegalEntity ? $bankUrl : null,
            // An edited firm shows its status of today; its statuses over time change on their tab.
            'vatToday' => $firm instanceof LegalEntity ? $this->vatToday() : null,
            'vatUrl' => $firm instanceof LegalEntity ? $this->generateUrl('app_firm_vat') : null,
        ], new Response(status: $status));
    }

    /**
     * The bank tab of the firm, or null without Banking: Party knows that screen
     * by its route only.
     */
    private function bankUrl(): ?string
    {
        try {
            return $this->generateUrl('app_firm_bank');
        } catch (RouteNotFoundException) {
            return null;
        }
    }

    /**
     * The status that holds today, with the period length of a payer.
     */
    private function vatToday(): string
    {
        $stretches = $this->vatStretches->stretches();
        $today = $this->vatStretches->on($this->clock->today()) ?? $stretches[0] ?? null;

        return $today instanceof VatStretch ? $this->vatLabel->of($today) : '';
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
            'vatValidFrom' => '',
            'vatId' => '',
            'street' => '',
            'city' => '',
            'postalCode' => '',
            'countryCode' => 'CZ',
            'registerEntry' => '',
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
            'vatValidFrom' => '',
            'vatId' => $firm->vatId()?->toString() ?? '',
            'street' => $firm->address()->street,
            'city' => $firm->address()->city,
            'postalCode' => $firm->address()->postalCode,
            'countryCode' => $firm->address()->countryCode,
            'registerEntry' => $firm->registerEntry(),
            'removeLogo' => '',
            'removeStamp' => '',
        ];
    }

    private function denyUnlessOwner(): void
    {
        if (!$this->role->isOwner()) {
            throw new AccessDeniedHttpException('Only the owner manages firms.');
        }
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::FORM_TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
