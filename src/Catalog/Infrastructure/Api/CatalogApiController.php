<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Catalog\Infrastructure\Api;

use Foresi\Catalog\Application\ProductReads;
use Foresi\Catalog\Contract\Field\ProductExtension;
use Foresi\Shared\Application\Api\ScopeGuard;
use Foresi\Shared\Application\Field\CustomFieldPayload;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The catalog read by a personal API token with the catalog:read scope: the
 * products, found by text, and one product. What the use cases refuse is
 * answered by the shared refusal contract (ADR 0032).
 */
#[AsController]
final readonly class CatalogApiController
{
    public const string READ_SCOPE = 'catalog:read';
    public const string PRODUCTS = 'api_v1_catalog_products';
    public const string PRODUCT = 'api_v1_catalog_product';

    public function __construct(private ScopeGuard $scope, private ProductReads $products, private CustomFieldPayload $customFields)
    {
    }

    #[Route('/api/v1/catalog/products', name: self::PRODUCTS, methods: ['GET'])]
    #[OA\Tag(name: 'Catalog')]
    #[OA\Parameter(name: 'q', description: 'Finds the products whose name or description holds this text, without regard to case.', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'archived', description: 'true includes the archived products; they are left out otherwise.', in: 'query', schema: new OA\Schema(type: 'boolean'))]
    #[OA\Response(response: 200, description: 'The products, in name order.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CatalogProduct'))], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the catalog:read scope, or belongs to a guest.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function products(Request $request): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);
        $products = $this->products->search($request->query->getString('q'), \in_array($request->query->getString('archived'), ['1', 'true'], true));

        return new JsonResponse(['data' => $this->customFields->attach(ProductExtension::RECORD, array_map(CatalogJson::product(...), $products))]);
    }

    #[Route('/api/v1/catalog/products/{productId}', name: self::PRODUCT, methods: ['GET'])]
    #[OA\Tag(name: 'Catalog')]
    #[OA\Response(response: 200, description: 'The product.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CatalogProduct')], type: 'object'))]
    #[OA\Response(response: 401, description: 'No valid token.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 403, description: 'The token lacks the catalog:read scope, or belongs to a guest.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 404, description: 'The catalog has no such product.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    public function product(string $productId): JsonResponse
    {
        $this->scope->require(self::READ_SCOPE);

        return new JsonResponse(['data' => $this->customFields->attachOne(ProductExtension::RECORD, CatalogJson::product($this->products->one($productId)))]);
    }
}
