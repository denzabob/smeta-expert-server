<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IdeaAttachment extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'idea_id',
        'file_path',
        'mime_type',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function idea()
    {
        return $this->belongsTo(Idea::class);
    }
}
