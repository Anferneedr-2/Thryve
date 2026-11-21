<?php

namespace App\Ai;

use App\Models\PriceEntry;
use App\Models\User;
use Carbon\CarbonInterface;

/** The price list. Entries are only ever added, so a past request keeps the price it was charged at. */
class PriceBook
{
    public function lookup(string $model, ?CarbonInterface $at = null): ?PriceEntry
    {
        return PriceEntry::where('model', $model)->where('effective_from', '<=', ($at ?? now())->utc())
            ->orderByDesc('effective_from')->orderByDesc('id')->first();
    }

    /** @return array{cost: string, currency: string, snapshot: array}|null null when no price is known */
    public function cost(string $model, int $input, int $output, ?CarbonInterface $at = null): ?array
    {
        $p = $this->lookup($model, $at);
        if (! $p) {
            return null;
        }
        $sum = bcadd(bcmul((string) $input, (string) $p->input_per_million, 10), bcmul((string) $output, (string) $p->output_per_million, 10), 10);

        return [
            'cost' => bcdiv($sum, '1000000', 6),
            'currency' => $p->currency,
            'snapshot' => ['price_id' => $p->id, 'input_per_million' => (string) $p->input_per_million, 'output_per_million' => (string) $p->output_per_million, 'currency' => $p->currency, 'effective_from' => $p->effective_from->toIso8601String()],
        ];
    }

    public function add(string $model, string $in, string $out, string $currency, CarbonInterface $effectiveFrom, ?User $by): PriceEntry
    {
        return PriceEntry::create(['kind' => 'agent', 'model' => $model, 'input_per_million' => $in, 'output_per_million' => $out, 'currency' => strtoupper($currency), 'effective_from' => $effectiveFrom->utc(), 'created_by' => $by?->id]);
    }
}
