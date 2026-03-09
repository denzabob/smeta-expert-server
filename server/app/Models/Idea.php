<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Idea extends Model
{
    use HasFactory;

    public const STATUS_NEW = 'NEW';
    public const STATUS_PLANNED = 'PLANNED';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_IMPLEMENTED = 'IMPLEMENTED';

    public const STATUSES = [
        self::STATUS_NEW,
        self::STATUS_PLANNED,
        self::STATUS_REJECTED,
        self::STATUS_IMPLEMENTED,
    ];

    protected $fillable = [
        'title',
        'description',
        'user_id',
        'status',
        'votes_up',
        'votes_down',
        'views',
    ];

    protected $casts = [
        'votes_up' => 'integer',
        'votes_down' => 'integer',
        'views' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function votes()
    {
        return $this->hasMany(IdeaVote::class);
    }

    public function comments()
    {
        return $this->hasMany(IdeaComment::class);
    }

    public function attachments()
    {
        return $this->hasMany(IdeaAttachment::class);
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'idea_tags');
    }
}
