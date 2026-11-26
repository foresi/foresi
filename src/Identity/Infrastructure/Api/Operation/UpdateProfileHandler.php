<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\Api\Operation;

use Foresi\Identity\Application\ApiTokenContext;
use Foresi\Identity\Application\InsufficientScope;
use Foresi\Identity\Application\ManageProfile;
use Foresi\Identity\Application\UpdateProfileInput;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Infrastructure\Api\ProfileJson;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class UpdateProfileHandler implements OperationHandler
{
    public function __construct(private ApiTokenContext $tokens, private ManageProfile $profile, private ProfileJson $json)
    {
    }

    public static function input(): string
    {
        return UpdateProfileInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof UpdateProfileInput);
        $grant = $this->tokens->grant() ?? throw new InsufficientScope(ApiScope::of('profile', true));
        $user = $this->profile->update($grant->userId, $input);

        return new OperationOutput($this->json->of($user), \sprintf('Updated the profile of %s.', $user->displayName()));
    }
}
