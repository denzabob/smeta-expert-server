<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IdeaVote extends Model
{
    use HasFactory;

    public const TYPE_UP = 'up';
    public const TYPE_DOWN = 'down';

    public const TYPES = [
        self::TYPE_UP,
        self::TYPE_DOWN,
    ];

    public $timestamps = false;

    protected $fillable = [
        'idea_id',
        'user_id',
        'vote_type',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function idea()
    {
        return $this->belongsTo(Idea::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
