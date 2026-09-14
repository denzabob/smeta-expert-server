<?php
declare(strict_types=1);
namespace App\Services\LLM;
use App\Services\LLM\DTO\LLMParsedFile;
use App\Services\LLM\DTO\LLMParsedFileImage;
final class RouterAiFileAnnotationParser
{
    public static function parse(mixed $annotations): array
    {
        $result=[];
        if (!is_array($annotations)) return $result;
        foreach ($annotations as $annotation) {
            if (!is_array($annotation) || ($annotation['type'] ?? null) !== 'file' || !is_array($annotation['file'] ?? null)) continue;
            $file=$annotation['file']; $hash=$file['hash'] ?? null; $name=$file['name'] ?? null; $content=$file['content'] ?? null;
            if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/i',$hash) || !is_string($name) || $name==='' || !is_array($content)) continue;
            $text=''; $images=[]; $imageBytes=0; $valid=true;
            foreach($content as $block){
                if(!is_array($block)){$valid=false;break;}
                if(($block['type']??null)==='text' && is_string($block['text']??null)){ $text.=$block['text']."\n"; continue; }
                if(($block['type']??null)==='image_url' && is_array($block['image_url']??null) && is_string($block['image_url']['url']??null)){
                    $url=$block['image_url']['url'];
                    if(!preg_match('/^data:(image\/(?:png|jpeg|webp));base64,([A-Za-z0-9+\/]*={0,2})$/',$url,$m)){$valid=false;break;}
                    $bytes=base64_decode($m[2],true); if($bytes===false || strlen($bytes)>(int)config('expert.pdf_ocr.max_annotation_image_bytes',5242880)){$valid=false;break;}
                    $imageBytes+=strlen($bytes); if($imageBytes>(int)config('expert.pdf_ocr.max_annotation_images_bytes',20971520) || count($images)>=(int)config('expert.pdf_ocr.max_annotation_images_per_pdf',8)){$valid=false;break;}
                    $images[]=new LLMParsedFileImage($m[1],$bytes); continue;
                }
                $valid=false;break;
            }
            if($valid && ($text!=='' || $images!==[]) && mb_strlen($text,'UTF-8') <= (int)config('expert.pdf_ocr.max_annotation_text_chars',500000)) $result[]=new LLMParsedFile(strtolower($hash),$name,trim($text),$images);
        }
        return $result;
    }
}