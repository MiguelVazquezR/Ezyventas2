<?php

namespace Tests\Feature;

use App\AiTools\EzyVentasToolProvider;
use App\AiTools\Registrars\HelpCenterTools;
use App\Models\Branch;
use App\Models\HelpArticle;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Prism\Prism\Tool;
use Tests\TestCase;

/**
 * The AI agent answers "how do I do this?" questions with a link to the matching
 * written guide of the help center ("Centro de ayuda").
 */
class AiAgentHelpGuideToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // The application ships with the point of sale guides seeded by a
        // migration. These tests build their own fixtures.
        HelpArticle::query()->delete();

        $subscription = Subscription::factory()->create();
        $subscription->update(['onboarding_completed_at' => now()]);

        $branch = Branch::factory()->create(['subscription_id' => $subscription->id]);

        $this->user = User::factory()->create(['branch_id' => $branch->id]);
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

    private function tool(string $name): Tool
    {
        $definition = collect((new HelpCenterTools())->definitions($this->user))
            ->first(fn (array $definition) => $definition['tool']->name() === $name);

        $this->assertNotNull($definition, "The {$name} tool is not registered.");

        return $definition['tool'];
    }

    /**
     * @return array<string, mixed>
     */
    private function search(string $query, ?string $module = null): array
    {
        return json_decode($this->tool('search_help_guides')->handle($query, $module), true);
    }

    #[Test]
    public function search_returns_the_deep_link_of_the_matching_guide(): void
    {
        $guide = $this->guide();

        $result = $this->search('cómo registrar una venta');

        $this->assertSame(route('help-center.index'), $result['help_center_url']);
        $this->assertCount(1, $result['guides']);

        $match = $result['guides'][0];

        $this->assertSame('Cómo registrar una venta', $match['title']);
        $this->assertSame('pos', $match['module']);
        $this->assertSame('Punto de venta', $match['module_label']);
        $this->assertSame(
            route('help-center.show', 'pos') . '#guide-' . $guide->id,
            $match['url']
        );
        $this->assertSame(route('help-center.show', 'pos'), $result['related_modules'][0]['url']);
    }

    #[Test]
    public function search_matches_text_inside_the_guide_body(): void
    {
        $guide = $this->guide([
            'title'   => 'Operación diaria',
            'excerpt' => null,
            'content' => '<h2>Cierre</h2><p>Realiza el corte de caja al final del día.</p>',
        ]);

        $result = $this->search('corte de caja');

        $this->assertCount(1, $result['guides']);
        $this->assertSame(
            route('help-center.show', 'pos') . '#guide-' . $guide->id,
            $result['guides'][0]['url']
        );
        $this->assertStringContainsString('corte de caja', $result['guides'][0]['snippet']);
    }

    #[Test]
    public function search_ignores_unpublished_and_scheduled_guides(): void
    {
        $this->guide(['is_published' => false, 'published_at' => null]);
        $this->guide(['title' => 'Cómo registrar una venta con factura', 'published_at' => now()->addWeek()]);

        $result = $this->search('registrar una venta');

        $this->assertSame([], $result['guides']);
        $this->assertStringContainsString('No encontré', $result['message']);
    }

    #[Test]
    public function search_can_be_scoped_to_a_module_by_key_or_label(): void
    {
        $this->guide();
        $this->guide([
            'module'     => 'billing',
            'title'      => 'Cómo facturar una venta',
            'excerpt'    => 'Emite la factura de una venta registrada.',
            'content'    => '<p>Desde la venta abre la opción de facturar.</p>',
            'sort_order' => 2,
        ]);

        $this->assertCount(2, $this->search('venta')['guides']);

        $byKey = $this->search('venta', 'billing');
        $this->assertCount(1, $byKey['guides']);
        $this->assertSame('billing', $byKey['guides'][0]['module']);

        $byLabel = $this->search('venta', 'Facturación');
        $this->assertCount(1, $byLabel['guides']);
        $this->assertSame('Cómo facturar una venta', $byLabel['guides'][0]['title']);
    }

    #[Test]
    public function search_offers_the_help_center_when_nothing_matches(): void
    {
        $this->guide();

        $result = $this->search('reparar la impresora fiscal');

        $this->assertSame([], $result['guides']);
        $this->assertSame([], $result['related_modules']);
        $this->assertSame(route('help-center.index'), $result['help_center_url']);
        $this->assertStringContainsString('No encontré', $result['message']);
    }

    #[Test]
    public function it_lists_only_the_modules_with_published_guides(): void
    {
        $this->guide();
        $this->guide([
            'module'       => 'billing',
            'title'        => 'Cómo facturar una venta',
            'is_published' => false,
            'published_at' => null,
        ]);

        $result = json_decode($this->tool('list_help_center_modules')->handle(), true);

        $this->assertCount(1, $result['modules']);
        $this->assertSame('pos', $result['modules'][0]['key']);
        $this->assertSame('Punto de venta', $result['modules'][0]['label']);
        $this->assertSame(1, $result['modules'][0]['guides_count']);
        $this->assertSame(route('help-center.show', 'pos'), $result['modules'][0]['url']);
    }

    #[Test]
    public function the_help_center_tools_are_registered_for_every_user(): void
    {
        $provider = new EzyVentasToolProvider();

        $names = collect($provider->tools($this->user))
            ->map(fn (Tool $tool) => $tool->name())
            ->all();

        $this->assertContains('search_help_guides', $names);
        $this->assertContains('list_help_center_modules', $names);
        $this->assertContains('help center', $provider->categories($this->user));
    }
}

