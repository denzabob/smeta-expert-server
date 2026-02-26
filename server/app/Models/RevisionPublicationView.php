<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RevisionPublicationView extends Model
{
    use HasUuids;

    protected $table = 'revision_publication_views';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'revision_publication_id',
        'ip',
        'user_agent',
        'viewed_at',
    ];

    protected $casts = [
        'viewed_at' => 'datetime',
    ];

    public function publication(): BelongsTo
    {
        return $this->belongsTo(RevisionPublication::class, 'revision_publication_id');
    }
}
