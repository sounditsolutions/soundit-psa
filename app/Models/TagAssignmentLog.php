<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only row recording one tag link change, written only by
 * App\Services\TagService.
 *
 * The model hooks below refuse update and delete through the model. They do not
 * see query-builder writes (TagAssignmentLog::query()->update()/delete(),
 * DB::table()) or TRUNCATE: on sqlite nothing blocks those, and on MariaDB/MySQL
 * the migration's triggers block UPDATE and DELETE statements but not TRUNCATE.
 */
class TagAssignmentLog extends Model
{
    public const UPDATED_AT = null;

    public const ACTIONS = ['attached', 'detached'];

    protected $fillable = [
        'tag_id',
        'tag_name',
        'entity_type',
        'entity_id',
        'client_id',
        'action',
        'source',
        'changed_by',
    ];

    protected function casts(): array
    {
        return [
            'tag_id' => 'integer',
            'entity_id' => 'integer',
            'client_id' => 'integer',
            'changed_by' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $log): void {
            throw new LogicException('tag_assignment_logs is append-only');
        });

        static::deleting(function (self $log): void {
            throw new LogicException('tag_assignment_logs is append-only');
        });
    }
}
