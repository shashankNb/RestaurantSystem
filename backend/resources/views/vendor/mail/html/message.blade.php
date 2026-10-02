@props(['restaurant' => null])
{{-- Laravel's layout, with the restaurant's name and website instead of the platform's (APP_NAME): each restaurant's emails carry its own name. --}}
<x-mail::layout :title="$restaurant?->name">
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="$restaurant?->webUrl() ?? config('app.url')">
{{ $restaurant?->name ?? config('app.name') }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ $restaurant?->name ?? config('app.name') }}. {{ __('All rights reserved.') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
