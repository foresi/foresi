<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

/**
 * What the owner deducts from the tax base in a year: gifts, the interest of a
 * housing loan, pension and life insurance products, a long-term investment
 * product, union fees and the fees of exams that verify further education.
 */
enum DeductionType: string
{
    case Gifts = 'gifts';
    case HousingLoanInterest = 'housing_loan_interest';
    case PensionProducts = 'pension_products';
    case LifeInsurance = 'life_insurance';
    case LongTermInvestment = 'long_term_investment';
    case UnionFees = 'union_fees';
    case ExamFees = 'exam_fees';
}
