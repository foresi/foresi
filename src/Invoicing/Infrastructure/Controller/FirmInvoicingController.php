<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Controller;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Foresi\Identity\Application\OwnerCheck;
use Foresi\Invoicing\Application\FirmInvoicingSettings;
use Foresi\Invoicing\Application\InvoicingCountries;
use Foresi\Invoicing\Application\NumberCounter;
use Foresi\Invoicing\Application\SeriesCatalog;
use Foresi\Invoicing\Application\UnknownSeries;
use Foresi\Invoicing\Application\UnsupportedCountry;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Contract\TotalRounding;
use Foresi\Invoicing\Domain\FirmInvoicing;
use Foresi\Invoicing\Domain\InvalidSeriesFormat;
use Foresi\Invoicing\Domain\Series;
use Foresi\Invoicing\Domain\SeriesFormat;
use Foresi\Invoicing\Infrastructure\Form\FirmInvoicingReader;
use Foresi\Party\Application\InstanceFirm;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Shared\Application\TransactionalWork;
use Foresi\Shared\Infrastructure\Http\SubmissionTokens;
use Foresi\Ui\Contract\Opens;
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
 * the firm form. Without a firm, or for an unknown series, it answers 404.
 */
final class FirmInvoicingController extends AbstractController
{
    public const string SETTINGS_TOKEN = 'firm_invoicing_form';
    public const string SERIES_TOKEN = 'series_form';

    public function __construct(
        private readonly InstanceFirm $instanceFirm,
        private readonly FirmInvoicingSettings $settings,
        private readonly SeriesCatalog $catalog,
        private readonly NumberCounter $counter,
        private readonly FirmInvoicingReader $reader,
        private readonly SubmissionTokens $tokens,
        private readonly TransactionalWork $transaction,
        private readonly OwnerCheck $owner,
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
        private readonly InvoicingCountries $countries,
    ) {
    }

