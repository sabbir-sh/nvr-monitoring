<?php

use App\Http\Controllers\NvrDeviceController;
use Illuminate\Support\Facades\Route;

Route::get('/', [NvrDeviceController::class, 'index'])->name('dashboard');
Route::get('/dashboard/data', [NvrDeviceController::class, 'dashboardData'])->name('dashboard.data');
Route::get('/devices/import-template', [NvrDeviceController::class, 'importTemplate'])->name('devices.import-template');
Route::post('/devices/import', [NvrDeviceController::class, 'import'])->name('devices.import');
Route::post('/devices/check-all', [NvrDeviceController::class, 'checkAll'])->name('devices.check-all');
Route::post('/devices', [NvrDeviceController::class, 'store'])->name('devices.store');
Route::put('/devices/{device}', [NvrDeviceController::class, 'update'])->name('devices.update');
Route::delete('/devices/{device}', [NvrDeviceController::class, 'destroy'])->name('devices.destroy');
Route::post('/devices/{device}/check', [NvrDeviceController::class, 'check'])->name('devices.check');
