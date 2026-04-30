<?php

use App\Http\Controllers\KnowledgeBaseController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\WasenderWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/wasender/webhook/{session_id}', [WasenderWebhookController::class, 'handle']);

Route::apiResource('products', ProductController::class);
Route::apiResource('knowledge-bases', KnowledgeBaseController::class);