<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Brand;
use App\Models\Product;
use App\Models\User;
use App\Models\ActivityLog;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductEnhancementsTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $cashierUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->adminUser = User::create([
            'name' => 'Admin POS',
            'username' => 'admin_pos',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);
        $this->adminUser->assignRole('admin');

        $this->cashierUser = User::create([
            'name' => 'Cashier Budi',
            'username' => 'cashier_budi',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);
        $this->cashierUser->assignRole('kasir');
    }

    public function test_admin_can_manage_categories(): void
    {
        // 1. Create category
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/categories', [
                'nama' => 'Makanan Ringan',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.nama', 'Makanan Ringan');

        $category = Category::first();
        $this->assertNotNull($category);

        // Check activity log
        $this->assertTrue(
            ActivityLog::where('action', 'create_category')
                ->where('description', "Category 'Makanan Ringan' was created.")
                ->exists()
        );

        // 2. Index categories
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/v1/categories');

        $response->assertStatus(200)
            ->assertJsonFragment(['nama' => 'Makanan Ringan']);

        // 3. Update category
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->putJson("/api/v1/categories/{$category->id}", [
                'nama' => 'Snack & Makanan Ringan',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.nama', 'Snack & Makanan Ringan');

        // 4. Delete category
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->deleteJson("/api/v1/categories/{$category->id}");

        $response->assertStatus(200);
        $this->assertNull(Category::find($category->id));

        // Check delete activity log
        $this->assertTrue(
            ActivityLog::where('action', 'delete_category')
                ->where('description', "Category 'Snack & Makanan Ringan' was deleted.")
                ->exists()
        );
    }

    public function test_admin_can_manage_brands(): void
    {
        // 1. Create brand
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/brands', [
                'nama' => 'Indofood',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.nama', 'Indofood');

        $brand = Brand::first();
        $this->assertNotNull($brand);

        // Check activity log
        $this->assertTrue(
            ActivityLog::where('action', 'create_brand')
                ->where('description', "Brand 'Indofood' was created.")
                ->exists()
        );

        // 2. Index brands
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/v1/brands');

        $response->assertStatus(200)
            ->assertJsonFragment(['nama' => 'Indofood']);

        // 3. Delete brand
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->deleteJson("/api/v1/brands/{$brand->id}");

        $response->assertStatus(200);
        $this->assertNull(Brand::find($brand->id));
    }

    public function test_cashier_cannot_manage_categories_or_brands(): void
    {
        // Cashier can read categories/brands
        $response = $this->actingAs($this->cashierUser, 'sanctum')
            ->getJson('/api/v1/categories');
        $response->assertStatus(200);

        $response = $this->actingAs($this->cashierUser, 'sanctum')
            ->getJson('/api/v1/brands');
        $response->assertStatus(200);

        // Cashier CANNOT create category
        $response = $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson('/api/v1/categories', ['nama' => 'Roti']);
        $response->assertStatus(403);

        // Cashier CANNOT create brand
        $response = $this->actingAs($this->cashierUser, 'sanctum')
            ->postJson('/api/v1/brands', ['nama' => 'Sari Roti']);
        $response->assertStatus(403);
    }

    public function test_product_barcode_auto_generation(): void
    {
        // Create product without barcode
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/products', [
                'nama' => 'Keripik Singkong',
                'stok' => 50,
                'harga' => 5000,
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['data' => ['barcode']]);

        $product = Product::first();
        $this->assertNotNull($product->barcode);
        $this->assertEquals(13, strlen($product->barcode));
        $this->assertTrue(str_starts_with($product->barcode, '20'));

        // Validate EAN-13 Checksum digit
        $digits = substr($product->barcode, 0, 12);
        $expectedChecksum = (int)$product->barcode[12];
        
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $digit = (int)$digits[$i];
            $sum += ($i % 2 === 0) ? $digit : $digit * 3;
        }
        $remainder = $sum % 10;
        $calculatedChecksum = $remainder === 0 ? 0 : 10 - $remainder;
        
        $this->assertEquals($calculatedChecksum, $expectedChecksum);
    }

    public function test_product_image_upload_and_relationship(): void
    {
        Storage::fake('public');
        
        $category = Category::create(['nama' => 'Minuman']);
        $brand = Brand::create(['nama' => 'Nestle']);

        $image = UploadedFile::fake()->create('milo.jpg', 100, 'image/jpeg');

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/products', [
                'nama' => 'Milo Box 1L',
                'category_id' => $category->id,
                'brand_id' => $brand->id,
                'stok' => 25,
                'harga' => 18000,
                'image' => $image,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.nama', 'Milo Box 1L')
            ->assertJsonPath('data.category_id', $category->id)
            ->assertJsonPath('data.brand_id', $brand->id)
            ->assertJsonStructure(['data' => ['image_url']]);

        $product = Product::first();
        $this->assertNotNull($product->image_path);
        
        // Assert file exists in fake storage disk
        Storage::disk('public')->assertExists($product->image_path);
        
        // Verify image URL format
        $this->assertStringContainsString('/storage/', $product->image_url);
        $this->assertStringContainsString('.jpg', $product->image_url);
    }

    public function test_print_barcode_endpoints(): void
    {
        $product = Product::create([
            'nama' => 'Indomie Goreng',
            'stok' => 100,
            'harga' => 3100,
            'barcode' => '8998866200728',
        ]);

        // 1. Single barcode print HTML view
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->get("/api/v1/products/{$product->id}/print-barcode?quantity=6");

        $response->assertStatus(200)
            ->assertSee('Indomie Goreng')
            ->assertSee('8998866200728')
            ->assertSee('<svg', false) // Barcode generator outputs inline SVG
            ->assertSee('label-card'); // Grid CSS layout check

        // 2. Bulk barcode print HTML view
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/products/print-barcodes', [
                'products' => [
                    ['id' => $product->id, 'quantity' => 2],
                ],
            ]);

        $response->assertStatus(200)
            ->assertSee('Indomie Goreng')
            ->assertSee('<svg', false);
    }
}
