<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Contract\Reading;

/**
 * What a reader can propose about a received document. The value is the key
 * under which a reading is stored.
 */
enum ReadField: string
{
    case Kind = 'kind';
    case Treatment = 'treatment';
    case SupplierName = 'supplier_name';
    case SupplierRegistrationNumber = 'supplier_registration_number';
    case SupplierVatId = 'supplier_vat_id';
    case SupplierCountry = 'supplier_country';
    case SupplierNumber = 'supplier_number';
    case VariableSymbol = 'variable_symbol';
    case IssueDate = 'issue_date';
    case TaxPointDate = 'tax_point_date';
    case DueDate = 'due_date';
    case Currency = 'currency';
    case Total = 'total';
    case VatRecap = 'vat_recap';
    case BankAccount = 'bank_account';
}
