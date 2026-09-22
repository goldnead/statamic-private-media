<?php

namespace Goldnead\PrivateMedia\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A host application's Eloquent user (App\Models\User on adriangoldner.com).
 */
class Viewer extends Authenticatable
{
    protected $table = 'viewers';

    protected $guarded = [];
}
