<?php
namespace App\Http\Resources\Expert;
use Illuminate\Http\Request; use Illuminate\Http\Resources\Json\JsonResource;
class ConversationResource extends JsonResource { public function toArray(Request $request): array { return ['public_id'=>$this->public_id,'title'=>$this->title,'messages_count'=>$this->when(isset($this->messages_count),(int)$this->messages_count),'created_at'=>$this->created_at?->toIso8601String(),'updated_at'=>$this->updated_at?->toIso8601String()]; } }
