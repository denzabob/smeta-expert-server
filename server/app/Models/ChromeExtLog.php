<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChromeExtLog extends Model
{
    protected $table = 'chrome_ext_logs';

    protected $fillable = [
        'user_id',
        'url',
        'domain',
        'action',
        'status',
        'extracted_fields',
        'errors',
        'template_id',
        'material_id',
    ];

    protected $casts = [
        'extracted_fields' => 'array',
        'errors' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function template()
    {
        return $this->belongsTo(ParserSupplierCollectProfile::class, 'template_id');
    }

    public function material()
    {
        return $this->belongsTo(Material::class);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForDomain($query, string $domain)
    {
        return $query->where('domain', $domain);
    }
}
