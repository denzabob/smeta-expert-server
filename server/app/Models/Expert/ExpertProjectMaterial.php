<?php
namespace App\Models\Expert;
use App\Models\Expert\Concerns\HasPublicUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
class ExpertProjectMaterial extends Model
{
    use HasPublicUuid;
    protected $fillable = ['expert_project_id', 'uploaded_by', 'original_name', 'storage_disk', 'storage_path', 'mime_type', 'extension', 'size', 'category', 'status', 'metadata'];
    protected $hidden = ['id', 'expert_project_id', 'uploaded_by', 'storage_disk', 'storage_path'];
    protected $casts = ['size' => 'integer', 'metadata' => 'array'];
    public function project() { return $this->belongsTo(ExpertProject::class, 'expert_project_id'); }
    public function uploader() { return $this->belongsTo(User::class, 'uploaded_by'); }
    public function findings() { return $this->belongsToMany(ExpertFinding::class, 'expert_finding_material'); }
    public function identity() { return $this->hasOne(ExpertMaterialIdentity::class, 'expert_project_material_id'); }
    public function storageMigration() { return $this->hasOne(ExpertStorageMigration::class, 'material_id'); }

    public function storageDisk(): string
    {
        $disk = trim((string) $this->storage_disk);

        return $disk !== '' ? $disk : 'local';
    }

    public function storageKey(): string
    {
        return (string) $this->storage_path;
    }
}
