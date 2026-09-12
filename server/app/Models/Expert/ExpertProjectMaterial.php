<?php
namespace App\Models\Expert;
use App\Models\Expert\Concerns\HasPublicUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
class ExpertProjectMaterial extends Model
{
    use HasPublicUuid;
    protected $fillable = ['expert_project_id', 'uploaded_by', 'original_name', 'storage_path', 'mime_type', 'extension', 'size', 'category', 'status', 'metadata'];
    protected $hidden = ['id', 'expert_project_id', 'uploaded_by', 'storage_path'];
    protected $casts = ['size' => 'integer', 'metadata' => 'array'];
    public function project() { return $this->belongsTo(ExpertProject::class, 'expert_project_id'); }
    public function uploader() { return $this->belongsTo(User::class, 'uploaded_by'); }
    public function findings() { return $this->belongsToMany(ExpertFinding::class, 'expert_finding_material'); }
}
