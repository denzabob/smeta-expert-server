<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\DB;

/** Keep a business mutation and its storage reference events atomic. */
trait AccountsStorageReferences
{
    public function save(array $options = [])
    {
        return DB::transaction(fn () => parent::save($options));
    }

    public function delete()
    {
        return DB::transaction(fn () => parent::delete());
    }
}
