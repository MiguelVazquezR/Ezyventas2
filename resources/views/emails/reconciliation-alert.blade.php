@component('mail::message')
# Alerta de conciliación de timbres

La reconciliación periódica de una **cuenta normal** detectó una diferencia
entre el saldo esperado localmente y el saldo real reportado por el PAC.

**Detalles:**
- **Cuenta PAC:** #{{ $accountId }}
- **Negocio:** {{ $subscriptionName }}
- **Saldo esperado (local):** {{ $expected }}
- **Saldo real (PAC):** {{ $real }}
- **Diferencia:** {{ $difference > 0 ? '+' : '' }}{{ $difference }}

Revisa la cuenta en el panel de administración para confirmar si hay
asignaciones del revendedor sin confirmar, timbres sin registrar, o un
descuadre que requiera ajuste manual.

@component('mail::button', ['url' => route('admin.pac-accounts.index')])
Revisar cuentas PAC
@endcomponent

Gracias,<br>
El equipo de {{ config('app.name') }}
@endcomponent
