<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Webhooks\Application\View;

use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ListDefinitionSource;
use Foresi\Shared\Application\View\ListFields;

/**
 * The webhook endpoints of Settings → Webhooks as a list with views
 * (docs/design/list-pages.md, rule 8). Its one shipped view is All, which shows the disabled
 * endpoints too, as the page always did.
 */
final class WebhookEndpointLists implements ListDefinitionSource
{
    public const string KEY = 'webhooks.endpoints';
    public const string URL = 'url';
    public const string DESCRIPTION = 'description';
    public const string DISABLED = 'disabled';

    private ?ListDefinition $list = null;

    public function __construct(private readonly ListFields $fields)
    {
    }

    public function lists(): iterable
    {
        yield $this->list();
    }

    public function list(): ListDefinition
    {
        return $this->list ??= new ListDefinition(self::KEY, $this->fields->of(self::KEY, [
            new Field(self::URL, FieldType::Text, 'webhooks.column.url'),
            new Field(self::DESCRIPTION, FieldType::Text, 'webhooks.column.description'),
            new Field(self::DISABLED, FieldType::Boolean, 'webhooks.state.disabled'),
        ]), [new DefaultView(ListDefinition::ALL, 'webhooks.tab.all', showCount: false, position: 0)]);
    }
}
