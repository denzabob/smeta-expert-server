<?php
namespace App\Models\Expert;
use App\Models\Expert\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
class ExpertMessage extends Model
{
    use HasPublicUuid;
    protected $fillable = ['expert_conversation_id', 'role', 'content', 'metadata'];
    protected $casts = ['metadata' => 'array'];
    public function conversation() { return $this->belongsTo(ExpertConversation::class, 'expert_conversation_id'); }
}
