<?php

use App\Http\Controllers\AipediaWebchatController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->name('dashboard');

Route::prefix('aipedia/webchat')->name('aipedia.webchat.')->group(function () {
    Route::get('/', [AipediaWebchatController::class, 'index'])->name('index');
    Route::get('/conversations', [AipediaWebchatController::class, 'listConversations'])->name('conversations.index');
    Route::post('/threads', [AipediaWebchatController::class, 'createThread'])->name('threads.create');
    Route::get('/threads/{threadId}', [AipediaWebchatController::class, 'getThread'])->name('threads.get');
    Route::patch('/threads/{threadId}', [AipediaWebchatController::class, 'rename'])->name('threads.rename');
    Route::post('/threads/{threadId}/turns', [AipediaWebchatController::class, 'startTurn'])->name('threads.turns');
    Route::get('/threads/{threadId}/events', [AipediaWebchatController::class, 'events'])->name('threads.events');
    Route::post('/threads/{threadId}/interrupt', [AipediaWebchatController::class, 'interrupt'])->name('threads.interrupt');
});
