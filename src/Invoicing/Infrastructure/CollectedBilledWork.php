<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure;

use Foresi\Invoicing\Application\BilledWork;
use Foresi\Invoicing\Application\InvoiceInput;
use Foresi\Invoicing\Application\IssueOrigin;
use Foresi\Invoicing\Application\IssueProblem;
use Foresi\Invoicing\Application\IssueRefused;
use Foresi\Invoicing\Application\LineInput;
use Foresi\Invoicing\Contract\BilledWork as ModuleBilledWork;
use Foresi\Invoicing\Contract\BilledWorkDraft;
use Foresi\Invoicing\Contract\BilledWorkRefused;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The work every installed module bills, such as Timesheet with time. A
 * reference belongs to the module that recognises it; with none installed,
 * no reference holds. A module's refusal becomes a problem of the form.
 */
final readonly class CollectedBilledWork implements BilledWork
{
    /**
     * @param iterable<ModuleBilledWork> $providers
     */
    public function __construct(
        #[AutowireIterator(ModuleBilledWork::TAG)]
        private iterable $providers,
    ) {
    }

    public function draft(string $reference): ?BilledWorkDraft
    {
        foreach ($this->providers as $provider) {
            $draft = $provider->draft($reference);
            if (null !== $draft) {
                return $draft;
            }
        }

        return null;
    }

    public function origin(InvoiceInput $input): IssueOrigin
    {
        $refused = null;
        foreach ($this->providers as $provider) {
            if (null === $input->billedWork) {
                break;
            }
            try {
                return new BilledWorkIssueOrigin($provider->origin($input->billedWork, array_map(static fn (LineInput $line): ?string => $line->source, $input->lines)));
            } catch (BilledWorkRefused $refusal) {
                $refused = BilledWorkIssueOrigin::refused($refusal);
            }
        }

        throw $refused ?? new IssueRefused([new IssueProblem('form', 'invoice.problem.billed_work_gone')]);
    }
}
