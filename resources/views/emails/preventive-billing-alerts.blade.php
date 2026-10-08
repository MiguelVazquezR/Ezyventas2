@component('mail::message')
# Avisos de facturación

Detectamos estos puntos que pueden afectar tu facturación en **{{ $subscriptionName }}**:

@foreach ($issues as $issue)
@if ($issue['type'] === 'low_balance')
- **Saldo bajo de timbres** — RFC {{ $issue['rfc'] }}: quedan **{{ $issue['balance'] }}** timbres disponibles (umbral: {{ $issue['threshold'] }}). Compra timbres para no interrumpir tu facturación.
@elseif ($issue['type'] === 'csd_expiring')
- **Certificado CSD por vencer** — RFC {{ $issue['rfc'] }}: vence en **{{ $issue['days_left'] }} días** ({{ $issue['valid_to'] }}). Súbelo actualizado antes de esa fecha.
@else
- **Certificado CSD vencido** — RFC {{ $issue['rfc'] }}: venció el {{ $issue['valid_to'] }}. El emisor no podrá timbrar hasta que subas un CSD vigente.
@endif
@endforeach

@component('mail::button', ['url' => route('billing.settings.index')])
Ir a configuración fiscal
@endcomponent

Gracias,<br>
El equipo de {{ config('app.name') }}
@endcomponent
