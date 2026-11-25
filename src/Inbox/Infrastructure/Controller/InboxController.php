<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Infrastructure\Controller;

use Foresi\Activities\Contract\LinkableRecords;
use Foresi\Inbox\Application\Api\CreateContactFromItemInput;
use Foresi\Inbox\Application\Api\DiscardItemInput;
use Foresi\Inbox\Application\Api\LinkItemInput;
use Foresi\Inbox\Application\Api\MarkItemDoneInput;
use Foresi\Inbox\Application\Api\MatchItemAgainInput;
use Foresi\Inbox\Application\Api\MatchItemInput;
use Foresi\Inbox\Application\Api\ReopenItemInput;
use Foresi\Inbox\Application\ForwardParser;
use Foresi\Inbox\Application\InboxRefused;
use Foresi\Inbox\Application\ItemDetail;
use Foresi\Inbox\Application\ItemReads;
use Foresi\Inbox\Application\ItemRow;
use Foresi\Inbox\Application\ItemWrites;
use Foresi\Inbox\Application\OutputView;
use Foresi\Inbox\Application\UntrustedText;
use Foresi\Inbox\Application\View\InboxLists;
use Foresi\Inbox\Domain\InboxItem;
use Foresi\Inbox\Domain\ItemState;
use Foresi\Inbox\Infrastructure\Column\InboxCells;
use Foresi\Inbox\Infrastructure\Column\InboxColumns;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Mail\Contract\MailAttachmentContent;
use Foresi\Mail\Contract\MailMessageView;
use Foresi\Mail\Contract\Messages;
use Foresi\Mail\Contract\SenderCheck;
use Foresi\Mail\Contract\UnknownMailMessage;
use Foresi\Party\Application\PartyQuery;
use Foresi\Party\Application\PartyRow;
use Foresi\Party\Contract\PartyDirectory;
use Foresi\Party\Contract\PartySummary;
use Foresi\Party\Infrastructure\Form\CountryOptions;
use Foresi\Shared\Application\ActorRole;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\FilterRows;
use Foresi\Shared\Application\View\ListView;
use Foresi\Shared\Application\View\ListViews;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Application\View\WorkingFilter;
use Foresi\Shared\Contract\RefusalKind;
use Foresi\Shared\Contract\Refused;
use Foresi\Shared\Infrastructure\View\FilterSheet;
use Foresi\Ui\Column\RememberedDisplay;
use Foresi\Ui\Contract\Opens;
use Foresi\Ui\Contract\WorkingDisplay;
use Foresi\Ui\Contract\WorkingState;
use Foresi\Ui\View\ViewBar;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The person's own inbox: the mail they forwarded to their inbox address as items,
 * on saved views as every list is (New, Done, Discarded, Unmatched), one row per
 * conversation with the latest mail shown and a count of the others (Front groups
 * a conversation under one row, Pipedrive's Smart Email BCC shows it under the
 * deal). The item opens as a page: the mail as plain text, its attachments as
 * downloads, who sent it and what came of it; Done, Discard and Reopen in the
 * header, Link, Create contact and Match contact in dialogs. Only the owner
 * reaches an item: any other person gets 404, for the page and the attachment.
 *
 * Mail text is untrusted. It is printed escaped, as text, with each address
 * followed by its domain in brackets and never a link, and no remote content.
 */
final class InboxController extends AbstractController
{
    public const string ROUTE = 'app_inbox';
    public const string ITEM_ROUTE = 'app_inbox_item';
    public const string ATTACHMENT_ROUTE = 'app_inbox_attachment';
    public const string CONTACTS_ROUTE = 'app_inbox_contacts';
    public const string TOKEN = 'inbox_items';
    private const int PAGE_SIZE = 50;
    private const int MAX_QUERY = 100;
    /** The one order of the inbox, newest first. */
    private const string ORDER = '-received';
    /** The parts of the list's address an action carries back to it. */
    private const array LIST_QUERY = ['view', WorkingFilter::PARAM, 'after', WorkingDisplay::COLUMNS, WorkingDisplay::DENSITY];

