<?php

use App\Http\Controllers\SocialCallbackController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/auth/{provider}/callback', SocialCallbackController::class)
    ->where('provider', 'google|apple|facebook');
