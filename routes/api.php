<?php

use App\Http\Controllers\Api\ApiBibMetadataController;
use App\Http\Controllers\Api\ApiBibParserController;
use App\Http\Controllers\Api\ApiBibValidationController;
use App\Http\Controllers\Api\ApiSourceController;
use Illuminate\Support\Facades\Route;

/**
 * ----------------НАДО РЕАЛИЗОВАТЬ----------------
 * - Просто парсер, который выдает разбитые записи в json
 * Просто метод который отпаршенные данные проверяет на соответствие ГОСТ
 * Метод который парсит данные, проверяет только на соответствие ГОСТ
 * Метод который парсит данные, проверяет только на соответствие нормам кафедры
 * Метод который парсит данные, проверяет соответствие ГОСТ и кафедры
 * Метод который отпаршенные данные, проверяет соответствие ГОСТ и кафедры
 */

/**
 * Парсинг текста/файла bib и txt
 */
Route::post('/bib/parse', [ApiBibParserController::class, 'parse']);
Route::post('/bib/check-gost', [ApiBibValidationController::class, 'checkGost']);
Route::post('/bib/check-full', [ApiBibValidationController::class, 'checkFull'])->middleware('throttle:10,1');
Route::post('/bib/check-department', [ApiBibValidationController::class, 'checkDepartment']);
Route::post('/bib/check-external', [ApiBibValidationController::class, 'checkExternal'])->middleware('throttle:10,1');

Route::get('/bib/types', [ApiBibMetadataController::class, 'types']);
Route::get('/bib/types/{type}/fields', [ApiBibMetadataController::class, 'fields']);
Route::get('/requirements', [ApiBibMetadataController::class, 'requirements']);

Route::post('/sources/verify', [ApiSourceController::class, 'verify'])->middleware('throttle:20,1');

// use Inertia\Inertia;
// use Laravel\Fortify\Features;

// use App\Http\Controllers\BibFileController;
// use App\Http\Controllers\CheckHistoryController;
// use App\Http\Controllers\Admin\BibtexController as AdminBibTexController;

// use App\Http\Controllers\Admin\BibtexController;
// use App\Http\Controllers\Admin\BibtexFieldController;

// use App\Http\Controllers\Admin\DepartmentController;
// use App\Http\Controllers\ProfileController;
// use App\Support\BibEditorViewData;

// use function Pest\Laravel\post;

// require __DIR__.'/settings.php';