    public function __construct(
        private readonly InboxLists $inboxLists,
        private readonly ItemReads $reads,
        private readonly ItemWrites $writes,
        private readonly Messages $messages,
        private readonly PartyDirectory $contacts,
        private readonly PartyQuery $parties,
        private readonly LinkableRecords $records,
        private readonly ForwardParser $forwards,
        private readonly InstanceClock $clock,
        private readonly TranslatorInterface $translator,
        private readonly ListViews $listViews,
        private readonly ActorRole $actor,
        private readonly RememberedDisplay $rememberedDisplay,
        private readonly ViewBar $viewBar,
        private readonly FilterSheet $filterSheet,
    ) {
    }

    #[Route('/inbox', name: self::ROUTE, methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyUnlessStaff();
        // The builder's rows (spec D11) become the list's working filter, in its address.
        if ($request->query->has(FilterRows::MARKER)) {
            return $this->redirectToRoute(self::ROUTE, $this->builtQuery($request->query), Response::HTTP_SEE_OTHER);
        }
        [$views, $view, $shown] = $this->views($request->query);
        $list = $this->inboxLists->list();
        $filterParam = WorkingFilter::param($shown, $view->filter);
        $after = $request->query->getString('after');
        $slice = $this->refused(fn (): \Foresi\Inbox\Application\ItemSlice => $this->reads->list($this->stateOf($shown), $this->unmatchedIn($shown), '' === $after ? null : $after, self::PAGE_SIZE));
        $query = array_filter(['view' => $view->isAll() ? null : $view->key, WorkingFilter::PARAM => $filterParam], static fn (?string $value): bool => null !== $value);
        $working = $this->rememberedDisplay->read(InboxColumns::LIST, [], $list->key, $view, $request->query->all(), filters: $list);
        $query += $working->query;
        $back = substr($this->generateUrl(self::ROUTE, '' === $after ? $query : $query + ['after' => $after]), \strlen($this->generateUrl(self::ROUTE)));
        $bar = $this->viewBar->of($list, $views, $view, $shown, self::ORDER, self::ORDER, $working, self::ROUTE, []);
        $rows = $this->rows($slice->rows, $working, $back);
        $next = $slice->nextAfter();

