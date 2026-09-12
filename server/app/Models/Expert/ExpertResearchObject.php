<?php
namespace App\Models\Expert;
use App\Models\Expert\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
class ExpertResearchObject extends Model
{
    use HasPublicUuid;
    protected $fillable = ['expert_project_id', 'name', 'type', 'description', 'sort_order'];
    protected $casts = ['sort_order' => 'integer'];
    public function project() { return $this->belongsTo(ExpertProject::class, 'expert_project_id'); }
    public function findings() { return $this->hasMany(ExpertFinding::class, 'research_object_id'); }
}
