<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Role;
use App\Models\Service;
use App\Models\SubscriptionVersion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ServiceImagePreservationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Branch $branch;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->branch = Branch::factory()->create();
        $this->user = User::factory()->create(['branch_id' => $this->branch->id]);
        $subscription = $this->branch->subscription;
        $subscription->update(['onboarding_completed_at' => now()]);

        SubscriptionVersion::create([
            'subscription_id' => $subscription->id,
            'start_date' => Carbon::yesterday(),
            'end_date' => Carbon::tomorrow(),
        ]);

        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'services.catalog.access',
            'services.catalog.create',
            'services.catalog.see_details',
            'services.catalog.edit',
            'services.catalog.delete',
        ];

        foreach ($permissions as $permission) {
            Permission::create(['name' => $permission, 'module' => 'services']);
        }

        $role = Role::create(['name' => 'Admin Servicios', 'branch_id' => $this->branch->id]);
        $role->givePermissionTo($permissions);
        $this->user->assignRole($role);

        $this->category = Category::factory()->create([
            'type' => 'service',
            'subscription_id' => $subscription->id,
            'name' => 'Mantenimiento',
        ]);

        $this->actingAs($this->user);
    }

    #[Test]
    public function it_keeps_the_existing_image_when_updating_without_a_new_file(): void
    {
        $service = $this->serviceWithImage();

        $response = $this->put(route('services.update', $service), $this->updatePayload($service));

        $response->assertRedirect(route('services.index'));

        $service->refresh();
        $this->assertCount(1, $service->getMedia('service-image'), 'La imagen existente se perdió al actualizar sin subir archivo.');
    }

    #[Test]
    public function it_replaces_the_image_only_when_a_new_file_is_sent(): void
    {
        $service = $this->serviceWithImage();
        $originalId = $service->getFirstMedia('service-image')->id;

        $response = $this->put(
            route('services.update', $service),
            $this->updatePayload($service) + ['image' => UploadedFile::fake()->image('nueva.jpg')]
        );

        $response->assertRedirect(route('services.index'));

        $service->refresh();
        $media = $service->getMedia('service-image');
        $this->assertCount(1, $media);
        $this->assertNotSame($originalId, $media->first()->id);
    }

    #[Test]
    public function it_removes_the_image_when_explicitly_requested(): void
    {
        $service = $this->serviceWithImage();

        $response = $this->put(
            route('services.update', $service),
            $this->updatePayload($service) + ['remove_image' => true]
        );

        $response->assertRedirect(route('services.index'));

        $service->refresh();
        $this->assertCount(0, $service->getMedia('service-image'));
    }

    #[Test]
    public function it_keeps_the_image_when_the_removal_flag_is_false(): void
    {
        $service = $this->serviceWithImage();

        $response = $this->put(
            route('services.update', $service),
            $this->updatePayload($service) + ['remove_image' => false]
        );

        $response->assertRedirect(route('services.index'));

        $service->refresh();
        $this->assertCount(1, $service->getMedia('service-image'), 'La imagen se perdió al enviar la bandera de eliminación en falso.');
    }

    #[Test]
    public function it_replaces_the_image_instead_of_accumulating_files(): void
    {
        $service = $this->serviceWithImage();

        $this->put(
            route('services.update', $service),
            $this->updatePayload($service) + ['image' => UploadedFile::fake()->image('reemplazo.jpg')]
        )->assertRedirect(route('services.index'));

        $service->refresh();
        $media = $service->getMedia('service-image');

        $this->assertCount(1, $media, 'La colección debe conservar una sola imagen después de reemplazarla.');
        $this->assertSame('reemplazo.jpg', $media->first()->file_name);
    }

    #[Test]
    public function the_edit_page_exposes_the_current_image(): void
    {
        $service = $this->serviceWithImage();

        $response = $this->get(route('services.edit', $service));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Service/Edit')
                ->has('service')
                ->has('service.media', 1)
            );
    }

    private function serviceWithImage(): Service
    {
        $service = Service::factory()->create([
            'branch_id' => $this->branch->id,
            'category_id' => $this->category->id,
            'name' => 'Cambio de pantalla',
            'base_price' => 500,
        ]);
        $service->branches()->attach($this->branch->id);
        $service->addMedia(UploadedFile::fake()->image('original.jpg'))
            ->toMediaCollection('service-image');

        return $service;
    }

    private function updatePayload(Service $service): array
    {
        return [
            'name' => $service->name,
            'description' => $service->description,
            'category_id' => $this->category->id,
            'base_price' => $service->base_price,
            'show_online' => false,
            'branch_ids' => [$this->branch->id],
        ];
    }
}
