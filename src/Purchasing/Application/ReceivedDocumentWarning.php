<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application;

/**
 * Something about a saved document a person should look at; it never stops the
 * save. The code is stable: the API returns it and it is the last part of a
 * translation key under purchasing.warning. The other documents it is about,
 * or the date it names, come with it.
 */
final readonly class ReceivedDocumentWarning
{
    /** Another document has the same original file. */
    public const string DUPLICATE_FILE = 'duplicate_file';
    /** The supplier's number is on another recorded document of the same supplier. */
    public const string DUPLICATE_SUPPLIER_NUMBER = 'duplicate_supplier_number';
    /** The latest deduction date has passed. */
    public const string DEDUCTION_DEADLINE_PASSED = 'deduction_deadline_passed';
    /** The latest deduction date is at most DEADLINE_DAYS away. */
    public const string DEDUCTION_DEADLINE_NEAR = 'deduction_deadline_near';
    /** A simplified receipt above the limit of a simplified tax document. */
    public const string SIMPLIFIED_OVER_LIMIT = 'simplified_receipt_over_limit';
    /** The document refused some values of the reading of its file; they wait as suggestions. */
    public const string READING_PARTLY_APPLIED = 'reading_partly_applied';
    /** More than one active contact has the supplier's registration or VAT number the reading found. */
    public const string SUPPLIER_AMBIGUOUS = 'supplier_ambiguous';
    /** No active contact has the supplier's registration or VAT number the reading found. */
    public const string SUPPLIER_NOT_FOUND = 'supplier_not_found';

    public const int DEADLINE_DAYS = 60;

    /**
     * @param list<string> $documentIds
     */
    public function __construct(
        public string $code,
        public array $documentIds = [],
        public ?\DateTimeImmutable $date = null,
    ) {
    }
}
