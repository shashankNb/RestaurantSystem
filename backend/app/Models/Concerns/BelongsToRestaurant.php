<?php

namespace App\Models\Concerns;

use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For models whose rows belong to one restaurant (the tenant). Queries for these
 * models always go through a restaurant relationship or the forRestaurant() scope.
 */
trait BelongsToRestaurant
{
    /**
     * @return BelongsTo<Restaurant, $this>
     */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function forRestaurant(Builder $query, Restaurant|int $restaurant): void
    {
        $query->where(
            $query->qualifyColumn('restaurant_id'),
            $restaurant instanceof Restaurant ? $restaurant->getKey() : $restaurant,
        );
    }
}
