<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait BelongsToStore
{
    protected static function bootBelongsToStore(): void
    {
        static::addGlobalScope('store', function (Builder $builder) {
            $user = auth()->user();

            if ($user && $user->store_id) {
                $builder->where(
                    $builder->getModel()->getTable().'.store_id',
                    $user->store_id
                );
            }
        });

        static::creating(function ($model) {
            if (empty($model->store_id) && auth()->check()) {
                $model->store_id = auth()->user()->store_id;
            }
        });
    }
}
