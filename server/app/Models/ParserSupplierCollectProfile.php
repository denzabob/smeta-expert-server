<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ParserSupplierCollectProfile extends Model
{
    protected $fillable = [
        'supplier_name',
        'name',
        'config_override',
        'is_default',
        'user_id',
        'url_patterns',
        'selectors',
        'extraction_rules',
        'validation_rules',
        'test_case',
        'source',
        'version',
    ];

    protected $casts = [
        'config_override' => 'array',
        'is_default' => 'boolean',
        'url_patterns' => 'array',
        'selectors' => 'array',
        'extraction_rules' => 'array',
        'validation_rules' => 'array',
        'test_case' => 'array',
        'version' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope: system-wide profiles (no user).
     */
    public function scopeSystem($query)
    {
        return $query->whereNull('user_id');
    }

    /**
     * Scope: profiles for a specific user (+ system fallback).
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where(function ($q) use ($userId) {
            $q->where('user_id', $userId)
              ->orWhereNull('user_id');
        });
    }

    /**
     * Scope: chrome extension templates only.
     */
    public function scopeChromeExt($query)
    {
        return $query->where('source', 'chrome_ext');
    }

    /**
     * Scope: templates for a specific domain.
     */
    public function scopeForDomain($query, string $domain)
    {
        return $query->where('supplier_name', $domain);
    }
}
