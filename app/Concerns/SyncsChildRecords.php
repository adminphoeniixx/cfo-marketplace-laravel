<?php

namespace App\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

trait SyncsChildRecords
{
    /**
     * Update the child the payload points at, or create a fresh one.
     *
     * Passing the id straight to updateOrCreate() would mass-assign it into
     * the insert — a null id then reaches the database and trips the not-null
     * constraint on the primary key. An id the parent does not own is treated
     * as absent, so a payload cannot reach across and rewrite, or collide
     * with, a row belonging to someone else.
     *
     * @template TChild of Model
     * @template TParent of Model
     *
     * @param  HasMany<TChild, TParent>  $relation
     * @param  array<string, mixed>  $attributes
     * @return TChild
     */
    protected function syncChild(HasMany $relation, mixed $id, array $attributes): Model
    {
        // The relation's query already carries the foreign-key constraint;
        // cloning it keeps the lookup from leaking into the caller's relation.
        $record = $id === null || $id === ''
            ? null
            : (clone $relation->getQuery())->whereKey($id)->first();

        if ($record) {
            $record->update($attributes);

            return $record;
        }

        return $relation->create($attributes);
    }
}
