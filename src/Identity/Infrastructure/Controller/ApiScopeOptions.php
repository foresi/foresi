<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\Controller;

use Foresi\Identity\Domain\ApiScope;
use Foresi\Shared\Application\Api\ApiResources;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The scopes a person can give a token or an OAuth client, with their labels:
 * all reads, all writes, then reading or changing each resource of the
 * installed modules. One list for the token form and the consent screen.
 */
final readonly class ApiScopeOptions
{
    public function __construct(
        private ApiResources $resources,
        private TranslatorInterface $translator,
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

    public function label(ApiScope $scope): string
    {
        $resource = $scope->resource();
        if (null === $resource) {
            return $this->translator->trans('api_tokens.scope.'.$scope->value);
        }
        $name = $this->translator->trans('api_tokens.resource.'.$resource);

        return $this->translator->trans($scope->writes() ? 'api_tokens.scope.resource_write' : 'api_tokens.scope.resource_read', ['resource' => $name]);
    }
}