    #[Route('/settings/firm/invoicing', name: 'app_firm_invoicing', methods: ['GET', 'POST'])]
    public function settings(Request $request): Response
    {
        $this->denyUnlessOwner();
        $this->firm();
        $settings = $this->settings->get();
        if (!$request->isMethod('POST')) {
            return $this->settingsForm($this->valuesOf($settings), [], Response::HTTP_OK);
        }

        $this->checkToken($request, self::SETTINGS_TOKEN);
        $submission = $this->reader->settings($request);
        $rounding = TotalRounding::tryFrom($submission->values['rounding']);
        if ([] !== $submission->errors || !$rounding instanceof TotalRounding) {
            return $this->settingsForm($submission->values, $submission->errors, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $values = $submission->values;
        try {
            $this->transaction->run(function () use ($settings, $rounding, $values): void {
                $settings->change($rounding, $values['constantSymbol'], $values['textBefore'], $values['textAfter'], $values['contactEmail'], $values['contactPhone'], $values['contactWeb']);
                $this->settings->save($settings);
            });
        } catch (UniqueConstraintViolationException) {
            // Another request stored the first settings meanwhile.
            return $this->settingsForm($values, ['form' => ['firm.invoicing.error.save_failed']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('success', $this->translator->trans('firm.invoicing.flash.saved'));

        return $this->redirectToRoute('app_firm_invoicing');
    }

    #[Route('/settings/firm/invoicing/series/new', name: 'app_series_new', methods: ['GET', 'POST'])]
    public function newSeries(Request $request): Response
    {
        $this->denyUnlessOwner();
        $this->firm();
        if (!$request->isMethod('POST')) {
            $kind = DocumentKind::tryFrom($request->query->getString('kind')) ?? DocumentKind::Invoice;
            $values = ['name' => '', 'format' => $kind->standardSeriesFormat(), 'startYear' => $this->today()->format('Y'), 'nextNumber' => '', 'default' => '', 'kind' => $kind->value];

            return $this->seriesForm(null, $values, [], $this->tokens->issue(), Response::HTTP_OK);
        }

        $this->checkToken($request, self::SERIES_TOKEN);
        $token = $request->request->getString('_submission');
        if ($this->tokens->isIssued($token) && null !== $this->tokens->createdId($token)) {
            // The same submission again: it is in the list already.
            return $this->redirectToRoute('app_firm_invoicing');
        }

        return $this->saveSeries($request, null, $token);
    }

    #[Route('/settings/firm/invoicing/series/{series}/edit', name: 'app_series_edit', requirements: ['series' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function editSeries(string $series, Request $request): Response
    {
        $this->denyUnlessOwner();
        $this->firm();
        $stored = $this->seriesOf($series);
        if (!$request->isMethod('POST')) {
            return $this->seriesForm($stored, $this->seriesValues($stored), [], '', Response::HTTP_OK);
        }

        $this->checkToken($request, self::SERIES_TOKEN);

        return $this->saveSeries($request, $stored, '');
    }

    private function saveSeries(Request $request, ?Series $series, string $token): Response
    {
        $submission = $this->reader->series($request);
        $values = $submission->values;
        $errors = $submission->errors;
        // The kind of a stored series stays; a browser does not send its disabled field.
        if ($series instanceof Series) {
            $values['kind'] = $series->kind()->value;
            unset($errors['kind']);
        }
        $format = $this->format($series, $values, $errors);
        if (!$series instanceof Series && !$this->tokens->isIssued($token)) {
            $token = $this->tokens->issue();
            $errors = ['form' => ['party.error.expired']] + $errors;
        }
        if ($this->nameIsTaken($series, $values['name'])) {
            $errors['name'] = ['series.error.name_taken'];
        }
        if ($format instanceof SeriesFormat) {
            $this->checkNextNumber($series, $format, $values, $errors);
        }
        if ([] !== $errors || !$format instanceof SeriesFormat) {
            return $this->seriesForm($series, $values, $errors, $token, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $saved = $this->transaction->run(fn (): Series => $this->store($series, $format, $values));
        } catch (UniqueConstraintViolationException) {
            // Another request took the name or the default after the checks above.
            return $this->seriesForm($series, $values, ['name' => ['series.error.name_taken']], $token, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!$series instanceof Series) {
            $this->tokens->complete($token, $saved->id());
        }
        $this->addFlash('success', $this->translator->trans('series.flash.saved'));

        return $this->redirectToRoute('app_firm_invoicing');
    }

    /**
     * @param array<string, string> $values
     */
    private function store(?Series $series, SeriesFormat $format, array $values): Series
    {
        $target = $series;
        if ($target instanceof Series) {
            $target->rename($values['name']);
            $target->changeFormat($format);
        } else {
            $target = new Series($values['name'], $format, DocumentKind::from($values['kind']));
            $this->catalog->add($target);
        }

        $period = $this->period($format, $values['startYear']);
        if ('' !== $values['nextNumber'] && (int) $values['nextNumber'] !== $this->nextNumber($target, $period)) {
            $target->startAt((int) $values['nextNumber'], $period);
        }
        $hasDefault = array_any($this->catalog->all($target->kind()), static fn (Series $other): bool => $other->isDefault());
        if ('1' === $values['default'] || !$hasDefault) {
            $this->catalog->makeDefault($target);
        }

        return $target;
    }

    /**
     * Whether the country of the firm takes the longest number the format can give.
     */
    private function carries(SeriesFormat $format): bool
    {
        try {
            return $this->countries->for($this->firm()->address()->countryCode)->acceptsNumber($format->longestNumber());
        } catch (UnsupportedCountry) {
            return true;
        }
    }

    /**
     * The format to store, or null with the error set. A locked format stays, and
     * a browser does not send its disabled field at all.
     *
     * @param array<string, string>                                      $values
     * @param array<string, array{0: string, 1?: array<string, string>}> $errors
     */
    private function format(?Series $series, array &$values, array &$errors): ?SeriesFormat
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

        if (!$this->carries($format)) {
            $errors['format'] = ['series.error.format_too_long'];

            return null;
        }

        foreach ($this->catalog->every() as $other) {
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
     * @param array<string, string>                                      $values
     * @param array<string, array{0: string, 1?: array<string, string>}> $errors
     */
    private function checkNextNumber(?Series $series, SeriesFormat $format, array $values, array &$errors): void
    {
        if (!$series instanceof Series || '' === $values['nextNumber'] || isset($errors['nextNumber']) || isset($errors['startYear'])) {
            return;
        }

        $last = $this->counter->last($series, $this->period($format, $values['startYear']));
        if ((int) $values['nextNumber'] <= $last) {
            $errors['nextNumber'] = ['series.error.next_below_counter', ['next' => (string) ($last + 1)]];
        }
    }

    private function nameIsTaken(?Series $series, string $name): bool
    {
        return array_any($this->catalog->every(), static fn (Series $other): bool => $other !== $series && $other->name() === $name);
    }

    /**
     * @param array<string, string>                                      $values
     * @param array<string, array{0: string, 1?: array<string, string>}> $errors
     */
    private function settingsForm(array $values, array $errors, int $status): Response
    {
        $series = $this->catalog->all();

        return $this->render('@Invoicing/firm/settings.html.twig', [
            'firm' => $this->firm(),
            'values' => $values,
            'errors' => $this->messages($errors),
            'roundings' => $this->roundings(),
            'columns' => array_map(fn (string $key): array => ['key' => $key, 'label' => $this->translator->trans('series.column.'.$key)], ['name', 'kind', 'format', 'next', 'default']),
            'rows' => array_map($this->row(...), $series),
            'firstNumber' => $this->counter->peek($this->catalog->defaultFor(), $this->today()),
            'action' => $this->generateUrl('app_firm_invoicing'),
            'cancel' => $this->generateUrl('app_firm'),
        ], new Response(status: $status));
    }

    /**
     * @param array<string, string>                                      $values
     * @param array<string, array{0: string, 1?: array<string, string>}> $errors
     */
    private function seriesForm(?Series $series, array $values, array $errors, string $token, int $status): Response
    {
        return $this->render('@Invoicing/firm/series_form.html.twig', [
            'firm' => $this->firm(),
            'series' => $series,
            'seriesName' => $series instanceof Series ? $this->seriesName($series) : '',
            'locked' => $series instanceof Series && $this->catalog->hasDocuments($series),
            'kinds' => $this->kinds(),
            'nextNow' => $series instanceof Series ? (string) $this->nextNumber($series, $series->format()->period($this->today())) : '1',
            'values' => $values,
            'errors' => $this->messages($errors),
            'token' => $token,
            'action' => $series instanceof Series ? $this->generateUrl('app_series_edit', ['series' => $series->id()]) : $this->generateUrl('app_series_new'),
            'settings' => $this->generateUrl('app_firm_invoicing'),
            'firmUrl' => $this->generateUrl('app_firm'),
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
    private function row(Series $series): array
    {
        return [
            'id' => $series->id(),
            'label' => $this->seriesName($series),
            'href' => $this->generateUrl('app_series_edit', ['series' => $series->id()]),
            // A series is a small record: it opens in a modal over the list (docs/design/overlays.md).
            'opens' => Opens::DIALOG,
            'cells' => [
                'name' => $this->seriesName($series),
                'kind' => $this->translator->trans('series.kind.'.$series->kind()->value),
                'format' => $series->format()->toString(),
                'next' => $this->counter->peek($series, $this->today()),
                'default' => $series->isDefault() ? ['text' => $this->translator->trans('series.default'), 'tone' => 'green'] : null,
            ],
        ];
    }

    private function seriesName(Series $series): string
    {
        return DocumentKind::isStandardSeriesName($series->name()) ? $this->translator->trans('series.name.'.$series->name()) : $series->name();
    }

    /**
     * @return array<string, string>
     */
    private function kinds(): array
    {
        $options = [];
        foreach (DocumentKind::cases() as $kind) {
            $options[$kind->value] = $this->translator->trans('series.kind.'.$kind->value);
        }

        return $options;
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
            'startYear' => $this->today()->format('Y'),
            'nextNumber' => '',
            'default' => $series->isDefault() ? '1' : '',
            'kind' => $series->kind()->value,
        ];
    }

    /**
     * The period a start applies to; a format without a year has only one.
     */
    private function period(SeriesFormat $format, string $year): string
    {
        return $format->period(new \DateTimeImmutable($year.'-01-01'));
    }

    private function nextNumber(Series $series, string $period): int
    {
        return max($this->counter->last($series, $period) + 1, $series->startFor($period));
    }

    private function today(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }

    /**
     * The firm of this instance; before the first-run setup there is none to set up.
     */
    private function firm(): LegalEntity
    {
        return $this->instanceFirm->find() ?? throw new NotFoundHttpException();
    }

    private function seriesOf(string $id): Series
    {
        try {
            return $this->catalog->get($id);
        } catch (UnknownSeries) {
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
