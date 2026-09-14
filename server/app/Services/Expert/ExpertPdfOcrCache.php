<?php
declare(strict_types=1);
namespace App\Services\Expert;
use App\Models\Expert\ExpertProjectMaterial;
use App\Services\LLM\DTO\LLMParsedFile;
use Illuminate\Support\Facades\Storage;
final class ExpertPdfOcrCache
{
    private function disk(){return Storage::disk('local');}
    public function cacheDirectory(ExpertProjectMaterial $material): string{return 'expert/'.($material->project?->public_id ?? 'unknown').'/ocr/'.$material->public_id;}
    public function path(ExpertPdfOcrCandidate $candidate): string{return 'expert/'.$candidate->projectPublicId.'/ocr/'.$candidate->materialPublicId.'/'.config('expert.pdf_ocr.cache_version','v1').'-'.$candidate->sha256.'.json';}
    public function get(ExpertPdfOcrCandidate $candidate): ?LLMParsedFile
    {
        $disk=$this->disk();$path=$this->path($candidate);if(!$disk->exists($path))return null;
        $raw=$disk->get($path);$data=json_decode($raw,true);
        if(!is_array($data)||($data['project_public_id']??null)!==$candidate->projectPublicId||($data['material_public_id']??null)!==$candidate->materialPublicId||($data['source_sha256']??null)!==$candidate->sha256||!is_string($data['text']??null)||!is_array($data['images']??null))throw ExpertPdfOcrException::cacheInvalid();
        $images=[];$total=0;
        if(count($data['images']) > (int) config('expert.pdf_ocr.max_annotation_images_per_pdf',8)) throw ExpertPdfOcrException::cacheInvalid();
        foreach($data['images'] as $image){if(!is_array($image)||!in_array($image['mime_type']??null,['image/png','image/jpeg','image/webp'],true)||!is_string($image['bytes_base64']??null))throw ExpertPdfOcrException::cacheInvalid();$bytes=base64_decode($image['bytes_base64'],true);if($bytes===false)throw ExpertPdfOcrException::cacheInvalid();$total+=strlen($bytes);$images[]=new \App\Services\LLM\DTO\LLMParsedFileImage($image['mime_type'],$bytes);}
        if($total>(int)config('expert.pdf_ocr.max_annotation_images_bytes',20971520)||mb_strlen($data['text'],'UTF-8')>(int)config('expert.pdf_ocr.max_annotation_text_chars',500000))throw ExpertPdfOcrException::cacheInvalid();
        return new LLMParsedFile($candidate->sha256,(string)($data['name']??$candidate->name),$data['text'],$images);
    }
    public function put(ExpertPdfOcrCandidate $candidate, LLMParsedFile $parsed): void
    {
        if(strtolower($parsed->sha256)!==strtolower($candidate->sha256)||$parsed->text==='' )throw ExpertPdfOcrException::cacheInvalid();
        $payload=['version'=>config('expert.pdf_ocr.cache_version','v1'),'project_public_id'=>$candidate->projectPublicId,'material_public_id'=>$candidate->materialPublicId,'source_sha256'=>$candidate->sha256,'name'=>$candidate->name,'text'=>$parsed->text,'images'=>array_map(static fn($i)=>['mime_type'=>$i->mimeType,'bytes_base64'=>base64_encode($i->bytes)],$parsed->images)];
        $json=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(strlen($json)>(int)config('expert.pdf_ocr.max_cache_bytes',33554432))throw ExpertPdfOcrException::cacheInvalid();
        $this->disk()->put($this->path($candidate),$json);
    }
}