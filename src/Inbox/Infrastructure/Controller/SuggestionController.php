<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Infrastructure\Controller;

use Foresi\Inbox\Application\DecideSuggestions;
use Foresi\Inbox\Application\Decision;
use Foresi\Inbox\Application\DeclaredAction;
use Foresi\Inbox\Application\InboxActions;
use Foresi\Inbox\Application\ItemDetail;
use Foresi\Inbox\Application\ItemReads;
use Foresi\Inbox\Application\StepReferences;
use Foresi\Inbox\Contract\PreFill;
use Foresi\Inbox\Domain\StepState;
use Foresi\Inbox\Domain\Suggestion;
use Foresi\Inbox\Domain\SuggestionState;
use Foresi\Inbox\Domain\SuggestionStep;
use Foresi\Shared\Contract\RefusalKind;
use Foresi\Shared\Contract\Refused;
use Foresi\Shared\Infrastructure\Operation\OperationField;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The person decides the suggestions an agent proposed on their item, on its
 * page (SuggestionCards): Accept runs a card's ticked steps, Accept selected the
 * ticked cards, each on its own; Reject asks for an optional reason in a dialog;
 * Edit opens a step's action in its own dialog (ActionForm), filled with the
 * step's input, and "Save and accept" stores the edit and accepts the
 * suggestion. A failed step is retried by accepting again. Marking the item done
 * while suggestions are open asks whether to reject or keep them.
 *
 * Every decision goes through DecideSuggestions as the signed-in person, so its
 * steps run as they do in the item's own action dialog, and answers with a
 * flash on the item. A suggestion of another item, or of another person's,
 * answers 404.
 */
final class SuggestionController extends AbstractController
{
    public const string ACCEPT_ROUTE = 'app_inbox_suggestions_accept';
    public const string REJECT_ROUTE = 'app_inbox_suggestion_reject';
    public const string EDIT_ROUTE = 'app_inbox_suggestion_step_edit';
    public const string DONE_ROUTE = 'app_inbox_item_done_ask';
    /** The form of the open cards on the item's page; the header's Accept selected submits it too. */
    public const string FORM = 'inbox-suggestions';
    /** The ticks of the steps to run in a step's edit dialog. */
    public const string RUN_STEPS = 'suggestionSteps';

    public function __construct(
        private readonly ItemReads $reads,
        private readonly DecideSuggestions $decide,
        private readonly InboxActions $actions,
        private readonly ActionForm $form,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * One card's Accept (the button's value names it) or Accept selected (the ticked cards), with each
     * card's ticked steps: an open step left unticked is skipped.
     */
    #[Route('/inbox/{id}/suggestions/accept', name: self::ACCEPT_ROUTE, requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function accept(string $id, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $open = array_filter($this->detail($id)->suggestions, static fn (Suggestion $suggestion): bool => $suggestion->isOpen());
        $byId = array_column(array_map(static fn (Suggestion $suggestion): array => [$suggestion->id(), $suggestion], $open), 1, 0);
        $one = $request->request->getString('accept');
        $chosen = '' !== $one ? [$one] : array_values(array_filter($request->request->all('selected'), \is_string(...)));
        $ids = array_values(array_unique(array_filter($chosen, static fn (string $chosen): bool => isset($byId[$chosen]))));
        if ([] === $ids) {
            $this->addFlash('warning', $this->translator->trans('' !== $one ? 'inbox.suggestion.flash.not_open' : 'inbox.suggestion.flash.none_selected'));

            return $this->back($id);
        }
        $ticked = $request->request->all('steps');
        $unselected = [];
        foreach ($ids as $suggestionId) {
            $positions = \is_array($ticked[$suggestionId] ?? null) ? array_map(intval(...), array_filter($ticked[$suggestionId], is_numeric(...))) : [];
            $left = array_values(array_map(static fn (SuggestionStep $step): int => $step->position(), array_filter($byId[$suggestionId]->steps(), static fn (SuggestionStep $step): bool => $step->isRunnable() && !\in_array($step->position(), $positions, true))));
            if ([] !== $left) {
                $unselected[$suggestionId] = $left;
            }
        }
        try {
            $decisions = $this->decide->accept($ids, null, [] === $unselected ? null : $unselected);
        } catch (Refused $refused) {
            $this->addFlash('error', $this->callRefusal($refused));

            return $this->back($id);
        }
        $this->report($decisions);

