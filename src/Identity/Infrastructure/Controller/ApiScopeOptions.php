<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\Controller;

use Foresi\Identity\Application\GuestScopes;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Shared\Application\Api\ApiResources;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The scopes a person can give a token or an OAuth client, with their labels:
 * all reads, all writes, then reading or changing each resource of the
 * installed modules. One list for the token form and the consent screen; a
 * guest's consent screen offers only the scopes a guest may hold (ADR 0041).
 * The consent screen shows them grouped by the module that declares them.
 */
final readonly class ApiScopeOptions
{
    public function __construct(
        private ApiResources $resources,
        private TranslatorInterface $translator,
        private GuestScopes $guestScopes,
    ) {
    }

    /**
     * @return array<string, string> scope => label
     */
    public function offered(): array
    {
        $offered = [ApiScope::READ => $this->label(ApiScope::read()), ApiScope::WRITE => $this->label(ApiScope::write())];
        foreach ($this->resources->declared() as $resource) {
            foreach ([false, true] as $write) {
                $scope = ApiScope::of($resource, $write);
                $offered[$scope->value] = $this->label($scope);
            }
        }

        return $offered;
    }

    /**
     * @return array<string, string> scope => label
     */
    public function offeredToGuests(): array
    {
        $offered = [];
        foreach ($this->guestScopes->offered() as $scope) {
            $offered[$scope->value] = $this->label($scope);
        }

        return $offered;
    }

    /**
     * The offered scopes as the consent screen shows them: the whole account first, then
     * each module with its resources and each resource with reading and changing, as the
     * consent screens of GitHub (permissions by resource, read-only or read and write) and
     * Atlassian (scopes by product) group them. A resource or a level not offered is left
     * out, so a guest sees only what a guest may hold.
     *
     * @param array<string, string> $offered scope => label, offered() or offeredToGuests()
     *
     * @return array{account: array{read: ?string, write: ?string}, modules: list<array{label: string, resources: list<array{label: string, read: ?string, write: ?string}>}>}
     */
    public function grouped(array $offered): array
    {
        $level = static fn (string $scope): ?string => \array_key_exists($scope, $offered) ? $scope : null;
        $modules = [];
        foreach ($this->resources->byModule() as $module => $names) {
            $resources = [];
            foreach ($names as $resource) {
                $read = $level(ApiScope::of($resource, false)->value);
                $write = $level(ApiScope::of($resource, true)->value);
                if (null !== $read || null !== $write) {
                    $resources[] = ['label' => $this->translator->trans('api_tokens.resource.'.$resource), 'read' => $read, 'write' => $write];
                }
            }
            if ([] !== $resources) {
                $modules[] = ['label' => $this->moduleLabel($module, $resources), 'resources' => $resources];
            }
        }
        $collator = new \Collator($this->translator instanceof LocaleAwareInterface ? $this->translator->getLocale() : 'en');
        usort($modules, static fn (array $a, array $b): int => (int) $collator->compare($a['label'], $b['label']));

        return ['account' => ['read' => $level(ApiScope::READ), 'write' => $level(ApiScope::WRITE)], 'modules' => $modules];
    }

    public function label(ApiScope $scope): string
    {
        $resource = $scope->resource();
        if (null === $resource) {
            return $this->translator->trans('api_tokens.scope.'.$scope->value);
        }
        $name = $this->translator->trans('api_tokens.resource.'.$resource);

        return $this->translator->trans($scope->writes() ? 'api_tokens.scope.resource_write' : 'api_tokens.scope.resource_read', ['resource' => $name]);
    }

    /**
     * A module of one resource goes by that resource; a module of a vendor without a
     * translated name goes by its id.
     *
     * @param non-empty-list<array{label: string, read: ?string, write: ?string}> $resources
     */
    private function moduleLabel(string $module, array $resources): string
    {
        if (1 === \count($resources)) {
            return $resources[0]['label'];
        }
        $key = 'api_tokens.module.'.$module;
        $label = $this->translator->trans($key);

        return $key === $label ? $module : $label;
    }
}
