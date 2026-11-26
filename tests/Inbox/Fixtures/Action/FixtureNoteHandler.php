<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Fixtures\Action;

use Foresi\Party\Contract\ContactCreation;
use Foresi\Party\Contract\NewContact;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class FixtureNoteHandler implements OperationHandler
{
    public const string MADE_BEFORE_FAILING = 'Made Before Failing';

    public function __construct(private FixtureNotes $notes, private ContactCreation $contacts)
    {
    }

    public static function input(): string
    {
        return FixtureNoteInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof FixtureNoteInput);
        if ($input->fail) {
            throw new FixtureNoteRefused();
        }
        if ($input->failAfter || $input->explode) {
            $this->contacts->create(new NewContact('person', self::MADE_BEFORE_FAILING, 'Example Street 2', 'Example City', '110 00', 'CZ', '', email: 'made.before@example.com'));
            if ($input->explode) {
                throw new \RuntimeException('Exploded with the secret text of the mail.');
            }
            throw new FixtureNoteRefused('failAfter');
        }
        $id = $this->notes->make($input->text, $input->aboutId);
        $this->notes->markDone[] = $input->markDone;

        return new OperationOutput(['id' => $id, 'text' => $input->text], 'Made the note.');
    }
}
