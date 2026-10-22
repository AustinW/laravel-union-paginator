<?php

namespace AustinW\UnionPaginator\Tests\TestClasses\Models;

use Illuminate\Database\Eloquent\Model;

// A model with a non-`id` key and no timestamp columns.
class TagModel extends Model
{
    const CREATED_AT = null;

    const UPDATED_AT = null;

    protected $primaryKey = 'tag_id';

    protected $guarded = [];
}
