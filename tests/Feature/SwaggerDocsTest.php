<?php

namespace Tests\Feature;

use Tests\TestCase;

class SwaggerDocsTest extends TestCase
{
    public function test_swagger_docs_page_is_available(): void
    {
        $this->get('/docs')
            ->assertOk()
            ->assertSee('Tumbas POS API Docs')
            ->assertSee('SwaggerUIBundle');
    }

    public function test_openapi_spec_is_available(): void
    {
        $this->get('/docs/openapi.json')
            ->assertOk()
            ->assertJsonPath('info.title', 'Tumbas POS API')
            ->assertJsonPath('paths./api/v1/products.get.tags.0', 'Products')
            ->assertJsonPath('paths./api/v1/transactions.post.tags.0', 'Transactions')
            ->assertJsonPath('paths./api/v1/reports/summary.get.tags.0', 'Reports');
    }
}
