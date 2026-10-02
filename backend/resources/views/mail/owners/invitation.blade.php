<x-mail::message :restaurant="$restaurant">
# {{ $restaurant->name }} is ready to set up

Hi {{ $firstName }},

You’re the owner of {{ $restaurant->name }} in the back office, where you set up your menu, opening hours, delivery areas, tables and payments, and follow your orders.

@if ($newAccount)
Choose a password to sign in. The link works for {{ $expiresInMinutes }} minutes; after that, use “Forgot password?” on the [sign-in page]({{ $signInUrl }}).

<x-mail::button :url="$url">
Choose your password
</x-mail::button>
@else
Sign in with the account you already have.

<x-mail::button :url="$url">
Open the back office
</x-mail::button>
@endif

Start with **Restaurant settings** (your address, and your Stripe keys so customers can pay) and **Opening hours**. Until your Stripe keys are in, customers can see your menu but can’t check out.
</x-mail::message>
