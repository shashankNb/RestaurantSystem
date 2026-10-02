<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurant;
use Database\Factories\DiningTableFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A table customers can order from (dine in). They choose it in the app, or its QR code
 * chooses it for them; staff bring the order to it.
 */
#[Fillable(['label', 'is_active', 'sort_order'])]
class DiningTable extends Model
{
    /** @use HasFactory<DiningTableFactory> */
    use BelongsToRestaurant, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** Where the table's QR code takes customers: the ordering site, with this table chosen. */
    public function orderingUrl(): string
    {
        return $this->restaurant->webUrl().'/table/'.rawurlencode($this->label);
    }
}
