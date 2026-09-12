<?php
namespace App\Services\Expert;
use App\Models\Expert\ExpertFinding;
use App\Models\Expert\ExpertProject;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
class ExpertFindingService
{
    public function create(ExpertProject $project, int $userId, array $data): ExpertFinding
    {
        return DB::transaction(function () use ($project,$userId,$data) { [$attrs,$materialIds]=$this->resolve($project,$data); $finding=$project->findings()->create($attrs+['created_by'=>$userId,'status'=>$attrs['status']??'expert_confirmed']); $finding->materials()->sync($materialIds); return $finding->load(['researchObject','materials']); });
    }
    public function update(ExpertFinding $finding, array $data): ExpertFinding
    {
        return DB::transaction(function () use ($finding,$data) { [$attrs,$materialIds,$sync]=$this->resolve($finding->project,$data,true); $finding->update($attrs); if($sync)$finding->materials()->sync($materialIds); return $finding->load(['researchObject','materials']); });
    }
    private function resolve(ExpertProject $project,array $data,bool $updating=false): array
    {
        $hasMaterials=Arr::exists($data,'material_public_ids'); $hasObject=Arr::exists($data,'research_object_public_id'); $publicMaterials=Arr::pull($data,'material_public_ids',[]); $objectPublic=Arr::pull($data,'research_object_public_id',null);
        if ($objectPublic !== null) { $object=$project->researchObjects()->where('public_id',$objectPublic)->first(); if(!$object) throw ValidationException::withMessages(['research_object_public_id'=>'The selected research object is invalid.']); $data['research_object_id']=$object->id; }
        elseif ($hasObject) $data['research_object_id']=null;
        $materials=$project->materials()->whereIn('public_id',$publicMaterials)->get();
        if(count($publicMaterials)!==$materials->count()) throw ValidationException::withMessages(['material_public_ids'=>'One or more selected materials are invalid.']);
        return [$data,$materials->pluck('id')->all(),$hasMaterials];
    }
}
