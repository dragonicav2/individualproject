<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'app');
Route::view('/login', 'app');
Route::view('/admin/login', 'app');
Route::view('/admin/password', 'app');
Route::view('/admin/users', 'app');
Route::view('/admin/settings', 'app');
Route::view('/admin/game-artwork', 'app');
Route::view('/register', 'app');
Route::view('/forgot-password', 'app');
Route::view('/reset-password/{token}', 'app')->name('password.reset');
Route::view('/tournaments', 'app');
Route::view('/tournaments/create', 'app');
Route::view('/demo-data', 'app');
