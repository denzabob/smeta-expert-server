<?php

use App\Http\Controllers\Api\FurnitureModuleController;
use App\Http\Controllers\Api\MaterialController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});
