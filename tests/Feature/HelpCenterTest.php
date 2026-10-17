<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\HelpArticle;
use App\Models\Subscription;
use App\Models\TutorialVideo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Help center ("Centro de ayuda"): subscriber pages and the admin panel that
 * manages the written guides of every module.
 */
class HelpCenterTest extends TestCase
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

    private function guide(array $attributes = []): HelpArticle
    {
        return HelpArticle::query()->create(array_merge([
            'module'       => 'pos',
            'title'        => 'Cómo registrar una venta',
            'excerpt'      => 'Paso a paso para cobrar en el punto de venta.',
            'content'      => '<p>Abre el punto de venta y agrega productos.</p>',
            'sort_order'   => 1,
            'is_published' => true,
            'published_at' => now(),
        ], $attributes));
    }

    #[Test]
    public function the_index_lists_only_modules_with_published_content(): void
    {
        TutorialVideo::query()->update(['is_active' => false]);

        $this->guide();
        $this->guide(['module' => 'products', 'title' => 'Borrador', 'is_published' => false, 'published_at' => null]);

        $this->actingAs($this->superAdmin())
            ->get(route('help-center.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('HelpCenter/Index')
                ->has('modules', 1)
                ->where('modules.0.key', 'pos')
                ->where('modules.0.articles_count', 1)
            );
    }

    #[Test]
    public function a_module_page_shows_its_guides_and_videos(): void
    {
        $this->guide();

        TutorialVideo::query()->create([
            'module'     => 'pos',
            'section'    => 'Ventas',
            'title'      => 'Cómo cobrar en el punto de venta',
            'sort_order' => 1,
            'is_active'  => true,
        ]);

        $this->actingAs($this->superAdmin())
            ->get(route('help-center.show', 'pos'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('HelpCenter/Show')
                ->where('module.key', 'pos')
                ->has('module.articles', 1)
                ->where('module.articles.0.title', 'Cómo registrar una venta')
                ->where('module.videos_count', 1)
                ->where('module.videos.0.title', 'Ventas')
                ->where('module.videos.0.videos.0.title', 'Cómo cobrar en el punto de venta')
            );
    }

    #[Test]
    public function an_unknown_module_returns_not_found(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('help-center.show', 'not-a-module'))
            ->assertNotFound();
    }

    #[Test]
    public function drafts_are_hidden_from_subscribers(): void
    {
        $this->guide(['is_published' => false, 'published_at' => null]);

        $this->actingAs($this->superAdmin())
            ->get(route('help-center.show', 'pos'))
            ->assertInertia(fn (Assert $page) => $page->has('module.articles', 0));
    }

    #[Test]
    public function an_admin_can_create_a_guide(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('admin.help-articles.store'), [
                'module'       => 'pos',
                'title'        => 'Cómo registrar una venta',
                'excerpt'      => '',
                'content'      => '<p>Abre el punto de venta y agrega productos.</p>',
                'is_published' => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('help_center_articles', [
            'module'       => 'pos',
            'title'        => 'Cómo registrar una venta',
            'excerpt'      => null,
            'is_published' => true,
        ]);
    }

    #[Test]
    public function validation_rejects_an_unknown_module(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('admin.help-articles.store'), [
                'module'  => 'not-a-module',
                'title'   => 'Guía inválida',
                'content' => '<p>Contenido</p>',
            ])
            ->assertSessionHasErrors(['module']);
    }

    #[Test]
    public function guides_can_be_updated_toggled_moved_and_deleted(): void
    {
        $admin = $this->superAdmin();

        $first = $this->guide(['title' => 'Primera guía', 'sort_order' => 1]);
        $second = $this->guide(['title' => 'Segunda guía', 'sort_order' => 2]);

        // Update.
        $this->actingAs($admin)
            ->put(route('admin.help-articles.update', $first->id), [
                'module'       => 'pos',
                'title'        => 'Primera guía editada',
                'content'      => '<p>Contenido actualizado.</p>',
                'is_published' => true,
            ])
            ->assertRedirect();

        $this->assertSame('Primera guía editada', $first->fresh()->title);

        // Toggle publication.
        $this->actingAs($admin)
            ->post(route('admin.help-articles.toggle-publish', $first->id))
            ->assertRedirect();

        $this->assertFalse($first->fresh()->is_published);
        $this->assertNull($first->fresh()->published_at);

        // Move up: swaps order with the previous sibling.
        $this->actingAs($admin)
            ->post(route('admin.help-articles.move', $second->id), ['direction' => 'up'])
            ->assertRedirect();

        $this->assertSame(1, $second->fresh()->sort_order);
        $this->assertSame(2, $first->fresh()->sort_order);

        // Delete.
        $this->actingAs($admin)
            ->delete(route('admin.help-articles.destroy', $first->id))
            ->assertRedirect();

        $this->assertDatabaseMissing('help_center_articles', ['id' => $first->id]);
    }

    #[Test]
    public function the_admin_index_lists_the_guides(): void
    {
        $this->guide();

        $this->actingAs($this->superAdmin())
            ->get(route('admin.help-articles.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/HelpCenter/Index')
                ->has('articles', 1)
                ->has('moduleOptions', 17)
            );
    }
}
