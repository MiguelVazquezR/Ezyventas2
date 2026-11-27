<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\HelpArticle;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Shipped documentation of the point of sale: the help center guides that are
 * seeded by migration and mirrored in database/sql/help_center_pos.sql.
 */
class PosHelpArticlesSeedTest extends TestCase
{
    use RefreshDatabase;

    private const TITLES = [
        'Cómo abrir y cerrar la caja del punto de venta',
        'Cómo registrar entradas y salidas de efectivo',
        'Cómo registrar una venta en el punto de venta',
        'Cómo cobrar con varios métodos de pago',
        'Cómo aplicar descuentos y cambiar precios en una venta',
        'Cómo usar la búsqueda inteligente en el punto de venta',
        'Cómo vender con precios de mayoreo y variantes',
        'Cómo trabajar con productos agotados',
        'Cómo guardar y reanudar carritos en espera',
        'Cómo crear un pedido para entrega desde el punto de venta',
        'Cómo registrar un apartado o una venta a crédito',
        'Cómo atender los pedidos de tienda en línea',
    ];

    #[Test]
    public function the_pos_guides_are_seeded_as_published_articles(): void
    {
        $articles = HelpArticle::query()
            ->forModule('pos')
            ->ordered()
            ->get();

        $this->assertCount(12, $articles);
        $this->assertSame(self::TITLES, $articles->pluck('title')->all());
        $this->assertSame(range(10, 120, 10), $articles->pluck('sort_order')->all());

        foreach ($articles as $article) {
            $this->assertTrue($article->is_published);
            $this->assertNotNull($article->published_at);
            $this->assertNotEmpty($article->excerpt);
            $this->assertLessThanOrEqual(500, mb_strlen($article->excerpt));
            $this->assertStringContainsString('<p>', $article->content);
            $this->assertStringContainsString('<h3>', $article->content);
            $this->assertStringNotContainsString('<script', $article->content);
            $this->assertStringNotContainsString('style=', $article->content);
        }
    }

    #[Test]
    public function the_pos_module_page_lists_the_seeded_guides(): void
    {
        $subscription = Subscription::factory()->create();
        $subscription->update(['onboarding_completed_at' => now()]);
        $branch = Branch::factory()->create(['subscription_id' => $subscription->id]);
        $user = User::factory()->create(['branch_id' => $branch->id]);

        $this->actingAs($user)
            ->get(route('help-center.show', 'pos'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('HelpCenter/Show')
                ->where('module.key', 'pos')
                ->has('module.articles', 12)
                ->where('module.articles.0.title', self::TITLES[0])
                ->where('module.articles.11.title', self::TITLES[11])
            );
    }

    #[Test]
    public function the_seed_migration_can_run_twice_without_duplicating_guides(): void
    {
        $migration = require database_path('migrations/2026_10_18_000001_seed_pos_help_articles.php');

        $migration->up();
        $this->assertSame(12, HelpArticle::query()->where('module', 'pos')->count());

        $migration->down();
        $this->assertSame(0, HelpArticle::query()->where('module', 'pos')->count());

        $migration->up();
        $this->assertSame(12, HelpArticle::query()->where('module', 'pos')->count());
    }
}
