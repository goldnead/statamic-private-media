<?php

namespace Goldnead\PrivateMedia\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One row of the audit trail: who asked for which file, and what came of it.
 *
 * @property int $id
 * @property string|null $user_id
 * @property string $resource
 * @property string|null $path
 * @property bool $allowed
 * @property string $reason
 * @property string|null $delivery
 * @property string|null $range
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon|null $created_at
 */
class AccessLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'allowed' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return (string) config('private-media.audit.table', 'private_media_access_log');
    }
}
