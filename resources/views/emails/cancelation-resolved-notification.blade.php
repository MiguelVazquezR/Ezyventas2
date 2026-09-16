@component('mail::message')
# {{ $result === 'canceled' ? 'Cancelación aceptada' : ($result === 'rejected' ? 'Cancelación rechazada' : 'Solicitud de cancelación vencida') }}

@if ($result === 'canceled')
Tu cliente aceptó la solicitud de cancelación. La factura quedó **cancelada** ante el SAT.
@elseif ($result === 'rejected')
Tu cliente **rechazó** la solicitud de cancelación. La factura sigue **vigente** para efectos fiscales.
@else
El plazo para que tu cliente respondiera venció sin resolución. La factura sigue **vigente**; puedes intentar cancelarla de nuevo desde el sistema.
@endif

**Detalles de la factura:**
- **Folio:** {{ $folio }}
- **UUID:** {{ $uuid }}
- **Receptor:** {{ $receiverName }} ({{ $receiverRfc }})
- **Total:** ${{ number_format((float) $total, 2) }} MXN

@component('mail::button', ['url' => route('billing.invoices.index')])
Ver facturas
@endcomponent

Gracias,<br>
El equipo de {{ config('app.name') }}
@endcomponent
