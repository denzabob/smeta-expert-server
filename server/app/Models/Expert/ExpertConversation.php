<?php
namespace App\Models\Expert;
use App\Models\Expert\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
class ExpertConversation extends Model
{
    use HasPublicUuid;
    protected $fillable = ['expert_project_id', 'title'];
    public function project() { return $this->belongsTo(ExpertProject::class, 'expert_project_id'); }
    public function messages() { return $this->hasMany(ExpertMessage::class)->orderBy('created_at')->orderBy('id'); }
    public function activeMaterials() { return $this->belongsToMany(ExpertProjectMaterial::class, 'expert_conversation_materials')->withPivot('created_at')->orderByPivot('created_at')->orderBy('expert_project_materials.id'); }
}
