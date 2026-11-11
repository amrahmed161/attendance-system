<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CenterController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Middleware\EnsureManager;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware(['auth:sanctum', EnsureManager::class])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/sync/pull', [SyncController::class, 'pull']);
    Route::post('/sync/push', [SyncController::class, 'push']);

    Route::get('/rooms', [RoomController::class, 'index']);
    Route::post('/rooms', [RoomController::class, 'store']);

    Route::get('/centers', [CenterController::class, 'index']);
    Route::post('/centers', [CenterController::class, 'store']);
    Route::put('/centers/{center}', [CenterController::class, 'update']);
    Route::delete('/centers/{center}', [CenterController::class, 'destroy']);

    Route::get('/students', [StudentController::class, 'index']);
    Route::post('/students', [StudentController::class, 'store']);
    Route::put('/students/{student}', [StudentController::class, 'update']);
    Route::delete('/students/{student}', [StudentController::class, 'destroy']);

    Route::post('/attendance/record', [AttendanceController::class, 'record']);
    Route::get('/attendance/report', [AttendanceController::class, 'report']);
    Route::post('/attendance/scan', [AttendanceController::class, 'scan']);
});
