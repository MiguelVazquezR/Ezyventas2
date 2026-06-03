<x-mail::message>
# Nuevo pedido recibido

**Tienda:** {{ $storeName }}

---

## Pedido {{ $orderNumber }}

**Cliente:** {{ $customerName }}  
**Teléfono:** {{ $customerPhone }}  
**Tipo de entrega:** {{ $deliveryType }}  
**Método de pago:** {{ $paymentMethod }}

---

### Productos

| Producto | Cant. | Precio |
|----------|-------|--------|
@foreach ($items as $item)
| {{ $item['name'] }} | {{ $item['quantity'] }} | ${{ $item['price'] }} |
@endforeach

---

**Total:** ${{ $total }} MXN

<x-mail::button :url="route('online-store.orders.index')">
Ver pedidos
</x-mail::button>

Gracias,<br>
{{ config('app.name') }}
</x-mail::message>
