<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application;

use Foresi\Inbox\Contract\InboxAction;
use Foresi\Inbox\Contract\InboxActionCondition;
use Foresi\Inbox\Contract\InboxItemView;
use Foresi\Inbox\Contract\InboxPreFill;
use Foresi\Inbox\Contract\PreFill;
use Foresi\Shared\Infrastructure\Operation\Operations;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The actions on an inbox item that the installed modules declare: every
 * operation whose input carries #[InboxAction], with the conditions and
 * pre-fills the modules register. Inbox knows none of them by name, so a
 * module adds an action without a change here.
 */
final class InboxActions
{
    /** @var array<class-string, InboxAction|null> */
    private static array $attributes = [];
    /** @var list<DeclaredAction>|null */
    private ?array $actions = null;
    /** @var array<string, InboxActionCondition>|null */
    private ?array $conditionsByClass = null;
    /** @var array<string, InboxPreFill>|null */
    private ?array $prefillsByOperation = null;

    /**
     * @param iterable<InboxActionCondition> $conditions
     * @param iterable<InboxPreFill>         $prefills
     */
    public function __construct(
        private readonly Operations $operations,
        #[AutowireIterator(InboxActionCondition::TAG)]
        private readonly iterable $conditions = [],
        #[AutowireIterator(InboxPreFill::TAG)]
        private readonly iterable $prefills = [],
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * The attribute of an input class, or null for an input that is no inbox action.
     *
     * @param class-string $input
     */
    public static function attributeOf(string $input): ?InboxAction
    {
        // Every operation of every module asks (RecordOutputs), so each class is read once.
        if (!\array_key_exists($input, self::$attributes)) {
            self::$attributes[$input] = (new \ReflectionClass($input)->getAttributes(InboxAction::class)[0] ?? null)?->newInstance();
        }

        return self::$attributes[$input];
    }

    /**
     * @return list<DeclaredAction> by weight, then by operation name
     */
    public function all(): array
    {
        if (null === $this->actions) {
            $actions = [];
            foreach ($this->operations->all() as $definition) {
                $attribute = self::attributeOf($definition->input);
                if ($attribute instanceof InboxAction) {
                    $actions[] = new DeclaredAction($definition, $attribute);
                }
            }
            usort($actions, static fn (DeclaredAction $a, DeclaredAction $b): int => [$a->action->weight, $a->name()] <=> [$b->action->weight, $b->name()]);
            $this->actions = $actions;
        }

        return $this->actions;
    }

    public function get(string $operation): ?DeclaredAction
    {
        foreach ($this->all() as $action) {
            if ($action->name() === $operation) {
                return $action;
            }
        }

        return null;
    }

    public function isAction(string $operation): bool
    {
        return $this->get($operation) instanceof DeclaredAction;
    }

    /**
     * The actions offered on the item: its condition applies, and the check
     * (such as the token's scopes) allows the operation's scope. A view is never
     * of an item in the trash (InboxItems::find); the caller offers nothing on one.
     *
     * @param (callable(string): bool)|null $allows whether the operation's scope is allowed; null allows every one
     *
     * @return list<DeclaredAction>
     */
    public function available(InboxItemView $item, ?callable $allows = null): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (DeclaredAction $action): bool => (null === $allows || $allows($action->definition->operation->scope)) && $this->applies($action->action, $item),
        ));
    }

    /**
     * Whether the action's condition applies to the item; an action without one applies to every item.
     *
     * @throws \LogicException for a condition no service implements
     */
    public function applies(InboxAction $action, InboxItemView $item): bool
    {
        if (null === $action->appliesTo) {
            return true;
        }
        $this->conditionsByClass ??= $this->byKey($this->conditions, static fn (InboxActionCondition $condition): string => $condition::class);
        $condition = $this->conditionsByClass[$action->appliesTo] ?? throw new \LogicException(\sprintf('No service is the inbox action condition %s.', $action->appliesTo));

        return $condition->applies($item);
    }

    /**
     * The starting values of the action's fields for the item; none when no
     * module fills it, or when its pre-fill fails, which is logged by ids and
     * class alone, so that the item and its other actions still show.
     */
    public function prefill(DeclaredAction $action, InboxItemView $item): PreFill
    {
        $this->prefillsByOperation ??= $this->byKey($this->prefills, static fn (InboxPreFill $prefill): string => $prefill->operation());
        $prefill = $this->prefillsByOperation[$action->name()] ?? null;
        if (!$prefill instanceof InboxPreFill) {
            return new PreFill();
        }
        try {
            return $prefill->prefill($item);
        } catch (\Throwable $failure) {
            // Ids and the class of the failure only: its message may carry the mail.
            $this->logger->error('The pre-fill of an inbox action failed.', [
                'item_id' => $item->id,
                'operation' => $action->name(),
                'prefill' => $prefill::class,
                'failure' => $failure::class,
            ]);

            return new PreFill();
        }
    }

    /**
     * @template T of object
     *
     * @param iterable<T>         $services
     * @param \Closure(T): string $key
     *
     * @return array<string, T>
     */
    private function byKey(iterable $services, \Closure $key): array
    {
        $keyed = [];
        foreach ($services as $service) {
            $keyed[$key($service)] = $service;
        }

        return $keyed;
    }
}
