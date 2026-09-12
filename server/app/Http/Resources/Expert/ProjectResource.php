<?php
namespace App\Http\Resources\Expert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array { return ['public_id'=>$this->public_id,'name'=>$this->name,'domain'=>$this->domain,'work_type'=>$this->work_type,'customer'=>$this->customer,'object_summary'=>$this->object_summary,'address'=>$this->address,'research_date'=>$this->research_date?->toDateString(),'research_questions'=>$this->research_questions ?? [],'status'=>$this->status,'counts'=>$this->when(isset($this->research_objects_count),fn()=>['research_objects'=>(int)$this->research_objects_count,'conversations'=>(int)$this->conversations_count,'materials'=>(int)$this->materials_count,'findings'=>(int)$this->findings_count]),'research_objects'=>ResearchObjectResource::collection($this->whenLoaded('researchObjects')),'conversations'=>ConversationResource::collection($this->whenLoaded('conversations')),'materials'=>MaterialResource::collection($this->whenLoaded('materials')),'findings'=>FindingResource::collection($this->whenLoaded('findings')),'created_at'=>$this->created_at?->toIso8601String(),'updated_at'=>$this->updated_at?->toIso8601String()]; }
}
