<?php

namespace Hypermed\Messaging\Support;

use Hypermed\Messaging\Contracts\UserDirectory;
use Illuminate\Database\Eloquent\Builder;

class EloquentUserDirectory implements UserDirectory
{
    public function search(?string $term, int $limit, ?int $excludeUserId = null): array
    {
        $model = MessagingConfig::userInstance();
        $nameCol = MessagingConfig::nameColumn();
        $avatarCol = MessagingConfig::avatarColumn();
        $columns = array_values(array_filter([$model->getKeyName(), $nameCol, $avatarCol]));

        $query = $model->newQuery()->select($columns)->orderBy($nameCol)->limit($limit);
        $this->scope($query);

        if ($excludeUserId !== null) {
            $query->where($model->getKeyName(), '<>', $excludeUserId);
        }

        $term = trim((string) $term);
        if ($term !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($term)).'%';
            $grammar = $query->getQuery()->getGrammar();
            // LOWER() on both sides: LIKE is case-sensitive on Postgres, not on MySQL.
            $query->where(function ($q) use ($like, $grammar) {
                foreach ((array) config('messaging.user_search_columns', ['name']) as $col) {
                    $q->orWhereRaw('lower('.$grammar->wrap($col).') like ?', [$like]);
                }
            });
        }

        return $query->get()->map(fn ($u) => UserSummary::make($u))->all();
    }

    /** Hosts extend this class to hide users from search (e.g. inactive accounts). */
    protected function scope(Builder $query): void {}
}
