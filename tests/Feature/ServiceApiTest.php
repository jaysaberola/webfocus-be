<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ServiceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_services_crud_bulk_actions_and_restore_flow(): void
    {
        Storage::fake('public');

        $user = $this->createUser();
        $category = ServiceCategory::create([
            'name' => 'Consulting',
            'sort_order' => 1,
            'position' => 1,
        ]);

        $createResponse = $this->actingAs($user, 'sanctum')->post('/api/services', [
            'name' => 'Initial Service',
            'price' => '99.50',
            'description' => 'Short description',
            'status' => 'active',
            'category_id' => $category->id,
            'image' => UploadedFile::fake()->image('service.jpg'),
        ]);

        $createResponse->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Service created')
            ->assertJsonPath('data.name', 'Initial Service')
            ->assertJsonPath('data.image', fn ($path) => is_string($path) && str_starts_with($path, 'services/'));

        $serviceId = $createResponse->json('data.id');

        Storage::disk('public')->assertExists(Service::findOrFail($serviceId)->image);

        $this->actingAs($user, 'sanctum')
            ->get('/api/services?search=Initial&status=active&category_id=' . $category->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $serviceId)
            ->assertJsonPath('data.0.image_url', fn ($url) => is_string($url) && str_contains($url, '/storage/services/'));

        $this->actingAs($user, 'sanctum')
            ->put('/api/services/' . $serviceId, [
                'name' => 'Updated Service',
                'status' => 'inactive',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Service updated')
            ->assertJsonPath('data.name', 'Updated Service')
            ->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('data.is_active', false);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/services/bulk-status', [
                'ids' => [$serviceId],
                'status' => 'active',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Status updated');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/services/bulk-delete', [
                'ids' => [$serviceId],
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Services deleted');

        $this->assertSoftDeleted('services', ['id' => $serviceId]);

        $this->actingAs($user, 'sanctum')
            ->post('/api/services/' . $serviceId . '/restore')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Service restored');

        $this->assertDatabaseHas('services', [
            'id' => $serviceId,
            'deleted_at' => null,
            'status' => 'active',
        ]);

        $this->actingAs($user, 'sanctum')
            ->post('/api/services/' . $serviceId, [
                '_method' => 'DELETE',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Service deleted');

        $this->assertSoftDeleted('services', ['id' => $serviceId]);
    }

    public function test_service_update_accepts_multipart_method_spoof_with_image(): void
    {
        Storage::fake('public');

        $user = $this->createUser();
        $service = Service::create([
            'name' => 'Image Service',
            'status' => 'active',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user, 'sanctum')->post('/api/services/' . $service->id, [
            '_method' => 'PUT',
            'name' => 'Image Service Updated',
            'image' => UploadedFile::fake()->image('replacement.jpg'),
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Service updated')
            ->assertJsonPath('data.name', 'Image Service Updated')
            ->assertJsonPath('data.image', fn ($path) => is_string($path) && str_starts_with($path, 'services/'));

        $service->refresh();
        Storage::disk('public')->assertExists($service->image);
    }

    public function test_service_categories_endpoints_support_alias_fields_and_method_spoofing(): void
    {
        $user = $this->createUser();

        $createResponse = $this->actingAs($user, 'sanctum')->postJson('/api/service-categories', [
            'title' => 'Design',
        ]);

        $createResponse->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Category created')
            ->assertJsonPath('data.name', 'Design');

        $categoryId = $createResponse->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->get('/api/fetch-service-categories')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $categoryId);

        $this->actingAs($user, 'sanctum')
            ->post('/api/service-categories/' . $categoryId, [
                '_method' => 'PUT',
                'title' => 'Strategy',
                'order' => 8,
                'position' => 4,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Category updated')
            ->assertJsonPath('data.name', 'Strategy')
            ->assertJsonPath('data.sort_order', 8)
            ->assertJsonPath('data.position', 4);

        $this->actingAs($user, 'sanctum')
            ->post('/api/service-categories/' . $categoryId, [
                '_method' => 'DELETE',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Category deleted');

        $this->assertDatabaseMissing('service_categories', [
            'id' => $categoryId,
        ]);
    }

    private function createUser(): User
    {
        return User::create([
            'fname' => 'Test',
            'lname' => 'User',
            'email' => 'tester' . uniqid('', true) . '@example.com',
            'password' => Hash::make('password'),
        ]);
    }
}
