<?php
namespace App\Models\Expert;
use App\Models\Expert\Concerns\HasPublicUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
class ExpertFinding extends Model
{
    use HasPublicUuid;
    protected $fillable = ['expert_project_id', 'research_object_id', 'type', 'title', 'description', 'value', 'unit', 'status', 'created_by'];
    public function project() { return $this->belongsTo(ExpertProject::class, 'expert_project_id'); }
    public function researchObject() { return $this->belongsTo(ExpertResearchObject::class, 'research_object_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function materials() { return $this->belongsToMany(ExpertProjectMaterial::class, 'expert_finding_material'); }
}
