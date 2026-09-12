<?php
namespace App\Http\Resources\Expert;
use Illuminate\Http\Request; use Illuminate\Http\Resources\Json\JsonResource;
class ResearchObjectResource extends JsonResource { public function toArray(Request $request): array { return ['public_id'=>$this->public_id,'name'=>$this->name,'type'=>$this->type,'description'=>$this->description,'sort_order'=>(int)$this->sort_order,'created_at'=>$this->created_at?->toIso8601String(),'updated_at'=>$this->updated_at?->toIso8601String()]; } }
