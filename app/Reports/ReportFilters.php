<?php

namespace App\Reports;

use App\Support\BusinessDay;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ReportFilters
{
    public function __construct(public string $from, public string $to, public ?int $userId, public ?int $departmentId) {}

    public static function fromRequest(Request $request): self
    {
        $values = $request->validate([
            'days' => ['nullable', 'integer', 'in:7,30,90'],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.BusinessDay::today()],
            'user_id' => ['nullable', 'integer', 'exists:users,id'], 'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $to = $values['to'] ?? BusinessDay::today();
        $from = $values['from'] ?? CarbonImmutable::parse($to)->subDays(($values['days'] ?? 7) - 1)->toDateString();
        if ($from > $to || CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 365) {
            throw ValidationException::withMessages(['from' => ['Choose an ordered date range of up to 366 days.']]);
        }

        return new self($from, $to, isset($values['user_id']) ? (int) $values['user_id'] : null, isset($values['department_id']) ? (int) $values['department_id'] : null);
    }

    public function scope($query, string $column = 'user_id'): mixed
    {
        if ($this->userId) {
            $query->where($column, $this->userId);
        }
        if ($this->departmentId) {
            $query->whereIn($column, fn ($q) => $q->select('user_id')->from('department_user')->where('department_id', $this->departmentId));
        }

        return $query;
    }

    public function meta(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'user_id' => $this->userId, 'department_id' => $this->departmentId,
            'timezone' => BusinessDay::timezone(), 'start_utc' => BusinessDay::startUtc($this->from)->toIso8601String(),
            'end_utc' => BusinessDay::endUtc($this->to)->toIso8601String(), 'generated_at' => now()->toIso8601String(),
            'definition_version' => 1, 'membership' => 'current'];
    }
}
