<?php

namespace App\Actions\Admin\Subscriptions;

use App\Models\SettingDefinition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class UpdateEntitySettingsAction
{
    /**
     * Actualiza las configuraciones de una entidad (Subscription, Branch o User).
     *
     * @param Model  $entity   La entidad dueña de las configuraciones (Subscription, Branch, User)
     * @param array  $settings Clave-valor con los settings a guardar: ['setting_key' => 'value', ...]
     */
    public function execute(Model $entity, array $settings): void
    {
        $definitions = SettingDefinition::whereIn('key', array_keys($settings))->get()->keyBy('key');

        foreach ($settings as $key => $value) {
            $definition = $definitions->get($key);
            if (! $definition) {
                continue;
            }

            // Si es archivo y viene como string (URL existente), mantener el valor
            if ($definition->type === 'file' && is_string($value) && ! request()->hasFile("settings.{$key}")) {
                continue;
            }

            // Si es archivo y se subió uno nuevo
            if ($definition->type === 'file' && request()->hasFile("settings.{$key}")) {
                $file = request()->file("settings.{$key}");
                $path = $file->store('settings', 'public');
                $value = Storage::url($path);
            }

            $entity->settings()->updateOrCreate(
                ['setting_definition_id' => $definition->id],
                ['value' => is_array($value) ? json_encode($value) : $value],
            );
        }
    }
}
