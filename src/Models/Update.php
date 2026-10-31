<?php

namespace Hibira\FilamentCollab\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stored Yjs update (base64), or a snapshot that replaced earlier ones.
 *
 * @property int $id
 * @property int $document_id
 * @property int $generation
 * @property string $update
 * @property string|null $author
 */
class Update extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'generation' => 'integer',
    ];

    public function getConnectionName()
    {
        return config('hibira-collab.connection') ?? parent::getConnectionName();
    }

    public function getTable()
    {
        return config('hibira-collab.tables.updates', 'hibira_collab_updates');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
