<?php

use App\Models\Score;

it('orders the board by points, highest first', function () {
    Score::factory()->worth(300)->create();
    $best = Score::factory()->worth(800)->create();
    Score::factory()->worth(500)->create();

    expect(Score::query()->best()->pluck('points')->all())->toBe([800, 500, 300])
        ->and(Score::query()->best()->first()->is($best))->toBeTrue();
});

it('gives a tied place to whoever got there first', function () {
    $first = Score::factory()->worth(800)->create();
    $second = Score::factory()->worth(800)->create();

    expect(Score::query()->best()->pluck('id')->all())->toBe([$first->id, $second->id])
        ->and($first->rank())->toBe(1)
        ->and($second->rank())->toBe(2);
});

it('ranks a round behind every round that beat it', function () {
    Score::factory()->count(3)->worth(800)->create();
    $score = Score::factory()->worth(70)->create();

    expect($score->rank())->toBe(4);
});

it('ranks the only round on the board first', function () {
    expect(Score::factory()->worth(0)->create()->rank())->toBe(1);
});

it('knows which rounds were played on the ukulele', function () {
    expect(Score::factory()->ukulele()->create()->playedWithUkulele())->toBeTrue()
        ->and(Score::factory()->create(['input_mode' => 'tap'])->playedWithUkulele())->toBeFalse();
});
