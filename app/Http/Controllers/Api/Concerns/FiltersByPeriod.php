<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Shared period + page-size handling for dated list endpoints. The apps
 * default every list to "this year" by sending date_from/date_to (Y-m-d,
 * inclusive); leaving both out means all time. per_page lets a client load
 * a whole period in one request instead of silently seeing only page 1.
 */
trait FiltersByPeriod
{
    protected function applyPeriod(Builder $query, Request $request, string $column): Builder
    {
        if ($request->filled('date_from')) {
            $query->whereDate($column, '>=', $request->date('date_from')->toDateString());
        }
        if ($request->filled('date_to')) {
            $query->whereDate($column, '<=', $request->date('date_to')->toDateString());
        }

        return $query;
    }

    protected function perPage(Request $request, int $default): int
    {
        return max(1, min($request->integer('per_page', $default), 2000));
    }
}
