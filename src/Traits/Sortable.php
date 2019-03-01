<?php

namespace Idegart\LaravelSortable\Traits;

use Idegart\LaravelSortable\Scopes\SortOrderScope;
use Eloquent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Validator;

/**
 * Trait Sortable
 * @mixin Eloquent
 * @mixin SoftDeletes
 * @package Artus\LaravelSortable
 */
trait Sortable
{
    protected static function bootSortable()
    {
        static::addGlobalScope(new SortOrderScope);

        static::creating(function (Model $model) {

            /** @var Sortable|Model $model */

            $maxSortOrder = $model::query()
                ->where([
                    $model->getSortModificator() => $model->getAttribute($model->getSortModificator())
                ])
                ->max('sort_order');

            $model->setAttribute(
                'sort_order',
                $maxSortOrder !== null ? $maxSortOrder + 1 : 0
            );
        });


        static::updating(function (Model $model) {

            /** @var Sortable|Model $model */


            if (
                key_exists('sort_order', $model->getDirty())
                && $model->getAttribute('sort_order') !== null
                && $model->getOriginal('sort_order') !== null
            ) {
                $sortIndex = $model->getAttribute('sort_order');
                $currentSort = $model->getOriginal('sort_order');

                self::validateSortIndex($sortIndex, $model);

                $signs = $sortIndex > $currentSort
                    ? ['<=', '>', '-']
                    : ['>=', '<', '+'];

                $model::query()
                    ->where('id', $model->getKey())
                    ->update(['sort_order' => null]);

                $sql = $model::query()
                    ->where($model->getSortModificator(), $model->getAttribute($model->getSortModificator()))
                    ->where('sort_order', $signs[0], $sortIndex)
                    ->where('sort_order', $signs[1], $currentSort)
                    ->whereNotNull('sort_order')
                    ->orderBy('sort_order', $sortIndex > $currentSort ? 'ASC' : 'DESC');

                if ($sortIndex > $currentSort) {
                    $sql->decrement('sort_order');
                } else {
                    $sql->increment('sort_order');
                }
            }

            if (key_exists($model->getSortModificator(), $model->getDirty())) {
                $currentModificatorId = $model->getOriginal($model->getSortModificator());
                $currentSort = $model->getOriginal('sort_order');

                $model::query()
                    ->where('id', $model->getKey())
                    ->update(['sort_order' => null]);

                $model::query()
                    ->whereNotNull('sort_order')
                    ->where($model->getSortModificator(), $currentModificatorId)
                    ->where('sort_order', '>', $currentSort)
                    ->orderBy('sort_order', 'ASC')
                    ->decrement('sort_order');
            }
        });


        static::updated(function (Model $model) {

            /** @var Sortable|Model $model */

            if (key_exists($model->getSortModificator(), $model->getDirty())) {
                $newModificatorId = $model->getAttribute($model->getSortModificator());

                $max = self::query()
                    ->where($model->getSortModificator(), '=', $newModificatorId)
                    ->max('sort_order');

                $model::query()
                    ->where('id', $model->getKey())
                    ->update(['sort_order' => $max === null ? 0 : $max + 1]);
            }
        });


        static::deleted(function (Model $model) {

            /** @var Sortable|Model $model */

            $oldSortOrder = $model->getAttribute('sort_order');

            if (in_array(SoftDeletes::class, class_uses($model))) {
                $model->update(['sort_order' => null]);
            }

            $model::query()
                ->where($model->getSortModificator(), '=', $model->getAttribute($model->getSortModificator()))
                ->where('sort_order', '>', $oldSortOrder)
                ->decrement('sort_order');
        });


        if (in_array(SoftDeletes::class, class_uses(self::class))) {
            static::restoring(
                function (Model $model) {
                    /** @var Sortable|Model $model */

                    $max = self::query()
                    ->where($model->getSortModificator(), '=', $model->getAttribute($model->getSortModificator()))
                    ->max('sort_order');

                    $model->update([
                        'sort_order' => $max !== null ? $max + 1 : 0
                    ]);
                }
            );
        }
    }

    /**
     * @param $sortIndex
     * @param Model|Sortable $model
     * @throws \Illuminate\Validation\ValidationException
     */
    public static function validateSortIndex(int $sortIndex, Model $model)
    {
        Validator::validate(['sort_order' => $sortIndex], [
            'sort_order' => function ($attribute, $value, $fail) use ($model) {

                if ( ! $model->checkCanUpdateSort($value)) {
                    $fail($attribute . ' is invalid');
                }
            }
        ]);
    }

    public function checkCanUpdateSort(int $sortIndex)
    {
        if (!is_integer($sortIndex) || $sortIndex < 0) {
            return false;
        }

        $currentSort = intval($this->getOriginal('sort_order'));
        $sortModificatorId = $this->getAttribute($this->getSortModificator());

        if ($currentSort == $sortIndex) {
            return false;
        }

        $max = self::query()
            ->where($this->getSortModificator(), '=', $sortModificatorId)
            ->max('sort_order') ?? 0;

        return $max == 0 || $sortIndex <= $max;
    }

    public function setSortAttribute($value)
    {
        $this->attributes['sort_order'] = $value;
    }

    public function getSortModificator()
    {
        return defined('SORT_MODIFICATOR')
            ? self::SORT_MODIFICATOR
            : 'parent_id';
    }
}
