<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Controller;

use App\Identity\Application\OwnerCheck;
use App\Invoicing\Application\FirmInvoicingSettings;
use App\Invoicing\Application\NumberCounter;
use App\Invoicing\Application\SeriesCatalog;
use App\Invoicing\Application\UnknownSeries;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\FirmInvoicing;
use App\Invoicing\Domain\InvalidSeriesFormat;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\SeriesFormat;
use App\Invoicing\Domain\TotalRounding;
use App\Invoicing\Infrastructure\Form\FirmInvoicingReader;
use App\Party\Application\LegalEntities;
use App\Party\Application\TransactionalWork;
use App\Party\Application\UnknownLegalEntity;
use App\Party\Domain\LegalEntity;
use App\Shared\Infrastructure\Http\SubmissionTokens;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The invoicing settings and number series of a firm, for the owner, worked like
 * the firm form. A firm or series of another tenant is never found, so it answers
 * 404 exactly like one that does not exist; a series of another firm does too.
 */
final class FirmInvoicingController extends AbstractController
{
    public const string SETTINGS_TOKEN = 'firm_invoicing_form';
    public const string SERIES_TOKEN = 'series_form';

    public function __construct(
        private readonly LegalEntities $firms,
        private readonly FirmInvoicingSettings $settings,
        private readonly SeriesCatalog $catalog,
        private readonly NumberCounter $counter,
        private readonly FirmInvoicingReader $reader,
        private readonly SubmissionTokens $tokens,
        private readonly TransactionalWork $transaction,
        private readonly OwnerCheck $owner,
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/settings/firms/{id}/invoicing', name: 'app_firm_invoicing', requirements: ['id' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function settings(string $id, Request $request): Response
    {
        $this->denyUnlessOwner();
        $firm = $this->firm($id);
        $settings = $this->settings->for($firm);
        if (!$request->isMethod('POST')) {
            return $this->settingsForm($firm, $this->valuesOf($settings), [], Response::HTTP_OK);
        }

        $this->checkToken($request, self::SETTINGS_TOKEN);
        $submission = $this->reader->settings($request);
        $rounding = TotalRounding::tryFrom($submission->values['rounding']);
        if ([] !== $submission->errors || !$rounding instanceof TotalRounding) {
            return $this->settingsForm($firm, $submission->values, $submission->errors, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $values = $submission->values;
        try {
            $this->transaction->run(function () use ($settings, $rounding, $values): void {
                $settings->change($rounding, $values['constantSymbol'], $values['textBefore'], $values['textAfter'], $values['contactEmail'], $values['contactPhone'], $values['contactWeb']);
                $this->settings->save($settings);
            });
        } catch (UniqueConstraintViolationException) {
            // Another request stored the first settings of this firm meanwhile.
            return $this->settingsForm($firm, $values, ['form' => ['firm.invoicing.error.save_failed']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('success', $this->translator->trans('firm.invoicing.flash.saved'));

        return $this->redirectToRoute('app_firm_invoicing', ['id' => $id]);
    }

    #[Route('/settings/firms/{id}/invoicing/series/new', name: 'app_series_new', requirements: ['id' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function newSeries(string $id, Request $request): Response
    {
        $this->denyUnlessOwner();
        $firm = $this->firm($id);
        if (!$request->isMethod('POST')) {
            $values = ['name' => '', 'format' => Series::STANDARD_FORMAT, 'nextNumber' => '1', 'default' => ''];

            return $this->seriesForm($firm, null, $values, [], $this->tokens->issue(), Response::HTTP_OK);
        }

        $this->checkToken($request, self::SERIES_TOKEN);
        $token = $request->request->getString('_submission');
        if ($this->tokens->isIssued($token) && null !== $this->tokens->createdId($token)) {
            // The same submission again: it is in the list already.
            return $this->redirectToRoute('app_firm_invoicing', ['id' => $id]);
        }

        return $this->saveSeries($request, $firm, null, $token);
    }

    #[Route('/settings/firms/{id}/invoicing/series/{series}/edit', name: 'app_series_edit', requirements: ['id' => Requirement::UUID_V7, 'series' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function editSeries(string $id, string $series, Request $request): Response
    {
        $this->denyUnlessOwner();
        $firm = $this->firm($id);
        $stored = $this->seriesOf($firm, $series);
        if (!$request->isMethod('POST')) {
            return $this->seriesForm($firm, $stored, $this->seriesValues($stored), [], '', Response::HTTP_OK);
        }

        $this->checkToken($request, self::SERIES_TOKEN);

        return $this->saveSeries($request, $firm, $stored, '');
    }

    private function saveSeries(Request $request, LegalEntity $firm, ?Series $series, string $token): Response
    {
        $submission = $this->reader->series($request);
        $values = $submission->values;
        $errors = $submission->errors;
        $format = $this->format($firm, $series, $values, $errors);
        if (!$series instanceof Series && !$this->tokens->isIssued($token)) {
            $token = $this->tokens->issue();
            $errors = ['form' => ['party.error.expired']] + $errors;
        }
        if ($this->nameIsTaken($firm, $series, $values['name'])) {
            $errors['name'] = ['series.error.name_taken'];
        }
        if ($format instanceof SeriesFormat) {
            $this->checkNextNumber($series, $format, $values['nextNumber'], $errors);
        }
        if ([] !== $errors || !$format instanceof SeriesFormat) {
            return $this->seriesForm($firm, $series, $values, $errors, $token, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $saved = $this->transaction->run(fn (): Series => $this->store($firm, $series, $format, $values));
        } catch (UniqueConstraintViolationException) {
            // Another request took the name or the default after the checks above.
            return $this->seriesForm($firm, $series, $values, ['name' => ['series.error.name_taken']], $token, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!$series instanceof Series) {
            $this->tokens->complete($token, $saved->id());
        }
        $this->addFlash('success', $this->translator->trans('series.flash.saved'));

        return $this->redirectToRoute('app_firm_invoicing', ['id' => $firm->id()]);
    }

    /**
     * @param array<string, string> $values
     */
    private function store(LegalEntity $firm, ?Series $series, SeriesFormat $format, array $values): Series
    {
        $target = $series;
        if ($target instanceof Series) {
            $target->rename($values['name']);
            $target->changeFormat($format);
        } else {
            $target = new Series($firm, $values['name'], $format, DocumentKind::Invoice);
            $this->catalog->add($target);
        }

        $period = $format->period($this->today());
        if ('' !== $values['nextNumber'] && (int) $values['nextNumber'] !== $this->nextNumber($target, $period)) {
            $target->startAt((int) $values['nextNumber'], $period);
        }
        $hasDefault = array_any($this->catalog->forFirm($firm), static fn (Series $other): bool => $other->isDefault());
        if ('1' === $values['default'] || !$hasDefault) {
            $this->catalog->makeDefault($target);
        }

        return $target;
    }

    /**
     * The format to store, or null with the error set. A locked format stays, and
     * a browser does not send its disabled field at all.
     *
     * @param array<string, string>                                      $values
     * @param array<string, array{0: string, 1?: array<string, string>}> $errors
     */
    private function format(LegalEntity $firm, ?Series $series, array &$values, array &$errors): ?SeriesFormat
    {
        if ($series instanceof Series && $this->catalog->hasDocuments($series)) {
            $stored = $series->format();
            if ('' !== $values['format'] && $values['format'] !== $stored->toString()) {
                $errors['format'] = ['series.error.format_locked'];
            }
            $values['format'] = $stored->toString();

            return $stored;
        }

        try {
            $format = SeriesFormat::fromString($values['format']);
        } catch (InvalidSeriesFormat) {
            $errors['format'] = ['series.error.format'];

            return null;
        }

        foreach ($this->catalog->forFirm($firm) as $other) {
            if ($other !== $series && $other->format()->collidesWith($format)) {
                $errors['format'] = ['series.error.format_taken', ['name' => $this->seriesName($other)]];

                return null;
            }
        }

        return $format;
    }

    /**
     * The next number can jump forward, or come back from such a jump down to where
     * the counter stands; never into numbers already given.
     *
     * @param array<string, array{0: string, 1?: array<string, string>}> $errors
     */
    private function checkNextNumber(?Series $series, SeriesFormat $format, string $nextNumber, array &$errors): void
    {
        if (!$series instanceof Series || '' === $nextNumber || isset($errors['nextNumber'])) {
            return;
        }

        $last = $this->counter->last($series, $format->period($this->today()));
        if ((int) $nextNumber <= $last) {
            $errors['nextNumber'] = ['series.error.next_below_counter', ['next' => (string) ($last + 1)]];
        }
    }

    private function nameIsTaken(LegalEntity $firm, ?Series $series, string $name): bool
    {
        return array_any($this->catalog->forFirm($firm), static fn (Series $other): bool => $other !== $series && $other->name() === $name);
    }

    /**
     * @param array<string, string>                                      $values
     * @param array<string, array{0: string, 1?: array<string, string>}> $errors
     */
    private function settingsForm(LegalEntity $firm, array $values, array $errors, int $status): Response
    {
        $series = $this->catalog->forFirm($firm);

        return $this->render('invoicing/firm/settings.html.twig', [
            'firm' => $firm,
            'values' => $values,
            'errors' => $this->messages($errors),
            'roundings' => $this->roundings(),
            'columns' => array_map(fn (string $key): array => ['key' => $key, 'label' => $this->translator->trans('series.column.'.$key)], ['name', 'format', 'next', 'default']),
            'rows' => array_map(fn (Series $one): array => $this->row($firm, $one), $series),
            'firstNumber' => $this->counter->peek($this->catalog->defaultFor($firm), $this->today()),
            'action' => $this->generateUrl('app_firm_invoicing', ['id' => $firm->id()]),
            'cancel' => $this->generateUrl('app_firm_edit', ['id' => $firm->id()]),
        ], new Response(status: $status));
    }

    /**
     * @param array<string, string>                                      $values
     * @param array<string, array{0: string, 1?: array<string, string>}> $errors
     */
    private function seriesForm(LegalEntity $firm, ?Series $series, array $values, array $errors, string $token, int $status): Response
    {
        return $this->render('invoicing/firm/series_form.html.twig', [
            'firm' => $firm,
            'series' => $series,
            'seriesName' => $series instanceof Series ? $this->seriesName($series) : '',
            'locked' => $series instanceof Series && $this->catalog->hasDocuments($series),
            'values' => $values,
            'errors' => $this->messages($errors),
            'token' => $token,
            'action' => $series instanceof Series ? $this->generateUrl('app_series_edit', ['id' => $firm->id(), 'series' => $series->id()]) : $this->generateUrl('app_series_new', ['id' => $firm->id()]),
            'settings' => $this->generateUrl('app_firm_invoicing', ['id' => $firm->id()]),
            'firmUrl' => $this->generateUrl('app_firm_edit', ['id' => $firm->id()]),
            'firmsUrl' => $this->generateUrl('app_firm_index'),
        ], new Response(status: $status));
    }

    /**
     * @param array<string, array{0: string, 1?: array<string, string>}> $errors
     *
     * @return array<string, string>
     */
    private function messages(array $errors): array
    {
        return array_map(fn (array $error): string => $this->translator->trans($error[0], $error[1] ?? []), $errors);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(LegalEntity $firm, Series $series): array
    {
        return [
            'id' => $series->id(),
            'label' => $this->seriesName($series),
            'href' => $this->generateUrl('app_series_edit', ['id' => $firm->id(), 'series' => $series->id()]),
            'cells' => [
                'name' => $this->seriesName($series),
                'format' => $series->format()->toString(),
                'next' => $this->counter->peek($series, $this->today()),
                'default' => $series->isDefault() ? ['text' => $this->translator->trans('series.default'), 'tone' => 'green'] : null,
            ],
        ];
    }

    private function seriesName(Series $series): string
    {
        return Series::STANDARD === $series->name() ? $this->translator->trans('series.name.standard') : $series->name();
    }

    /**
     * @return array<string, string>
     */
    private function roundings(): array
    {
        $options = [];
        foreach (TotalRounding::cases() as $rounding) {
            $options[$rounding->value] = $this->translator->trans('firm.invoicing.rounding.'.$rounding->value);
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function valuesOf(FirmInvoicing $settings): array
    {
        return [
            'rounding' => $settings->rounding()->value,
            'constantSymbol' => $settings->constantSymbol(),
            'textBefore' => $settings->textBefore(),
            'textAfter' => $settings->textAfter(),
            'contactEmail' => $settings->contactEmail(),
            'contactPhone' => $settings->contactPhone(),
            'contactWeb' => $settings->contactWeb(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function seriesValues(Series $series): array
    {
        return [
            'name' => $series->name(),
            'format' => $series->format()->toString(),
            'nextNumber' => (string) $this->nextNumber($series, $series->format()->period($this->today())),
            'default' => $series->isDefault() ? '1' : '',
        ];
    }

    private function nextNumber(Series $series, string $period): int
    {
        return max($this->counter->last($series, $period) + 1, $series->startFor($period));
    }

    private function today(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }

    private function firm(string $id): LegalEntity
    {
        try {
            return $this->firms->get($id);
        } catch (UnknownLegalEntity) {
            throw new NotFoundHttpException();
        }
    }

    private function seriesOf(LegalEntity $firm, string $id): Series
    {
        try {
            $series = $this->catalog->get($id);
        } catch (UnknownSeries) {
            throw new NotFoundHttpException();
        }

        return $series->firm()->id() === $firm->id() ? $series : throw new NotFoundHttpException();
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
