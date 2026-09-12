<?php

namespace App\Models\Expert;

use App\Models\Expert\Concerns\HasPublicUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ExpertProject extends Model
{
    use HasPublicUuid;

    protected $fillable = ['user_id', 'name', 'domain', 'work_type', 'customer', 'object_summary', 'address', 'research_date', 'research_questions', 'status'];
    protected $casts = ['research_date' => 'date', 'research_questions' => 'array'];

    public function user() { return $this->belongsTo(User::class); }
    public function researchObjects() { return $this->hasMany(ExpertResearchObject::class)->orderBy('sort_order'); }
    public function conversations() { return $this->hasMany(ExpertConversation::class); }
    public function materials() { return $this->hasMany(ExpertProjectMaterial::class); }
    public function findings() { return $this->hasMany(ExpertFinding::class); }
}
