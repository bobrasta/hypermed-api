<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApprovalLog extends Model
{
    protected $fillable = [
        'subject_type', 'subject_id', 'action', 'actor_id', 'delegation_id', 'notes',
    ];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function delegation()
    {
        return $this->belongsTo(Delegation::class);
    }

    // subject_type stores the model's short class name (not a fully-
    // qualified morph map or a raw FQCN) so the column reads cleanly in a
    // report without joining back through Laravel's morph map.
    public static function record(Model $subject, string $action, User $actor, ?string $notes = null): self
    {
        return static::create([
            'subject_type'  => class_basename($subject),
            'subject_id'    => $subject->getKey(),
            'action'        => $action,
            'actor_id'      => $actor->id,
            'delegation_id' => $actor->activeDelegationAsDelegate()?->id,
            'notes'         => $notes,
        ]);
    }
}