        return $this->render('@Inbox/index.html.twig', [
            'view' => $view->isAll() ? '' : $view->key,
            'working' => $filterParam ?? '',
            'workingQuery' => $working->query,
            'filtered' => null !== $filterParam,
            'emptyState' => $this->emptyState($view),
            ...$this->filterSheet->of($list, $shown, fn (ViewFilter $next): string => $this->generateUrl(self::ROUTE, [WorkingFilter::PARAM => WorkingFilter::param($next, $view->filter)] + array_diff_key($query, [WorkingFilter::PARAM => true])), pinned: $working->pinned),
            ...$bar,
            ...$this->tabs($views, $view->key, $query, $bar['viewBar']['modified']),
            'columns' => $working->display->table(),
            'rows' => $rows,
            'density' => $working->tableDensity(),
            'picker' => $this->picker($working, $query),
            'olderUrl' => null === $next ? null : $this->generateUrl(self::ROUTE, $query + ['after' => $next]),
        ]);
    }

    #[Route('/inbox/contacts', name: self::CONTACTS_ROUTE, methods: ['GET'])]
    public function contacts(Request $request): JsonResponse
    {
        $this->denyUnlessStaff();
        $page = $this->parties->search(mb_substr($request->query->getString('q'), 0, self::MAX_QUERY), null, false, 'name', 1);

        return $this->json(['results' => array_map(fn (PartyRow $row): array => [
            'id' => $row->id,
            'label' => $row->displayName,
            'hint' => $row->email ?? $row->registrationNumber ?? $this->translator->trans('party.kind.'.$row->kind->value),
        ], $page->rows)]);
    }

    #[Route('/inbox/{id}', name: self::ITEM_ROUTE, requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function show(string $id): Response
    {
        $detail = $this->refused(fn (): ItemDetail => $this->reads->get($id));
        $item = $detail->item;
        $message = $detail->message;
        $contact = null === $item->contactId() ? null : $this->contacts->find($item->contactId());
        $subject = $message instanceof MailMessageView && '' !== trim($message->subject) ? $message->subject : $this->translator->trans('inbox.item.no_subject');

        return $this->render('@Inbox/show.html.twig', [
            'id' => $item->id(),
            'state' => $item->state()->value,
            'title' => $subject,
            'mailRemoved' => !$message instanceof MailMessageView,
            'from' => $message?->from,
            'originalFrom' => $item->originalFrom(),
            'verdict' => $message->verdict ?? SenderCheck::UNKNOWN,
            'received' => $this->time($item->receivedAt()),
            'stateChanged' => $this->time($item->stateChangedAt()),
            'matchBasis' => $item->matchBasis()?->value,
            'contact' => $contact instanceof PartySummary ? ['name' => $contact->displayName, 'href' => $this->generateUrl('app_contact_show', ['id' => $contact->id]), 'archived' => $contact->archived] : null,
            'matched' => null !== $item->contactId(),
            'warnings' => $detail->warnings(),
            'duplicateOf' => $item->duplicateOfPurpose(),
            'text' => null === $detail->text ? null : UntrustedText::shown($detail->text),
            'attachments' => array_map(fn (\Foresi\Mail\Contract\MailAttachment $attachment): array => [
                'name' => $attachment->name,
                'size' => $this->size($attachment->size),
                'href' => $this->generateUrl(self::ATTACHMENT_ROUTE, ['id' => $item->id(), 'index' => $attachment->index]),
            ], $detail->attachments),
            'thread' => $this->thread($detail->thread),
            'outputs' => array_map(static fn (OutputView $output): array => ['kind' => $output->output->kind(), 'available' => $output->available, 'label' => $output->label, 'url' => $output->url], $detail->outputs),
            'token' => self::TOKEN,
        ]);
    }

    /**
     * An attachment, as a download and nothing else: the file is the sender's, so it is never
     * served under its own type, never shown in the page and never sniffed.
     */
    #[Route('/inbox/{id}/attachments/{index}', name: self::ATTACHMENT_ROUTE, requirements: ['id' => Requirement::UUID, 'index' => '\d{1,4}'], methods: ['GET'])]
    public function attachment(string $id, int $index): Response
    {
        $detail = $this->refused(fn (): ItemDetail => $this->reads->get($id));
        $known = false;
        foreach ($detail->attachments as $attachment) {
            $known = $known || $attachment->index === $index;
        }
        if (!$known || !$detail->message instanceof MailMessageView) {
            throw new NotFoundHttpException('The mail has no attachment with this number.');
        }
        try {
            $content = $this->messages->attachment($detail->message->id, $index);
        } catch (UnknownMailMessage $gone) {
            // Removed or unreadable since the page was read.
            throw new NotFoundHttpException('The mail has no attachment with this number.', $gone);
        }

        return $this->download($content, $index);
    }

    #[Route('/inbox/{id}/done', name: 'app_inbox_item_done', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function done(string $id, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $this->refused(fn (): InboxItem => $this->writes->done(new MarkItemDoneInput($id)));
        $this->addFlash('success', $this->translator->trans('inbox.flash.done'));

        return $this->afterAction($request, $id, toList: true);
    }

    #[Route('/inbox/{id}/discard', name: 'app_inbox_item_discard', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function discard(string $id, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $this->refused(fn (): InboxItem => $this->writes->discard(new DiscardItemInput($id)));
        $this->addFlash('success', $this->translator->trans('inbox.flash.discarded', ['days' => ItemWrites::DISCARD_DAYS]));

        return $this->afterAction($request, $id, toList: true);
    }

    #[Route('/inbox/{id}/reopen', name: 'app_inbox_item_reopen', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function reopen(string $id, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $this->refused(fn (): InboxItem => $this->writes->reopen(new ReopenItemInput($id)));
        $this->addFlash('success', $this->translator->trans('inbox.flash.reopened'));

        return $this->afterAction($request, $id, toList: false);
    }

    #[Route('/inbox/{id}/match-again', name: 'app_inbox_item_match_again', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function matchAgain(string $id, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $item = $this->refused(fn (): InboxItem => $this->writes->matchAgain(new MatchItemAgainInput($id)));
        $this->addFlash('success', $this->translator->trans(null === $item->contactId() ? 'inbox.flash.match_again_none' : 'inbox.flash.matched'));

        return $this->redirectToRoute(self::ITEM_ROUTE, ['id' => $id]);
    }

    #[Route('/inbox/{id}/unmatch', name: 'app_inbox_item_unmatch', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function unmatch(string $id, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $this->refused(fn (): InboxItem => $this->writes->match(new MatchItemInput($id, null)));
        $this->addFlash('success', $this->translator->trans('inbox.flash.unmatched'));

        return $this->redirectToRoute(self::ITEM_ROUTE, ['id' => $id]);
    }

    /**
     * The contact picker in a dialog (the same URL is a page of its own).
     */
    #[Route('/inbox/{id}/match', name: 'app_inbox_item_match', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function match(string $id, Request $request): Response
    {
        $this->refused(fn (): ItemDetail => $this->reads->get($id));
        $values = ['contactId' => '', 'contactQuery' => ''];
        $error = null;
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $values = ['contactId' => $request->request->getString('contactId'), 'contactQuery' => mb_substr($request->request->getString('contactQuery'), 0, self::MAX_QUERY)];
            if ('' === $values['contactId']) {
                $error = $this->translator->trans('inbox.match.refused.contact_required');
            } else {
                try {
                    $this->writes->match(new MatchItemInput($id, $values['contactId']));
                    $this->addFlash('success', $this->translator->trans('inbox.flash.matched'));

                    return $this->redirectToRoute(self::ITEM_ROUTE, ['id' => $id]);
                } catch (InboxRefused $refused) {
                    $error = $this->formError($refused, 'inbox.match.refused.');
                }
            }
        }

        return $this->render('@Inbox/match.html.twig', ['id' => $id, 'values' => $values, 'error' => $error, 'token' => self::TOKEN], $this->status($error));
    }

    /**
     * The new contact's form in a dialog, its name and e-mail taken from the sender.
     */
    #[Route('/inbox/{id}/contact/new', name: 'app_inbox_item_contact_new', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function createContact(string $id, Request $request): Response
    {
        $detail = $this->refused(fn (): ItemDetail => $this->reads->get($id));
        $values = $this->senderValues($detail);
        $errors = [];
        $summary = null;
        $existing = [];
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            foreach (array_keys($values) as $field) {
                $values[$field] = trim(mb_substr($request->request->getString($field), 0, 320));
            }
            try {
                $this->writes->createContact(new CreateContactFromItemInput($id, $values['kind'], $values['displayName'], $values['street'], $values['city'], $values['postalCode'], strtoupper($values['countryCode']), $values['registrationNumber'], $values['vatId'], '' === $values['email'] ? null : $values['email'], $values['phone']));
                $this->addFlash('success', $this->translator->trans('inbox.flash.contact_created'));

                return $this->redirectToRoute(self::ITEM_ROUTE, ['id' => $id]);
            } catch (Refused $refused) {
                if (RefusalKind::Invalid !== $refused->kind() && RefusalKind::Conflict !== $refused->kind()) {
                    throw $this->httpError($refused);
                }
                $text = $this->refusalText($refused, 'inbox.create_contact.refused.');
                $field = $this->formField($refused->field());
                if (null !== $field && \array_key_exists($field, $values)) {
                    $errors[$field] = $text;
                } else {
                    $summary = $text;
                }
                $existing = $this->existing($refused);
            }
        }

        return $this->render('@Inbox/create_contact.html.twig', [
            'id' => $id,
            'values' => $values,
            'errors' => $errors,
            'summary' => $summary,
            'existing' => $existing,
            'kinds' => array_combine(['person', 'sole_trader', 'company'], array_map(fn (string $kind): string => $this->translator->trans('party.kind.'.$kind), ['person', 'sole_trader', 'company'])),
            'countries' => CountryOptions::options($request->getLocale()),
            'token' => self::TOKEN,
        ], $this->status([] === $errors && null === $summary ? null : 'invalid'));
    }

    /**
     * The record to link in a dialog: its kind, offered by the modules, and its id.
     */
    #[Route('/inbox/{id}/link', name: 'app_inbox_item_link', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function link(string $id, Request $request): Response
    {
        $this->refused(fn (): ItemDetail => $this->reads->get($id));
        $kinds = $this->records->kinds();
        $values = ['kind' => $kinds[0] ?? '', 'recordId' => ''];
        $error = null;
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $values = ['kind' => $request->request->getString('kind'), 'recordId' => trim(mb_substr($request->request->getString('recordId'), 0, 64))];
            try {
                $this->writes->link(new LinkItemInput($id, $values['kind'], $values['recordId']));
                $this->addFlash('success', $this->translator->trans('inbox.flash.linked'));

                return $this->redirectToRoute(self::ITEM_ROUTE, ['id' => $id]);
            } catch (Refused $refused) {
                if (RefusalKind::Invalid !== $refused->kind() && RefusalKind::Conflict !== $refused->kind()) {
                    throw $this->httpError($refused);
                }
                $error = $this->refusalText($refused, 'inbox.link.refused.');
            }
        }

        return $this->render('@Inbox/link.html.twig', [
            'id' => $id,
            'values' => $values,
            'error' => $error,
            'kinds' => array_combine($kinds, array_map($this->kindLabel(...), $kinds)),
            'token' => self::TOKEN,
        ], $this->status($error));
    }

    /**
     * The inbox's views as its address names them (spec D10): the person's views, the
     * one ?view= names, its All when none, and the filter the list shows, the view's own
     * unless f= changed it.
     *
     * @param InputBag<string> $input
     *
     * @return array{list<ListView>, ListView, ViewFilter}
     */
    private function views(InputBag $input): array
    {
        $list = $this->inboxLists->list();
        $views = $this->listViews->of($list, $this->actor->userId());
        $view = ListViews::chosen($views, $input->getString('view'));

        return [$views, $view, WorkingFilter::decode($input->getString(WorkingFilter::PARAM), $list) ?? $view->filter];
    }

    /**
     * @param InputBag<string> $input
     *
     * @return array<string, mixed>
     */
    private function builtQuery(InputBag $input): array
    {
        [, $view] = $this->views($input);
        $query = array_diff_key($input->all(), [FilterRows::PARAM => true, FilterRows::MARKER => true, WorkingFilter::PARAM => true, 'after' => true]);

        return array_filter($query + [WorkingFilter::PARAM => WorkingFilter::param(FilterRows::parse($input->all(FilterRows::PARAM), $this->inboxLists->list()), $view->filter)], static fn (mixed $value): bool => null !== $value && '' !== $value);
    }

    /**
     * The state a list shows: the one its filter names, New when it names none.
     */
    private function stateOf(ViewFilter $shown): ItemState
    {
        foreach ($shown->on(InboxLists::STATE) as $condition) {
            $state = \is_string($condition->value) ? ItemState::tryFrom($condition->value) : null;
            if ($state instanceof ItemState) {
                return $state;
            }
        }

        return ItemState::New;
    }

    private function unmatchedIn(ViewFilter $shown): bool
    {
        return array_any($shown->on(InboxLists::MATCH), static fn (Condition $condition): bool => InboxLists::UNMATCHED === $condition->value);
    }

    /**
     * One row per conversation: the page is newest first, so the first row of a thread is its latest
     * mail, and the count says how many other items the thread has.
     *
     * @param list<ItemRow> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function rows(array $rows, WorkingState $working, string $back): array
    {
        $seen = [];
        $names = [];
        $shown = [];
        foreach ($rows as $row) {
            $item = $row->item;
            if (isset($seen[$item->threadKey()])) {
                continue;
            }
            $seen[$item->threadKey()] = true;
            $contactId = $item->contactId();
            if (null !== $contactId && !\array_key_exists($contactId, $names)) {
                $names[$contactId] = $this->contacts->find($contactId)?->displayName;
            }
            $contact = null === $contactId ? '' : (string) $names[$contactId];
            $sender = $item->originalFrom() ?? $row->message->from ?? '';
            $subject = $row->message instanceof MailMessageView && '' !== trim($row->message->subject) ? $row->message->subject : $this->translator->trans('inbox.item.no_subject');
            $received = $this->time($item->receivedAt());
            $label = 0 === $row->otherInThread ? $subject : $this->translator->trans('inbox.list.thread', ['subject' => $subject, 'count' => $row->otherInThread]);
            $outputs = 0 === $row->outputCount ? '' : $this->translator->trans('inbox.list.outputs', ['count' => $row->outputCount]);
            $shown[] = [
                'id' => $item->id(),
                'href' => $this->generateUrl(self::ITEM_ROUTE, ['id' => $item->id()]),
                'opens' => Opens::PAGE,
                'label' => $label,
                'secondary' => implode(' · ', array_filter([$sender, $received], static fn (string $part): bool => '' !== $part)),
                'cells' => $working->display->cells(new InboxCells($sender, $contact, $label, $received, $outputs)),
                'actions' => $this->rowActions($item, $back),
            ];
        }

        return $shown;
    }

    /**
     * @return list<array<string, string>>
     */
    private function rowActions(InboxItem $item, string $back): array
    {
        $id = ['id' => $item->id()];
        $action = fn (string $route, string $label, string $icon): array => ['label' => $this->translator->trans($label), 'href' => $this->generateUrl($route, $id).$back, 'method' => 'POST', 'icon' => $icon];

        return match ($item->state()) {
            ItemState::New => [$action('app_inbox_item_done', 'inbox.action.done', 'check'), $action('app_inbox_item_discard', 'inbox.action.discard', 'trash-2')],
            ItemState::Done, ItemState::Discarded => [$action('app_inbox_item_reopen', 'inbox.action.reopen', 'inbox')],
        };
    }

    /**
     * @param list<InboxItem> $items
     *
     * @return list<array<string, mixed>>
     */
    private function thread(array $items): array
    {
        $messages = $this->messages->findMany(array_map(static fn (InboxItem $item): string => $item->mailMessageId(), $items));

        return array_map(fn (InboxItem $item): array => $this->threadEntry($item, $messages[$item->mailMessageId()] ?? null), $items);
    }

    /**
     * @return array<string, mixed>
     */
    private function threadEntry(InboxItem $item, ?MailMessageView $message): array
    {
        return [
            'href' => $this->generateUrl(self::ITEM_ROUTE, ['id' => $item->id()]),
            'subject' => $message instanceof MailMessageView && '' !== trim($message->subject) ? $message->subject : $this->translator->trans('inbox.item.no_subject'),
            'from' => $item->originalFrom() ?? $message?->from,
            'received' => $this->time($item->receivedAt()),
            'state' => $this->translator->trans('inbox.state.'.$item->state()->value),
        ];
    }

    /**
     * What a new contact starts with: the sender's name and address. A forward names its original
     * sender in its text; a plain mail names the sender in its From field.
     *
     * @return array<string, string>
     */
    private function senderValues(ItemDetail $detail): array
    {
        $item = $detail->item;
        $name = '';
        $email = $item->originalFrom() ?? (string) $detail->message?->from;
        if ($item->isForwarded()) {
            $name = (string) $this->forwards->parse($detail->text)?->name;
        } elseif ($detail->message instanceof MailMessageView) {
            $name = $this->fromName($detail->message->id);
        }

        return [
            'kind' => 'company',
            'displayName' => mb_substr($name, 0, 200),
            'street' => '',
            'city' => '',
            'postalCode' => '',
            'countryCode' => '',
            'registrationNumber' => '',
            'vatId' => '',
            'email' => $email,
            'phone' => '',
        ];
    }

    /**
     * The display name of the From field, if it has one.
     */
    private function fromName(string $messageId): string
    {
        try {
            $from = $this->messages->mail($messageId)->headers('From')[0] ?? '';
        } catch (\RuntimeException|\InvalidArgumentException) {
            // The raw file is missing, changed or not a mail: the dialog starts without a name.
            return '';
        }
        if (1 === preg_match('/^\s*"?([^"<]{1,200}?)"?\s*<[^>]+>\s*$/u', $from, $match)) {
            return trim($match[1]);
        }

        return '';
    }

    /**
     * The tabs of the inbox and the other views of its Settings sheet (spec D7), without counts (D9).
     *
     * @param list<ListView>        $views
     * @param array<string, string> $query
     *
     * @return array{tabs: list<array<string, mixed>>, moreViews: list<array<string, mixed>>}
     */
    private function tabs(array $views, string $active, array $query, bool $modified): array
    {
        [$primary, $overflow] = ListViews::placed($views);
        $link = fn (ListView $view): array => [
            'label' => $view->translated ? $this->translator->trans($view->name) : $view->name,
            'href' => $this->generateUrl(self::ROUTE, ['view' => $view->isAll() ? null : $view->key] + array_diff_key($query, ['view' => true, 'after' => true, WorkingFilter::PARAM => true] + array_flip(WorkingDisplay::KEYS))),
            'active' => $view->key === $active,
            'modified' => $modified && $view->key === $active,
            'private' => $view->private,
        ];

        return ['tabs' => array_map($link, $primary), 'moreViews' => array_map($link, $overflow)];
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
            'resetHref' => $this->generateUrl(self::ROUTE, $kept + [WorkingDisplay::DENSITY => '']),
            'modified' => $working->modified,
        ];
    }

    /**
     * @return array{title: string, text: string}
     */
    private function emptyState(ListView $view): array
    {
        $key = match (true) {
            $view->isAll() => 'new',
            InboxLists::DONE_VIEW === $view->key => 'done',
            InboxLists::DISCARDED_VIEW === $view->key => 'discarded',
            InboxLists::UNMATCHED_VIEW === $view->key => 'unmatched',
            default => null,
        };
        if (null === $key) {
            return ['title' => $this->translator->trans('inbox.empty.view'), 'text' => ''];
        }

        return ['title' => $this->translator->trans('inbox.empty.'.$key.'_title'), 'text' => $this->translator->trans('inbox.empty.'.$key.'_text')];
    }

    private function download(MailAttachmentContent $content, int $index): Response
    {
        $name = $this->safeName($content->name, $index);
        $response = new Response($content->bytes);
        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $name, 'attachment'.$index));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "sandbox; default-src 'none'");
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    /**
     * The file name the sender gave, reduced to what a header and a file system take: no path, no
     * control characters, no quotes.
     */
    private function safeName(string $name, int $index): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim((string) preg_replace('/[\x00-\x1f\x7f"\\\\\/%:*?<>|]+/u', '_', $name), " ._\t");
        $name = mb_substr($name, 0, 120);

        return '' === $name ? 'attachment-'.($index + 1) : $name;
    }

    private function kindLabel(string $kind): string
    {
        $key = 'inbox.link.kind.'.$kind;
        $label = $this->translator->trans($key);

        return $label === $key ? ucfirst(str_replace('_', ' ', $kind)) : $label;
    }

    private function size(int $bytes): string
    {
        return $bytes < 1024 ? $this->translator->trans('inbox.item.size_b', ['size' => $bytes]) : $this->translator->trans('inbox.item.size_kb', ['size' => (int) ceil($bytes / 1024)]);
    }

    private function time(\DateTimeImmutable $time): string
    {
        return $time->setTimezone($this->clock->timezone())->format($this->translator->trans('format.datetime'));
    }

    private function formField(?string $field): ?string
    {
        return null === $field ? null : lcfirst(str_replace('_', '', ucwords($field, '_')));
    }

    /**
     * The words of a refusal in a form: the module's own translation of its reason, else its message,
     * which names no mail text.
     */
    private function refusalText(Refused $refused, string $prefix): string
    {
        $key = $prefix.$refused->reason();
        $text = $this->translator->trans($key);

        return $text === $key ? $refused->getMessage() : $text;
    }

    private function formError(InboxRefused $refused, string $prefix): string
    {
        if (RefusalKind::Invalid !== $refused->kind() && RefusalKind::Conflict !== $refused->kind()) {
            throw $this->httpError($refused);
        }

        return $this->refusalText($refused, $prefix);
    }

    /**
     * The contacts an address book duplicate matched, to open instead of making another.
     *
     * @return list<array{name: string, href: string}>
     */
    private function existing(Refused $refused): array
    {
        $ids = $refused->details()['existing_ids'] ?? [];
        $found = [];
        foreach (\is_array($ids) ? $ids : [] as $contactId) {
            $contact = \is_string($contactId) ? $this->contacts->find($contactId) : null;
            if ($contact instanceof PartySummary) {
                $found[] = ['name' => $contact->displayName, 'href' => $this->generateUrl('app_contact_show', ['id' => $contact->id])];
            }
        }

        return $found;
    }

    private function status(?string $error): Response
    {
        return new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * Back to the list the action was asked from, or to the item.
     */
    private function afterAction(Request $request, string $id, bool $toList): RedirectResponse
    {
        if (!$toList) {
            return $this->redirectToRoute(self::ITEM_ROUTE, ['id' => $id]);
        }

        return $this->redirectToRoute(self::ROUTE, array_filter(array_intersect_key($request->query->all(), array_flip(self::LIST_QUERY)), \is_string(...)));
    }

    private function denyUnlessStaff(): void
    {
        if (!$this->actor->isStaff()) {
            throw new AccessDeniedHttpException('Only the owners and members of the organisation have an inbox.');
        }
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
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

    private function httpError(Refused $refused): \Throwable
    {
        return match ($refused->kind()) {
            RefusalKind::NotFound => new NotFoundHttpException($refused->getMessage(), $refused),
            RefusalKind::Conflict => new ConflictHttpException($refused->getMessage(), $refused),
            default => new AccessDeniedHttpException($refused->getMessage(), $refused),
        };
    }
}
