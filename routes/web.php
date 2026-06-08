<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::view('/docs', 'swagger')->name('docs.swagger');

Route::get('/docs/openapi.json', function () {
    $openapi = json_decode(file_get_contents(public_path('openapi.json')), true, flags: JSON_THROW_ON_ERROR);

    return response()->json($openapi);
})->name('docs.openapi');

Route::get('/login', function () {
    return response()->json([
        'message' => 'Unauthenticated.'
    ], 401);
})->name('login');

