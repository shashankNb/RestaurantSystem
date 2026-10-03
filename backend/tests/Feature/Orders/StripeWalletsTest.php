<?php

use App\Models\Restaurant;
use App\Payments\StripePaymentGateway;
use Tests\Support\FakeStripeHttpClient;

afterEach(fn () => FakeStripeHttpClient::reset());

/** The account's payment method settings, as Stripe lists them. */
function stripeSettings(bool $applePay, bool $googlePay): array
{
    $method = fn (bool $on): array => ['available' => $on, 'display_preference' => ['overridable' => null, 'preference' => $on ? 'on' : 'off', 'value' => $on ? 'on' : 'off']];

    return ['object' => 'list', 'url' => '/v1/payment_method_configurations', 'has_more' => false, 'data' => [
        ['id' => 'pmc_child', 'object' => 'payment_method_configuration', 'active' => true, 'is_default' => false, 'apple_pay' => $method(false), 'google_pay' => $method(false)],
        ['id' => 'pmc_default', 'object' => 'payment_method_configuration', 'active' => true, 'is_default' => true, 'apple_pay' => $method($applePay), 'google_pay' => $method($googlePay)],
    ]];
}

/** A payment method domain, as Stripe gives it; a message makes Apple Pay inactive on it. */
function stripeDomain(?string $applePayProblem = null, bool $enabled = true): array
{
    return [
        'id' => 'pmd_123',
        'object' => 'payment_method_domain',
        'domain_name' => 'order.momohouse.com.au',
        'enabled' => $enabled,
        'livemode' => false,
        'apple_pay' => $applePayProblem === null ? ['status' => 'active'] : ['status' => 'inactive', 'status_details' => ['error_message' => $applePayProblem]],
        'google_pay' => ['status' => 'active'],
        'link' => ['status' => 'active'],
        'paypal' => ['status' => 'active'],
    ];
}

function stripeDomains(array ...$domains): array
{
    return ['object' => 'list', 'url' => '/v1/payment_method_domains', 'has_more' => false, 'data' => $domains];
}

it('reads Apple Pay and Google Pay from the account’s own settings, and registers the website', function () {
    $stripe = FakeStripeHttpClient::install([
        'GET /v1/payment_method_configurations' => stripeSettings(applePay: true, googlePay: false),
        'GET /v1/payment_method_domains' => stripeDomains(),
        'POST /v1/payment_method_domains' => stripeDomain(),
    ]);

    $setup = (new StripePaymentGateway)->prepareWallets(Restaurant::factory()->create(), 'order.momohouse.com.au');

    expect($setup->applePay)->toBeTrue()
        ->and($setup->googlePay)->toBeFalse()
        ->and($setup->domain)->toBe('order.momohouse.com.au')
        ->and($setup->domainReady)->toBeTrue()
        ->and($stripe->requested())->toBe(['GET /v1/payment_method_configurations', 'GET /v1/payment_method_domains', 'POST /v1/payment_method_domains'])
        ->and($stripe->requests[1]['params'])->toMatchArray(['domain_name' => 'order.momohouse.com.au'])
        ->and($stripe->requests[2]['params'])->toBe(['domain_name' => 'order.momohouse.com.au']);
});

it('leaves a registered domain that’s ready alone', function () {
    $stripe = FakeStripeHttpClient::install([
        'GET /v1/payment_method_configurations' => stripeSettings(applePay: true, googlePay: true),
        'GET /v1/payment_method_domains' => stripeDomains(stripeDomain()),
    ]);

    $setup = (new StripePaymentGateway)->prepareWallets(Restaurant::factory()->create(), 'order.momohouse.com.au');

    expect($setup->ready())->toBeTrue()
        ->and($stripe->requested())->toBe(['GET /v1/payment_method_configurations', 'GET /v1/payment_method_domains']);
});

it('asks Stripe to check a domain again when it isn’t ready, and passes on why not', function () {
    $stripe = FakeStripeHttpClient::install([
        'GET /v1/payment_method_configurations' => stripeSettings(applePay: true, googlePay: true),
        'GET /v1/payment_method_domains' => stripeDomains(stripeDomain('The domain couldn’t be reached.')),
        'POST /v1/payment_method_domains/pmd_123/validate' => stripeDomain('The domain couldn’t be reached.'),
    ]);

    $setup = (new StripePaymentGateway)->prepareWallets(Restaurant::factory()->create(), 'order.momohouse.com.au');

    expect($setup->domainReady)->toBeFalse()
        ->and($setup->domainProblem)->toBe('The domain couldn’t be reached.')
        ->and($stripe->requested())->toContain('POST /v1/payment_method_domains/pmd_123/validate');
});

it('switches a disabled domain back on', function () {
    $stripe = FakeStripeHttpClient::install([
        'GET /v1/payment_method_configurations' => stripeSettings(applePay: true, googlePay: true),
        'GET /v1/payment_method_domains' => stripeDomains(stripeDomain(enabled: false)),
        'POST /v1/payment_method_domains/pmd_123' => stripeDomain(),
    ]);

    $setup = (new StripePaymentGateway)->prepareWallets(Restaurant::factory()->create(), 'order.momohouse.com.au');

    // Form-encoded on its way to Stripe, true is sent as "true".
    expect($setup->domainReady)->toBeTrue()
        ->and($stripe->requests[2])->toBe(['request' => 'POST /v1/payment_method_domains/pmd_123', 'params' => ['enabled' => 'true']]);
});

it('only reads the settings when there’s no public website to register', function () {
    $stripe = FakeStripeHttpClient::install([
        'GET /v1/payment_method_configurations' => stripeSettings(applePay: true, googlePay: false),
    ]);

    $setup = (new StripePaymentGateway)->prepareWallets(Restaurant::factory()->create(), null);

    expect($setup->domain)->toBeNull()
        ->and($setup->domainReady)->toBeFalse()
        ->and($stripe->requested())->toBe(['GET /v1/payment_method_configurations']);
});

it('turns on Apple Pay and Google Pay in the account’s own settings only', function () {
    $stripe = FakeStripeHttpClient::install([
        'GET /v1/payment_method_configurations' => stripeSettings(applePay: true, googlePay: false),
        'POST /v1/payment_method_configurations/pmc_default' => stripeSettings(applePay: true, googlePay: true)['data'][1],
    ]);

    (new StripePaymentGateway)->turnOnWallets(Restaurant::factory()->create());

    expect($stripe->requests[1])->toBe(['request' => 'POST /v1/payment_method_configurations/pmc_default', 'params' => [
        'apple_pay' => ['display_preference' => ['preference' => 'on']],
        'google_pay' => ['display_preference' => ['preference' => 'on']],
    ]]);
});
