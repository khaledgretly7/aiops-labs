<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ApiController;

Route::get('/normal',   [ApiController::class, 'normal']);
Route::get('/slow',     [ApiController::class, 'slow']);
Route::get('/error',    [ApiController::class, 'error']);
Route::get('/random',   [ApiController::class, 'random']);
Route::get('/db',       [ApiController::class, 'db']);
Route::post('/validate',[ApiController::class, 'validateInput']);
Route::get('/metrics', [ApiController::class, 'metrics']);