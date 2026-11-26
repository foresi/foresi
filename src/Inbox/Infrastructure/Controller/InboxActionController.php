<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Infrastructure\Controller;

use Foresi\Inbox\Application\DeclaredAction;
use Foresi\Inbox\Application\InboxActions;
use Foresi\Inbox\Application\InboxRefused;
use Foresi\Inbox\Application\ItemDetail;
use Foresi\Inbox\Application\ItemReads;
use Foresi\Inbox\Application\RecordOutputs;
use Foresi\Inbox\Contract\InboxActionInput;
use Foresi\Shared\Contract\RefusalKind;
use Foresi\Shared\Contract\Refused;
use Foresi\Shared\Infrastructure\Operation\InputMapper;
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
 * An action a module declares on an inbox item (InboxActions), in a dialog over
 * the item: the fields of its operation (ActionForm) filled from the mail by the
 * module's pre-fill, and "Mark the item done", ticked. Saving runs the same
 * handler the API runs, checked as the API checks it (the item is the person's
 * own, not in the trash, and the action's condition applies) but without a
 * token's scope, and adds what it made to the item's outputs. An action that is
 * no action of the item answers 404, as an item of somebody else does.
 *
 * The pre-filled text is the sender's: it is printed escaped, as the value of
 * its control, never as markup.
 */
final class InboxActionController extends AbstractController
{
    public const string ROUTE = 'app_inbox_item_action';

    public function __construct(
        private readonly ItemReads $reads,
        private readonly InboxActions $actions,
        private readonly RecordOutputs $outputs,
        private readonly ActionForm $form,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/inbox/{id}/actions/{operation}', name: self::ROUTE, requirements: ['id' => Requirement::UUID, 'operation' => '[a-z0-9_.-]{1,100}'], methods: ['GET', 'POST'])]
    public function action(string $id, string $operation, Request $request): Response
    {
        $detail = $this->refused(fn (): ItemDetail => $this->reads->get($id));
        $view = $detail->view(true);
        $action = $this->actions->get($operation);
        if (!$action instanceof DeclaredAction || $detail->item->isTrashed() || !$this->actions->applies($action->action, $view)) {
            throw new NotFoundHttpException('The item has no such action.');
        }
        $prefill = $this->actions->prefill($action, $view);
        $values = $this->form->initial($action, $prefill);
        $errors = [];
        $summary = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(InboxController::TOKEN, $request->request->getString('_token'))) {
                throw new AccessDeniedHttpException('Invalid CSRF token.');
            }
            $values = $this->form->posted($action, $request->request);
            [$sent, $errors] = $this->form->sent($action, $id, $values);
            if ([] === $errors) {
                try {
                    return $this->run($action, $id, $sent);
                } catch (Refused $refused) {
                    $text = $this->refusalText($refused);
                    $field = $refused->field();
                    if (null !== $field && \array_key_exists($field, $values)) {
                        $errors[$field] = $text;
                    } else {
                        $summary = $text;
                    }
                }
            }
        }

        return $this->render('@Inbox/action.html.twig', [
            'id' => $id,
            'operation' => $action->name(),
            'title' => $this->translator->trans($action->action->label),
            'fields' => $this->form->fields($action, $prefill, $values, $errors),
            'summary' => $summary,
            'token' => InboxController::TOKEN,
        ], new Response(status: [] === $errors && null === $summary ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * The action's handler, between the check of the item and the recording of what it made.
     *
     * @param array<string, mixed> $sent
     *
     * @throws Refused when the input or the module's use case refuses it
     */
    private function run(DeclaredAction $action, string $id, array $sent): RedirectResponse
    {
        $input = InputMapper::map($action->definition, $sent);
        if (!$input instanceof InboxActionInput) {
            throw new \LogicException(\sprintf('The input of %s is no inbox action input.', $action->name()));
        }
        // The item may have gone to the trash or changed since the dialog opened.
        try {
            $this->outputs->check($action->action, $input, false);
        } catch (InboxRefused $refused) {
            if ('email_activity_unavailable' === $refused->reason()) {
                // Shown on the dialog: the person can restore the type and save again.
                throw $refused;
            }
            throw $this->httpError($refused);
        }
        $output = $action->definition->handler->handle($input);
        if ($this->outputs->record($action->action, $input, $output)) {
            $this->addFlash('success', $this->translator->trans('inbox.flash.action_done'));
        } else {
            $this->addFlash('warning', $this->translator->trans($this->outputs->linksByHand($action->action) ? 'inbox.flash.action_not_linked' : 'inbox.flash.action_not_added'));
        }

        return $this->redirectToRoute(InboxController::ITEM_ROUTE, ['id' => $id]);
    }

    /**
     * The words of a refusal on the form: Inbox's translation of its reason, else the module's
     * message, which never quotes the mail.
     */
    private function refusalText(Refused $refused): string
    {
        $key = 'inbox.action_dialog.refused.'.$refused->reason();
        $text = $this->translator->trans($key);

        return $text === $key ? $refused->getMessage() : $text;
    }

    /**
     * @template T
     *
     * @param callable(): T $action
     *
     * @return T
     */
    private function refused(callable $action): mixed
    {
        try {
            return $action();
        } catch (Refused $refused) {
            throw $this->httpError($refused);
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
