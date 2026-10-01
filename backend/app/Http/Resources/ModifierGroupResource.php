<?php

namespace App\Http\Resources;

use App\Models\ModifierGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A choice customers make for an item, with its rule in words ("Required · choose 1").
 *
 * @mixin ModifierGroup
 */
class ModifierGroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'min_select' => $this->min_select,
            'max_select' => $this->max_select,
            'selection_rule' => $this->selectionRule(),
            'options' => ModifierOptionResource::collection($this->options),
        ];
    }
}
