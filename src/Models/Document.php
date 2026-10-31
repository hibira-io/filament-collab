<?php

namespace Hibira\FilamentCollab\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The shared state of one rich text field of one record.
 *
 * @property int $id
 * @property string $key
 * @property string|null $subject
 * @property int $generation
 * @property bool $seeded
 * @property string|null $source_hash
 * @property int $pending_updates
 */
class Document extends Model
{
    protected $guarded = [];

    protected $casts = [
        'generation' => 'integer',
        'seeded' => 'boolean',
        'pending_updates' => 'integer',
    ];

    public function getConnectionName()
    {
        return config('hibira-collab.connection') ?? parent::getConnectionName();
    }

    public function getTable()
    {
        return config('hibira-collab.tables.documents', 'hibira_collab_documents');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(Update::class);
    }
}
