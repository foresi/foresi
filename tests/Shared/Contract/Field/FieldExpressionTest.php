<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Contract\Field;

use Foresi\Shared\Contract\Field\FieldExpression;
use PHPUnit\Framework\TestCase;

final class FieldExpressionTest extends TestCase
{
    public function testAnAliasedExpressionRenamesTheAliasAloneOrWithAPath(): void
    {
        $expression = new FieldExpression('Note', 'cf_fleet', 'cf_fleet.id = p.id AND cf_fleet.size > :cf_fleet', 'cf_fleet.size');

        $aliased = $expression->aliased('cfs_fleet');

        self::assertSame('Note', $aliased->entity);
        self::assertSame('cfs_fleet', $aliased->alias);
        self::assertSame('cfs_fleet.id = p.id AND cfs_fleet.size > :cf_fleet', $aliased->correlation);
        self::assertSame('cfs_fleet.size', $aliased->column);
        self::assertSame('cf_fleet', $expression->alias);
    }

    public function testAnAliasedExpressionLeavesALongerNameAndAPathsPart(): void
    {
        $expression = new FieldExpression('Note', 'cf', 'cf.id = n.id AND cf_x.id = n.cf', 'n.cf');

        $aliased = $expression->aliased('x');

        self::assertSame('x.id = n.id AND cf_x.id = n.cf', $aliased->correlation);
        self::assertSame('n.cf', $aliased->column);
    }

    public function testTheRowReadsWithinACorrelationOrAJoin(): void
    {
        $expression = new FieldExpression('Note', 'cf', 'cf.id = n.id', 'cf.due');

        self::assertSame('Note cf WHERE cf.id = n.id', $expression->within());
        self::assertSame('LEFT JOIN Note cf WITH cf.id = n.id', $expression->join());
    }
}
