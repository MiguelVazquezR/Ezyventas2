<?php

namespace Tests\Feature\Admin;

use App\Models\Branch;
use App\Models\Subscription;
use App\Models\TutorialVideo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Admin panel "Tutoriales": CRUD of the video tutorials shown per module.
 */
class TutorialsTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        $subscription = Subscription::factory()->create();

        // Skip the onboarding redirect applied to every authenticated request.
        $subscription->update(['onboarding_completed_at' => now()]);

        $branch = Branch::factory()->create(['subscription_id' => $subscription->id]);

        return User::factory()->create(['branch_id' => $branch->id]);
    }

    #[Test]
    public function the_index_lists_the_managed_videos(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('admin.tutorials.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tutorials/Index')
                // Billing placeholders seeded by the data migration.
                ->has('videos', 10)
                ->has('moduleOptions', 17)
            );
    }

    #[Test]
    public function a_video_can_be_created_from_an_external_link(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('admin.tutorials.store'), [
                'module'      => 'pos',
                'section'     => 'Ventas',
                'title'       => 'Cómo cobrar en el punto de venta',
                'description' => 'Flujo completo de cobro',
                'duration'    => '4:10',
                'source_type' => 'link',
                'url'         => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('tutorial_videos', [
            'module'    => 'pos',
            'section'   => 'Ventas',
            'title'     => 'Cómo cobrar en el punto de venta',
            'is_active' => true,
        ]);
    }

    #[Test]
    public function a_video_can_be_uploaded_as_a_file(): void
    {
        Storage::fake('public');

        $this->actingAs($this->superAdmin())
            ->post(route('admin.tutorials.store'), [
                'module'      => 'pos',
                'section'     => 'Ventas',
                'title'       => 'Clip corto de cobro',
                'source_type' => 'file',
                'file'        => UploadedFile::fake()->create('clip.mp4', 500, 'video/mp4'),
            ])
            ->assertRedirect();

        $video = TutorialVideo::query()->where('title', 'Clip corto de cobro')->first();

        $this->assertNotNull($video);
        $this->assertNotNull($video->file_path);
        $this->assertTrue(Storage::disk('public')->exists($video->file_path));
        $this->assertStringContainsString('/storage/', $video->file_url);
    }

    #[Test]
    public function validation_rejects_unknown_modules_and_missing_sources(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('admin.tutorials.store'), [
                'module'      => 'not-a-module',
                'section'     => 'General',
                'title'       => 'Video inválido',
                'source_type' => 'link',
            ])
            ->assertSessionHasErrors(['module', 'url']);
    }

    #[Test]
    public function a_video_can_be_toggled_moved_and_deleted(): void
    {
        $admin = $this->superAdmin();

        $first = TutorialVideo::query()->forModule('billing')->ordered()->first();
        $second = TutorialVideo::query()->forModule('billing')->ordered()->skip(1)->first();

        // Toggle active/inactive.
        $this->actingAs($admin)
            ->post(route('admin.tutorials.toggle-active', $first->id))
            ->assertRedirect();

        $this->assertFalse($first->fresh()->is_active);

        // Move down: swaps order with the next sibling.
        $this->actingAs($admin)
            ->post(route('admin.tutorials.move', $first->id), ['direction' => 'down'])
            ->assertRedirect();

        $this->assertSame(2, $first->fresh()->sort_order);
        $this->assertSame(1, $second->fresh()->sort_order);

        // Delete.
        $this->actingAs($admin)
            ->delete(route('admin.tutorials.destroy', $first->id))
            ->assertRedirect();

        $this->assertDatabaseMissing('tutorial_videos', ['id' => $first->id]);
    }

    #[Test]
    public function the_shared_prop_exposes_only_active_videos_grouped_by_module(): void
    {
        $admin = $this->superAdmin();

        TutorialVideo::query()->update(['is_active' => false]);

        TutorialVideo::query()->create([
            'module'     => 'billing',
            'section'    => 'Facturas',
            'title'      => 'Cómo crear una factura',
            'sort_order' => 1,
            'is_active'  => true,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.tutorials.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('tutorials', 1)
                ->where('tutorials.0.key', 'billing')
                ->where('tutorials.0.label', 'Facturación')
                ->has('tutorials.0.sections', 1)
                ->where('tutorials.0.sections.0.title', 'Facturas')
                ->where('tutorials.0.sections.0.videos.0.title', 'Cómo crear una factura')
            );
    }
}
