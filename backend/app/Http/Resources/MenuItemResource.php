<?php

namespace App\Http\Resources;

use App\Enums\Allergen;
use App\Enums\DietaryTag;
use App\Models\MenuItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A menu item as customers see it. Sold-out items stay on the menu with
 * `is_available: false`.
 *
 * @mixin MenuItem
 */
class MenuItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'price_cents' => $this->price_cents,
            'image_url' => $this->image_url,
            'is_available' => $this->is_available,
            'dietary_tags' => self::labelled($this->dietary_tags ?? [], DietaryTag::class),
            'allergens' => self::labelled($this->allergens ?? [], Allergen::class),
            'modifier_groups' => ModifierGroupResource::collection($this->modifierGroups),
        ];
    }

    /**
     * Stored enum values as {value, label} pairs, skipping any the enum no longer knows.
     *
     * @param  array<mixed>  $values
     * @param  class-string<DietaryTag>|class-string<Allergen>  $enum
     * @return list<array{value: string, label: string}>
     */
    private static function labelled(array $values, string $enum): array
    {
        $labelled = [];

        foreach ($values as $value) {
            $case = is_string($value) ? $enum::tryFrom($value) : null;

            if ($case !== null) {
                $labelled[] = ['value' => $case->value, 'label' => $case->getLabel()];
            }
        }

        return $labelled;
    }
}
