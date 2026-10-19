<?php

namespace App\Http\Resources;

use App\Models\NestTender;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** @mixin NestTender */
class NestTenderResource extends JsonResource
{
    public static function eat(?Carbon $at): ?string
    {
        return $at?->copy()->setTimezone(config('nest.timezone'))->toIso8601String();
    }

    public static function closingInDays(?Carbon $at): ?int
    {
        if (! $at) {
            return null;
        }
        $tz = config('nest.timezone');

        return (int) now($tz)->startOfDay()->diffInDays($at->copy()->setTimezone($tz)->startOfDay(), false);
    }

    public function toArray(Request $request): array
    {
        $days = self::closingInDays($this->closing_at);
        $open = $this->closing_at?->isFuture() ?? false;
        $detail = $request->route('nestTender') !== null;

        return [
            'id' => $this->id,
            'entity_uuid' => $this->entity_uuid,
            'entity_number' => $this->entity_number,
            'reference_number' => $this->reference_number,
            'entity_type' => $this->entity_type,
            'entity_status' => $this->entity_status,
            'description' => $this->description,
            'sub_category_acronym' => $this->sub_category_acronym,
            'sub_category_name' => $this->sub_category_name,
            'procurement_category' => $this->procurement_category,
            'procurement_category_name' => $this->procurement_category_name,
            'procuring_entity_name' => $this->procuring_entity_name,
            'procuring_entity_uuid' => $this->procuring_entity_uuid,
            'financial_year' => $this->financial_year,
            'lot_count' => $this->lot_count,
            'has_addendum' => $this->has_addendum,
            'invitation_at' => self::eat($this->invitation_at),
            'closing_at' => self::eat($this->closing_at),
            'closing_in_days' => $days,
            'is_open' => $open,
            'is_closing_soon' => $open && $days !== null && $days <= (int) config('nest.closing_soon_days'),
            'relevance_score' => $this->relevance_score,
            'is_relevant' => $this->is_relevant,
            'relevance' => $this->is_relevant ? 'relevant' : ($this->relevance_score >= (int) config('nest.maybe_min') ? 'maybe' : 'other'),
            'tags' => $this->tags ?? [],
            'triage_status' => $this->triage_status,
            'pipeline_status' => $this->pipelineStatus(),
            'assigned_to' => $this->assignee ? ['id' => $this->assignee->id, 'name' => $this->assignee->name] : null,
            'tender' => $this->tender ? [
                'id' => $this->tender->id, 'status' => $this->tender->status, 'tender_number' => $this->tender->tender_number,
            ] : null,
            'first_seen_at' => self::eat($this->first_seen_at),
            'last_seen_at' => self::eat($this->last_seen_at),
            'nest_url' => $this->nestUrl(),
            $this->mergeWhen($detail, fn () => [
                'eligible_types' => $this->eligible_types ? array_values(array_filter(array_map('trim', explode(',', $this->eligible_types)))) : [],
                'matched_keywords' => $this->matched_keywords ?? [],
                'notes' => $this->notes,
                'changes' => $this->changes->map(fn ($c) => [
                    'field' => $c->field,
                    'old_value' => $c->field === 'closing_at' && $c->old_value ? self::eat(Carbon::parse($c->old_value, 'UTC')) : $c->old_value,
                    'new_value' => $c->field === 'closing_at' && $c->new_value ? self::eat(Carbon::parse($c->new_value, 'UTC')) : $c->new_value,
                    'detected_at' => self::eat($c->detected_at),
                ])->values(),
            ]),
        ];
    }
}
