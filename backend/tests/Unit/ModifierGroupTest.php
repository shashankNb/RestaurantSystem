<?php

use App\Models\ModifierGroup;

it('describes its selection rule the way customers read it', function (int $min, int $max, string $rule) {
    $group = new ModifierGroup(['name' => 'Group', 'min_select' => $min, 'max_select' => $max]);

    expect($group->selectionRule())->toBe($rule)
        ->and($group->isRequired())->toBe($min > 0);
})->with([
    'required, pick one' => [1, 1, 'Required · choose 1'],
    'optional, up to two' => [0, 2, 'Optional · choose up to 2'],
    'required range' => [1, 3, 'Required · choose 1 to 3'],
    'required exact count' => [2, 2, 'Required · choose 2'],
]);
