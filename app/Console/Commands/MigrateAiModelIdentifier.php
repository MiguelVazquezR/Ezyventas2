<?php

namespace App\Console\Commands;

use App\Models\SettingDefinition;
use App\Models\SettingValue;
use Ezyventas\AiAgent\Models\AiConversation;
use Illuminate\Console\Command;

class MigrateAiModelIdentifier extends Command
{
    protected $signature = 'ai-agent:migrate-model {old} {new} {--dry-run}';
    protected $description = 'Migrate a deprecated AI model identifier to a new one across settings and existing conversations';

    public function handle(): int
    {
        $old = $this->argument('old');
        $new = $this->argument('new');
        $dryRun = (bool) $this->option('dry-run');

        $this->info(($dryRun ? '[DRY RUN] ' : '') . "Migrating model identifier: {$old} → {$new}");

        // 1. SettingDefinition default value
        $definition = SettingDefinition::where('key', 'ai.model')->first();
        if ($definition && $definition->default_value === $old) {
            $this->line("SettingDefinition.default_value: {$old} → {$new}");
            if (! $dryRun) {
                $definition->update(['default_value' => $new]);
            }
        } else {
            $this->line('SettingDefinition: no change needed (already up to date or not found)');
        }

        // 2. Per-subscription SettingValue overrides
        $valuesQuery = SettingValue::whereHas('definition', fn ($q) => $q->where('key', 'ai.model'))
            ->where('value', $old);
        $valueCount = $valuesQuery->count();
        $this->line("SettingValue rows to update: {$valueCount}");
        if (! $dryRun && $valueCount > 0) {
            $valuesQuery->update(['value' => $new]);
        }

        // 3. Already-created conversations (the one most likely to be missed)
        $conversationsQuery = AiConversation::where('model', $old);
        $convCount = $conversationsQuery->count();
        $this->line("AiConversation rows to update: {$convCount}");
        if (! $dryRun && $convCount > 0) {
            $conversationsQuery->update(['model' => $new]);
        }

        $this->info($dryRun ? 'Dry run complete — no changes made.' : 'Migration complete.');

        return self::SUCCESS;
    }
}
