<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RevisionPublication extends Model
{
    use HasUuids;

    protected $table = 'revision_publications';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'project_revision_id',
        'public_id',
        'public_token_hash',
        'is_active',
        'expires_at',
        'access_level',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'expires_at' => 'datetime',
    ];

    public function revision(): BelongsTo
    {
        return $this->belongsTo(ProjectRevision::class, 'project_revision_id');
    }

    public function views(): HasMany
    {
        return $this->hasMany(RevisionPublicationView::class, 'revision_publication_id');
    }
}
