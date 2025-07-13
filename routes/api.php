<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\OptimationController;
use App\Http\Controllers\RegisterController;
use Illuminate\Support\Facades\Route;

Route::post('/v1/email/verify/{id}/{hash}', [RegisterController::class, 'verify'])->name('verification.verify');
Route::get('/v1/email/verify', [RegisterController::class, 'verificationNotice'])->name('verification.notice');
Route::post('/v1/email/resend', [RegisterController::class, 'resendEmailVerification'])->name('verification.send')->middleware(['auth:sanctum','throttle:6,1']);

Route::post('/v1/register', [RegisterController::class, 'register'])->name('api.register');
Route::post('/v1/login', [LoginController::class, 'login']);

Route::middleware(['auth:sanctum', 'verified'])->group(function () {
    Route::post('/v1/logout', [LoginController::class, 'logout']);

    Route::get('/v1/courses/new', [HomeController::class, 'getNewestCourses'])
        ->name('home.courses.byNewest');
    Route::get('/v1/courses/popular', [HomeController::class, 'getPopularCourses'])
        ->name('home.courses.byPopular');
    Route::get('/v1/courses/filter/{format?}', [HomeController::class, 'getFilteredCourses'])
        ->name('home.courses.byFiltered');
    Route::get('/v1/courses/{categoryId}', [HomeController::class, 'getCoursesByCategory'])
        ->name('home.courses.byCategory');

    Route::get('/v1/courses/contents/{courseId}', [CourseController::class, 'getCourseContents'])
        ->name('course.contents');
    Route::post('/v1/courses/finished', [CourseController::class, 'finishedCourse'])
        ->name('course.finished');
});

Route::put('/optimation', [OptimationController::class, 'optimation']);
