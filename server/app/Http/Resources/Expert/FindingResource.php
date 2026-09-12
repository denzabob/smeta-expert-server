<?php
namespace App\Http\Resources\Expert;
use Illuminate\Http\Request; use Illuminate\Http\Resources\Json\JsonResource;
class FindingResource extends JsonResource { public function toArray(Request $request): array { return ['public_id'=>$this->public_id,'type'=>$this->type,'title'=>$this->title,'description'=>$this->description,'value'=>$this->value,'unit'=>$this->unit,'status'=>$this->status,'research_object'=>new ResearchObjectResource($this->whenLoaded('researchObject')),'materials'=>MaterialResource::collection($this->whenLoaded('materials')),'created_at'=>$this->created_at?->toIso8601String(),'updated_at'=>$this->updated_at?->toIso8601String()]; } }
