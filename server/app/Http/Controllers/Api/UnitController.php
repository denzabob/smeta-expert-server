<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use Illuminate\Http\Request;

class UnitController extends Controller
{
    public function index()
    {
        $userId = auth()->id();

        $units = Unit::where('user_id', $userId)
            ->orWhere(function ($query) {
                $query->whereNull('user_id')
                    ->whereIn('origin', ['system', 'parser']);
            })
            ->orderBy('name')
            ->pluck('name')
            ->values();

        return response()->json($units);
    }
}
