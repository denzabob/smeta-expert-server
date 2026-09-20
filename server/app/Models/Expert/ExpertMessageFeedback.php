<?php

declare(strict_types=1);

namespace App\Models\Expert;

use App\Models\Expert\Concerns\HasPublicUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final class ExpertMessageFeedback extends Model
{
    use HasPublicUuid;

    protected $table = 'expert_message_feedback';

    protected $fillable = [
        'message_id', 'user_id', 'rating', 'reason_code', 'comment',
        'provider', 'model', 'requested_mode', 'resolved_mode', 'run_id',
    ];

    public function message()
    {
        return $this->belongsTo(ExpertMessage::class, 'message_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
