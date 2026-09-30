<?php

namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\SecurityScheme;
use ApiPlatform\OpenApi\OpenApi;

/**
 * Decorates the OpenAPI factory to declare the JWT bearer security scheme,
 * so the Swagger UI "Authorize" button can be used with the API.
 */
final class OpenApiFactoryDecorator implements OpenApiFactoryInterface
{
    public function __construct(private readonly OpenApiFactoryInterface $decorated)
    {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);
        $schemes = $openApi->getComponents()->getSecuritySchemes() ?? [];
        $schemes['JWT'] = new SecurityScheme(
            type: 'http',
            scheme: 'bearer',
            bearerFormat: 'JWT',
        );
        $openApi->getComponents()->withSecuritySchemes($schemes);

        return $openApi;
    }
}
