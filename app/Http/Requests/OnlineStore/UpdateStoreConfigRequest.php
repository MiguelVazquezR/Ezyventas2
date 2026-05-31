<?php

namespace App\Http\Requests\OnlineStore;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStoreConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('online_store.config.edit');
    }

    protected function prepareForValidation(): void
    {
        // Convert prep time from days/hours/minutes to total minutes
        $prepDays = (int) ($this->prep_days ?? 0);
        $prepHours = (int) ($this->prep_hours ?? 0);
        $prepMinutes = (int) ($this->prep_minutes ?? 0);
        $totalMinutes = ($prepDays * 1440) + ($prepHours * 60) + $prepMinutes;

        $this->merge([
            'primary_color' => $this->ensureHash($this->primary_color),
            'secondary_color' => $this->ensureHash($this->secondary_color),
            'preparation_time_minutes' => $totalMinutes > 0 ? $totalMinutes : ($this->preparation_time_minutes ?? 30),
        ]);
    }

    private function ensureHash(?string $value): ?string
    {
        if ($value === null || $value === '' || str_starts_with($value, '#')) {
            return $value;
        }

        return '#' . $value;
    }

    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9-]+$/', 'unique:store_configs,slug,' . $this->user()->branch->subscription->storeConfig?->id],
            'is_active' => ['boolean'],
            'store_name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'logo' => ['nullable', 'image', 'max:2048'],
            'remove_logo' => ['boolean'],
            'primary_color' => ['nullable', 'string', 'max:7'],
            'secondary_color' => ['nullable', 'string', 'max:7'],
            'welcome_message' => ['nullable', 'string', 'max:500'],
            'accepts_pickup' => ['boolean'],
            'accepts_delivery' => ['boolean'],
            'allow_out_of_stock_purchases' => ['boolean'],
            'out_of_stock_extra_minutes' => ['nullable', 'integer', 'min:0', 'required_if:allow_out_of_stock_purchases,true'],
            'whatsapp_number' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9]{10,15}$/'],
            'tagline' => ['nullable', 'string', 'max:120'],
            'theme_mode' => ['nullable', 'string', 'in:light,dark'],
            'banners' => ['nullable', 'array', 'max:3'],
            'banners.*' => ['image', 'max:4096'],
            'remove_banners' => ['boolean'],
            'prep_days' => ['nullable', 'integer', 'min:0', 'max:30'],
            'prep_hours' => ['nullable', 'integer', 'min:0', 'max:23'],
            'prep_minutes' => ['nullable', 'integer', 'min:0', 'max:59'],
            'delivery_fee' => ['nullable', 'numeric', 'min:0'],
            'free_shipping_minimum' => ['nullable', 'numeric', 'min:0'],
            'preparation_time_minutes' => ['nullable', 'integer', 'min:1'],
            'delivery_policy' => ['nullable', 'string', 'max:2000'],
            'terms_policy' => ['nullable', 'string', 'max:10000'],
            'footer_note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'El slug solo puede contener letras minúsculas, números y guiones.',
            'slug.unique' => 'Este slug ya está en uso. Elige otro.',
            'store_name.required' => 'El nombre de la tienda es obligatorio.',
        ];
    }
}
