<?php

namespace Tests\Feature;

use Tests\TestCase;

class CatalogNotFoundTest extends TestCase
{
    public function test_unknown_category_slug_returns_404_not_500(): void
    {
        $response = $this->getJson('/api/en/categories/this-slug-does-not-exist');

        $response->assertStatus(404);
        $response->assertJson(['code' => 'not_found']);
    }

    public function test_unknown_subcategory_slug_returns_404_not_500(): void
    {
        $response = $this->getJson('/api/en/categories/real-category/this-slug-does-not-exist');

        $response->assertStatus(404);
        $response->assertJson(['code' => 'not_found']);
    }

    public function test_unknown_product_slug_on_single_product_route_returns_404_not_500(): void
    {
        $response = $this->getJson('/api/en/categories/real-category/real-subcategory/this-slug-does-not-exist');

        $response->assertStatus(404);
        $response->assertJson(['code' => 'not_found']);
    }
}
