<x-mail::message>
# Thanks, {{ $firstName }}. Order {{ $order->display_number }} is in.

{{ $restaurant->name }} has your order. We’ll let you know when the kitchen accepts it.

**{{ $when }}**

<x-mail::button :url="$trackingUrl">
Track your order
</x-mail::button>

## Tax invoice

**{{ $restaurant->name }}**@if ($restaurant->abn) · ABN {{ $restaurant->abn }}@endif<br>
@if ($restaurant->address)
{{ collect([$restaurant->address['line1'] ?? null, $restaurant->address['line2'] ?? null, trim(($restaurant->address['suburb'] ?? '').' '.($restaurant->address['state'] ?? '').' '.($restaurant->address['postcode'] ?? ''))])->filter()->implode(', ') }}<br>
@endif
Order {{ $order->display_number }} · {{ $placedAt }}

<x-mail::table>
| Item | Qty | Amount |
|:-----|:---:|-------:|
@foreach ($lines as $line)
| {{ $line['name'] }}@if ($line['options'] !== '') ({{ $line['options'] }})@endif | {{ $line['quantity'] }} | {{ $line['total'] }} |
@endforeach
| Subtotal | | {{ $subtotal }} |
@if ($deliveryFee)
| Delivery | | {{ $deliveryFee }} |
@endif
@if ($discount)
| Discount ({{ $order->promo_code }}) | | {{ $discount }} |
@endif
| **Total** | | **{{ $total }}** |
</x-mail::table>

The total includes GST of {{ $gst }}.

@if ($order->notes)
Your note: {{ $order->notes }}
@endif

Thanks,<br>
{{ $restaurant->name }}@if ($restaurant->phone) · {{ $restaurant->phone }}@endif
</x-mail::message>
