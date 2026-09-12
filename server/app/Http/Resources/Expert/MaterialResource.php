<?php
namespace App\Http\Resources\Expert;
use Illuminate\Http\Request; use Illuminate\Http\Resources\Json\JsonResource;
class MaterialResource extends JsonResource { public function toArray(Request $request): array { return ['public_id'=>$this->public_id,'original_name'=>$this->original_name,'mime_type'=>$this->mime_type,'extension'=>$this->extension,'size'=>(int)$this->size,'category'=>$this->category,'status'=>$this->status,'metadata'=>$this->metadata,'created_at'=>$this->created_at?->toIso8601String(),'updated_at'=>$this->updated_at?->toIso8601String()]; } }