        return $this->back($id);
    }

    #[Route('/inbox/{id}/suggestions/{suggestionId}/reject', name: self::REJECT_ROUTE, requirements: ['id' => Requirement::UUID, 'suggestionId' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function reject(string $id, string $suggestionId, Request $request): Response
    {
        $suggestion = $this->suggestion($this->detail($id), $suggestionId);
        $reason = '';
        $error = null;
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $reason = mb_substr($request->request->getString('reason'), 0, 1_000);
            try {
                $this->decide->reject($suggestion->id(), '' === trim($reason) ? null : $reason);
                $this->addFlash('success', $this->translator->trans('inbox.suggestion.flash.rejected'));

                return $this->back($id);
            } catch (Refused $refused) {
                if ('not_open' === $refused->reason()) {
                    $this->addFlash('warning', $this->translator->trans('inbox.suggestion.flash.not_open'));

                    return $this->back($id);
                }
                if (RefusalKind::Invalid !== $refused->kind()) {
                    throw $this->httpError($refused);
                }
                $error = $this->translator->trans('inbox.suggestion.reject.too_long');
            }
        }

        return $this->render('@Inbox/suggestion_reject.html.twig', [
            'id' => $id,
            'suggestionId' => $suggestion->id(),
            'reason' => $reason,
            'error' => $error,
            'token' => InboxController::TOKEN,
        ], new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * A step's action in its own dialog, filled with the step's input; "Save and accept" stores the
     * edit and accepts the suggestion with the steps ticked in the dialog: every step of the
     * suggestion is listed with its tick, ticked unless it was unticked before, so a step the person
     * leaves unticked is skipped, not run. A field that names the record of an earlier step is shown,
     * not edited, and kept; so are the fields the dialog has no control for (lists).
     */
    #[Route('/inbox/{id}/suggestions/{suggestionId}/steps/{position}/edit', name: self::EDIT_ROUTE, requirements: ['id' => Requirement::UUID, 'suggestionId' => Requirement::UUID, 'position' => '\d{1,2}'], methods: ['GET', 'POST'])]
    public function edit(string $id, string $suggestionId, int $position, Request $request): Response
    {
        $detail = $this->detail($id);
        $suggestion = $this->suggestion($detail, $suggestionId);
        if (!$suggestion->isOpen() || $detail->item->isTrashed()) {
            $this->addFlash('warning', $this->translator->trans('inbox.suggestion.flash.not_open'));

            return $this->back($id);
        }
        $step = array_find($suggestion->steps(), static fn (SuggestionStep $step): bool => $step->position() === $position);
        $action = $step instanceof SuggestionStep ? $this->actions->get($step->operation()) : null;
        if (!$step instanceof SuggestionStep || !$step->isRunnable() || !$action instanceof DeclaredAction) {
            throw new NotFoundHttpException('The suggestion has no such step to edit.');
        }
        $input = $step->input();
        $references = $this->references($action, $input);
        $prefill = $this->actions->prefill($action, $detail->view(true));
        $values = $this->form->initial($action, new PreFill($this->byName($action, $input), $prefill->choices));
        $errors = [];
        $summary = null;
        $runnable = array_values(array_map(static fn (SuggestionStep $one): int => $one->position(), array_filter($suggestion->steps(), static fn (SuggestionStep $one): bool => $one->isRunnable())));
        $ticked = $runnable;
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $posted = $request->request->all(self::RUN_STEPS);
            $ticked = array_values(array_intersect($runnable, array_map(intval(...), array_filter($posted, is_numeric(...)))));
            $values = $this->form->posted($action, $request->request);
            [$sent, $errors] = $this->form->sent($action, $id, $values);
            $errors = array_diff_key($errors, $references);
            if ([] === $errors) {
                $edited = $this->edited($action, $input, $sent, $references);
                try {
                    // The step being edited runs; any other runnable step left unticked is skipped.
                    $unselected = array_values(array_diff($runnable, $ticked, [$position]));
                    [$decision] = $this->decide->accept([$suggestion->id()], [$suggestion->id() => [$position => $edited]], [] === $unselected ? null : [$suggestion->id() => $unselected]);
                    $this->report([$decision]);

                    return $this->back($id);
                } catch (Refused $refused) {
                    if (RefusalKind::Invalid !== $refused->kind()) {
                        throw $this->httpError($refused);
                    }
                    $text = $this->stepRefusal($refused->reason());
                    $field = $this->fieldOf($refused->field(), $suggestion->id(), $position);
                    if (null !== $field && \array_key_exists($field, $values) && !\array_key_exists($field, $references)) {
                        $errors[$field] = $text;
                    } else {
                        $summary = $text;
                    }
                }
            }
        }
        $fields = [];
        foreach ($this->form->fields($action, $prefill, $values, $errors) as $field) {
            $name = \is_string($field['name'] ?? null) ? $field['name'] : '';
            if (ActionForm::MARK_DONE === $name) {
                // The item is marked done after the last decision, not by a step.
                continue;
            }
            if (\array_key_exists($name, $references)) {
                $field = ['widget' => 'reference', 'value' => $references[$name], 'required' => false] + $field;
            }
            $fields[] = $field;
        }

        return $this->render('@Inbox/action.html.twig', [
            'id' => $id,
            'operation' => $action->name(),
            'title' => $this->translator->trans($action->action->label),
            'fields' => $fields,
            'summary' => $summary,
            'token' => InboxController::TOKEN,
            'formAction' => $this->generateUrl(self::EDIT_ROUTE, ['id' => $id, 'suggestionId' => $suggestion->id(), 'position' => $position]),
            'submitLabel' => $this->translator->trans('inbox.suggestion.save_and_accept'),
            'intro' => $this->translator->trans('inbox.suggestion.edit_intro'),
            'runSteps' => $this->runSteps($suggestion, $position, $ticked),
        ], new Response(status: [] === $errors && null === $summary ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * Done on an item with open suggestions: whether to reject them or keep them on the done item.
     */
    #[Route('/inbox/{id}/done', name: self::DONE_ROUTE, requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function done(string $id): Response
    {
        $detail = $this->detail($id);

        return $this->render('@Inbox/done_ask.html.twig', [
            'id' => $id,
            'open' => \count(array_filter($detail->suggestions, static fn (Suggestion $suggestion): bool => $suggestion->isOpen())),
            'token' => InboxController::TOKEN,
        ]);
    }

    /**
     * Every step of the suggestion with its tick, for the edit dialog: the edited step always runs, a
     * done or skipped step shows its state and cannot be ticked, the others are ticked as given.
     *
     * @param list<int> $ticked
     *
     * @return list<array{name: string, id: string, value: string, label: string, checked: bool, disabled: bool, state: ?string}>
     */
    private function runSteps(Suggestion $suggestion, int $position, array $ticked): array
    {
        $steps = [];
        foreach ($suggestion->steps() as $step) {
            $action = $this->actions->get($step->operation());
            $label = \sprintf('%d. %s', $step->position(), $action instanceof DeclaredAction ? $this->translator->trans($action->action->label) : $step->operation());
            $editing = $step->position() === $position;
            $steps[] = [
                'name' => self::RUN_STEPS.'[]',
                'id' => 'inbox-suggestion-run-'.$step->position(),
                'value' => (string) $step->position(),
                'label' => $label,
                'checked' => $editing || StepState::Done === $step->state() || ($step->isRunnable() && \in_array($step->position(), $ticked, true)),
                'disabled' => $editing || !$step->isRunnable(),
                'state' => $editing ? $this->translator->trans('inbox.suggestion.editing') : ($step->isRunnable() ? null : $this->translator->trans('inbox.suggestion.step_state.'.$step->state()->value)),
            ];
        }

        return $steps;
    }

    /**
     * What came of the decisions, as flashes: how many were accepted, which stopped at a step and
     * which were decided already.
     *
     * @param list<Decision> $decisions
     */
    private function report(array $decisions): void
    {
        $accepted = 0;
        $failed = 0;
        $closed = 0;
        $other = [];
        foreach ($decisions as $decision) {
            $state = $decision->suggestion?->state();
            if ('not_open' === $decision->refused) {
                ++$closed;
            } elseif (null !== $decision->refused) {
                $other[$decision->refused] = $this->stepRefusal($decision->refused);
            } elseif (SuggestionState::Accepted === $state) {
                ++$accepted;
            } elseif (array_any($decision->suggestion?->steps() ?? [], static fn (SuggestionStep $step): bool => StepState::Failed === $step->state())) {
                ++$failed;
            }
        }
        if ($accepted > 0) {
            $this->addFlash('success', $this->translator->trans('inbox.suggestion.flash.accepted', ['count' => $accepted]));
        }
        if ($failed > 0) {
            $this->addFlash('error', $this->translator->trans('inbox.suggestion.flash.failed', ['count' => $failed]));
        }
        if ($closed > 0) {
            $this->addFlash('warning', $this->translator->trans('inbox.suggestion.flash.not_open'));
        }
        foreach ($other as $text) {
            $this->addFlash('warning', $text);
        }
    }

    /**
     * The words of a refusal of the whole call: every step unticked, or an input that is not right.
     */
    private function callRefusal(Refused $refused): string
    {
        if ('invalid_input' === $refused->reason() && str_starts_with((string) $refused->field(), 'unselected')) {
            return $this->translator->trans('inbox.suggestion.flash.none_ticked');
        }

        return $this->stepRefusal($refused->reason());
    }

    private function stepRefusal(string $reason): string
    {
        $key = 'inbox.suggestion.refused.'.$reason;
        $text = $this->translator->trans($key);

        return $text === $key ? $reason : $text;
    }

    /**
     * The field of the dialog a refusal of an edit names: edits.<id>.<position>.<field>….
     */
    private function fieldOf(?string $path, string $suggestionId, int $position): ?string
    {
        $prefix = 'edits.'.$suggestionId.'.'.$position.'.';
        if (null === $path || !str_starts_with($path, $prefix)) {
            return null;
        }

        return explode('.', substr($path, \strlen($prefix)))[0];
    }

    /**
     * The fields of the input that name the record of an earlier step, by PHP name, with the words
     * the dialog shows for them.
     *
     * @param array<mixed> $input
     *
     * @return array<string, string>
     */
    private function references(DeclaredAction $action, array $input): array
    {
        $references = [];
        foreach ($input as $wire => $value) {
            $field = $this->form->field($action, (string) $wire);
            if ($field instanceof OperationField && StepReferences::isReference($value) && \is_array($value)) {
                $references[$field->name] = $this->translator->trans('inbox.suggestion.reference', ['step' => \is_int($value[StepReferences::STEP] ?? null) ? $value[StepReferences::STEP] : 0]);
            }
        }

        return $references;
    }

    /**
     * The step's input by the fields' PHP names, as the dialog starts with it.
     *
     * @param array<mixed> $input
     *
     * @return array<string, mixed>
     */
    private function byName(DeclaredAction $action, array $input): array
    {
        $values = [];
        foreach ($input as $wire => $value) {
            $field = $this->form->field($action, (string) $wire);
            if ($field instanceof OperationField && !StepReferences::isReference($value)) {
                $values[$field->name] = $value;
            }
        }

        return $values;
    }

    /**
     * The edited input: the step's input with what the dialog sent over it, without the item and
     * Mark done (the run adds them), and with each reference kept as it was.
     *
     * @param array<mixed>          $input
     * @param array<string, mixed>  $sent
     * @param array<string, string> $references
     *
     * @return array<string, mixed>
     */
    private function edited(DeclaredAction $action, array $input, array $sent, array $references): array
    {
        $edited = [];
        foreach ($input as $wire => $value) {
            $edited[(string) $wire] = $value;
        }
        foreach ($sent as $wire => $value) {
            $field = $this->form->field($action, $wire);
            if (!$field instanceof OperationField || $field->inPath || ActionForm::MARK_DONE === $field->name || \array_key_exists($field->name, $references)) {
                continue;
            }
            $edited[$wire] = $value;
        }

        return $edited;
    }

    private function detail(string $id): ItemDetail
    {
        try {
            return $this->reads->get($id);
        } catch (Refused $refused) {
            throw $this->httpError($refused);
        }
    }

    private function suggestion(ItemDetail $detail, string $suggestionId): Suggestion
    {
        return array_find($detail->suggestions, static fn (Suggestion $suggestion): bool => $suggestion->id() === $suggestionId)
            ?? throw new NotFoundHttpException('The item has no such suggestion.');
    }

    private function back(string $id): RedirectResponse
    {
        return $this->redirectToRoute(InboxController::ITEM_ROUTE, ['id' => $id]);
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(InboxController::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }

    private function httpError(Refused $refused): HttpException
    {
        return match ($refused->kind()) {
            RefusalKind::NotFound => new NotFoundHttpException($refused->getMessage(), $refused),
            RefusalKind::Conflict => new ConflictHttpException($refused->getMessage(), $refused),
            default => new AccessDeniedHttpException($refused->getMessage(), $refused),
        };
    }
}
