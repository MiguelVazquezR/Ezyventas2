<?php

namespace App\Http\Requests\OnlineStore;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStoreConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('online_store.config.edit');
    }

    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9-]+$/', 'unique:store_configs,slug,' . $this->user()->branch->subscription->storeConfig?->id],
            'is_active' => ['boolean'],
            'store_name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'logo' => ['nullable', 'image', 'max:2048'],
            'primary_color' => ['nullable', 'string', 'max:7'],
            'secondary_color' => ['nullable', 'string', 'max:7'],
            'welcome_message' => ['nullable', 'string', 'max:500'],
            'accepts_pickup' => ['boolean'],
            'accepts_delivery' => ['boolean'],
            'delivery_fee' => ['nullable', 'numeric', 'min:0'],
            'preparation_time_minutes' => ['nullable', 'integer', 'min:1'],
            'delivery_policy' => ['nullable', 'string', 'max:2000'],
            'footer_note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'The slug may only contain lowercase letters, numbers, and hyphens.',
            'slug.unique' => 'This slug is already in use. Please choose another one.',
        ];
    }
}
