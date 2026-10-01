<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddressRequest;
use App\Http\Resources\CustomerAddressResource;
use App\Models\CustomerAddress;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The signed-in customer's saved delivery addresses.
 */
class AddressController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return CustomerAddressResource::collection($user->addresses()->orderBy('label')->orderBy('id')->get());
    }

    public function store(AddressRequest $request): CustomerAddressResource
    {
        /** @var User $user */
        $user = $request->user();

        return new CustomerAddressResource($user->addresses()->create($request->validated()));
    }

    public function update(AddressRequest $request, CustomerAddress $address): CustomerAddressResource
    {
        Gate::authorize('update', $address);

        $address->update($request->validated());

        return new CustomerAddressResource($address);
    }

    public function destroy(CustomerAddress $address): Response
    {
        Gate::authorize('delete', $address);

        $address->delete();

        return response()->noContent();
    }
}
