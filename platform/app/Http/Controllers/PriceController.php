<?php

namespace App\Http\Controllers;

use App\Ai\PriceBook;
use App\Http\Responses\Api;
use App\Models\PriceEntry;
use App\Models\ProviderConnection;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Admins can read the price list; only a Super Admin can add a price (route guard). Prices are never edited, only superseded. */
class PriceController extends Controller
{
    private function present(PriceEntry $p): array
    {
        return ['id' => $p->id, 'kind' => $p->kind, 'model' => $p->model, 'input_per_million' => (string) $p->input_per_million, 'output_per_million' => (string) $p->output_per_million, 'currency' => $p->currency, 'effective_from' => $p->effective_from->toIso8601String(), 'in_force' => $p->effective_from->isPast()];
    }

    public function index(): JsonResponse
    {
        return Api::ok(PriceEntry::orderBy('kind')->orderBy('model')->orderByDesc('effective_from')->orderByDesc('id')->get()->map(fn ($p) => $this->present($p))->values());
    }

    public function store(Request $request, PriceBook $prices): JsonResponse
    {
        $d = $request->validate([
            'kind' => ['required', Rule::in(ProviderConnection::KINDS)],
            'model' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._:\\/-]+$/'],
            'input_per_million' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'output_per_million' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'effective_from' => ['nullable', 'date'],
        ]);
        $entry = $prices->add($d['kind'], $d['model'], number_format((float) $d['input_per_million'], 4, '.', ''), number_format((float) $d['output_per_million'], 4, '.', ''), $d['currency'], CarbonImmutable::parse($d['effective_from'] ?? now()), $request->user());
        Audit::log('price.added', $request->user(), null, ['kind' => $d['kind'], 'model' => $d['model'], 'currency' => strtoupper($d['currency'])]);

        return Api::ok($this->present($entry), status: 201);
    }
}
