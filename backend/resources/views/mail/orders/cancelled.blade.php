<x-mail::message>
# Order {{ $order->display_number }} is cancelled

{{ $reason }}

We’ve refunded {{ $total }} to the card you paid with. Refunds usually take 5 to 10 business days to show on your statement.

<x-mail::button :url="$trackingUrl">
See your order
</x-mail::button>

Sorry about that,<br>
{{ $restaurant->name }}@if ($restaurant->phone) · {{ $restaurant->phone }}@endif
</x-mail::message>
