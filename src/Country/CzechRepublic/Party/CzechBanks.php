<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Country\CzechRepublic\Party;

/**
 * The payment system codes of Czech banks, from the list of the Czech National Bank:
 * https://www.cnb.cz/cs/platebni-styk/.galleries/ucty_kody_bank/download/kody_bank_CR.csv
 * as published on 2026-11-02. A bank without a BIC there has an empty one here.
 */
final class CzechBanks
{
    /** @var array<int|string, array{string, string}> name and BIC by code */
    private const array BANKS = [
        '0100' => ['Komerční banka, a.s.', 'KOMBCZPP'],
        '0300' => ['Československá obchodní banka, a. s.', 'CEKOCZPP'],
        '0600' => ['MONETA Money Bank, a.s.', 'AGBACZPP'],
        '0710' => ['ČESKÁ NÁRODNÍ BANKA', 'CNBACZPP'],
        '0800' => ['Česká spořitelna, a.s.', 'GIBACZPX'],
        '2010' => ['Fio banka, a.s.', 'FIOBCZPP'],
        '2060' => ['Citfin, spořitelní družstvo', 'CITFCZPP'],
        '2070' => ['TRINITY BANK a.s.', 'MPUBCZPP'],
        '2100' => ['ČSOB Hypoteční banka, a.s.', ''],
        '2200' => ['Peněžní dům, spořitelní družstvo', ''],
        '2220' => ['Artesa, spořitelní družstvo', 'ARTTCZPP'],
        '2250' => ['Banka CREDITAS a.s.', 'CTASCZ22'],
        '2600' => ['Citibank Europe plc, organizační složka', 'CITICZPX'],
        '2700' => ['UniCredit Bank Czech Republic and Slovakia, a.s.', 'BACXCZPP'],
        '3030' => ['Air Bank a.s.', 'AIRACZPP'],
        '3060' => ['PKO BP S.A., Czech Branch', 'BPKOCZPP'],
        '3500' => ['ING Bank N.V.', 'INGBCZPP'],
        '4300' => ['Národní rozvojová banka, a.s.', 'NROZCZPP'],
        '5500' => ['Raiffeisenbank a.s.', 'RZBCCZPP'],
        '5800' => ['J&T BANKA, a.s.', 'JTBPCZPP'],
        '6000' => ['PPF banka a.s.', 'PMBPCZPP'],
        '6200' => ['COMMERZBANK Aktiengesellschaft, pobočka Praha', 'COBACZPX'],
        '6210' => ['mBank S.A., organizační složka', 'BREXCZPP'],
        '6300' => ['BNP Paribas S.A., pobočka Česká republika', 'GEBACZPP'],
        '6363' => ['Partners Banka, a.s.', 'PTBNCZPP'],
        '6600' => ['Banking Circle S.A., Czech Republic', ''],
        '6700' => ['Všeobecná úverová banka a.s., pobočka Praha', 'SUBACZPP'],
        '6800' => ['Sberbank CZ, a.s. v likvidaci', 'VBOECZ2X'],
        '7910' => ['Deutsche Bank Aktiengesellschaft Filiale Prag, organizační složka', 'DEUTCZPX'],
        '7950' => ['Raiffeisen stavební spořitelna a.s.', ''],
        '7960' => ['ČSOB Stavební spořitelna, a.s.', ''],
        '7970' => ['MONETA Stavební Spořitelna, a.s.', ''],
        '7990' => ['Modrá pyramida stavební spořitelna, a.s.', ''],
        '8030' => ['Volksbank Raiffeisenbank Nordoberpfalz eG pobočka Cheb', 'GENOCZ21'],
        '8040' => ['Oberbank AG pobočka Česká republika', 'OBKLCZ2X'],
        '8060' => ['Stavební spořitelna České spořitelny, a.s.', ''],
        '8090' => ['Česká exportní banka, a.s.', 'CZEECZPP'],
        '8150' => ['HSBC Continental Europe, Czech Republic', 'MIDLCZPP'],
        '8198' => ['FAS finance company s.r.o.', 'FFCSCZP1'],
        '8220' => ['Payment execution s.r.o.', 'PAERCZP1'],
        '8250' => ['Bank of China (CEE) Ltd. Prague Branch', 'BKCHCZPP'],
        '8255' => ['Bank of Communications Co., Ltd., Prague Branch odštěpný závod', 'COMMCZPP'],
        '8265' => ['Industrial and Commercial Bank of China Limited, Prague Branch, odštěpný závod', 'ICBKCZPP'],
        '8500' => ['Multitude Bank p.l.c.', ''],
        '8610' => ['Devizová burza a.s.', ''],
        '8620' => ['Comgate a.s.', ''],
        '8660' => ['PAYMONT, UAB', ''],
    ];

    public static function nameOf(string $code): string
    {
        return self::BANKS[$code][0] ?? '';
    }

    public static function bicOf(string $code): string
    {
        return self::BANKS[$code][1] ?? '';
    }
}
