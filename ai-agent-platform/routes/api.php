<?php

use App\Http\Controllers\Api\Admin\TenantController;
use App\Http\Controllers\Api\AgentBehaviorRuleController;
use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\BusinessController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DeliveryController;
use App\Http\Controllers\Api\PaymentMethodController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PostAiSettingController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ScheduledPostController;
use App\Http\Controllers\Api\SocialAccountController;
use App\Http\Controllers\Api\FalImageWebhookController;
use App\Http\Controllers\Api\SocialApiWebhookController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\TelegramSettingsController;
use App\Http\Controllers\Api\TelegramWebhookController;
use App\Http\Controllers\Api\AiCampaignController;
use App\Http\Controllers\Api\AgentChatController;
use App\Http\Controllers\Api\AgentChatsController;
use App\Http\Controllers\Api\AgentImageJobController;
use App\Http\Controllers\Api\CustomerAiSettingController;
use App\Http\Controllers\Api\McpController;
use App\Http\Controllers\Api\McpTokenController;
use App\Http\Controllers\Api\ProfileInterviewController;
use App\Http\Controllers\Api\QueueHealthController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\WorkflowController;
use App\Http\Controllers\Api\Internal\InternalAiController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/socialapi', SocialApiWebhookController::class);
Route::post('/webhooks/fal/image', FalImageWebhookController::class);
Route::post('/telegram/webhook/{secret}', TelegramWebhookController::class)->middleware('throttle:120,1');
Route::post('/mcp', McpController::class)->middleware('throttle:mcp');
Route::get('/health/queue', QueueHealthController::class)->middleware('throttle:30,1');

Route::middleware('internal_ai')->prefix('internal/ai')->group(function () {
    Route::post('/tools/invoke', [InternalAiController::class, 'invokeTool']);
    Route::post('/pending-actions', [InternalAiController::class, 'createPendingAction']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', MeController::class);

    Route::middleware('super_admin')->prefix('admin')->group(function () {
        Route::get('/businesses', [TenantController::class, 'index']);
        Route::patch('/businesses/{id}', [TenantController::class, 'update']);
        Route::post('/businesses/{id}/impersonate', [TenantController::class, 'impersonate']);
        Route::post('/stop-impersonation', [TenantController::class, 'stopImpersonation']);
        Route::post('/wallet/topup', [WalletController::class, 'topup']);
    });

    Route::middleware('shop')->group(function () {
        Route::get('/wallet', [WalletController::class, 'show']);
        Route::get('/wallet/ledger', [WalletController::class, 'ledger']);

        Route::get('/business', [BusinessController::class, 'show'])->middleware('permission:settings.view');
        Route::put('/business', [BusinessController::class, 'update'])->middleware('permission:settings.manage');
        Route::get('/customer-ai-settings', [CustomerAiSettingController::class, 'show'])->middleware('permission:settings.manage');
        Route::put('/customer-ai-settings', [CustomerAiSettingController::class, 'update'])->middleware('permission:settings.manage');
        Route::get('/mcp-tokens', [McpTokenController::class, 'index'])->middleware('permission:settings.manage');
        Route::post('/mcp-tokens', [McpTokenController::class, 'store'])->middleware('permission:settings.manage');
        Route::delete('/mcp-tokens/{id}', [McpTokenController::class, 'destroy'])->middleware('permission:settings.manage');
        Route::get('/telegram', [TelegramSettingsController::class, 'show'])->middleware('permission:settings.manage');
        Route::post('/telegram/link', [TelegramSettingsController::class, 'link'])->middleware('permission:settings.manage');
        Route::put('/telegram', [TelegramSettingsController::class, 'update'])->middleware('permission:settings.manage');
        Route::delete('/telegram', [TelegramSettingsController::class, 'destroy'])->middleware('permission:settings.manage');

        Route::get('/agent', [AgentController::class, 'show'])->middleware('permission:agents.view');
        Route::put('/agent', [AgentController::class, 'update'])->middleware('permission:agents.manage');
        Route::get('/agent/behavior-rules', [AgentBehaviorRuleController::class, 'index'])->middleware('permission:agents.view');
        Route::post('/agent/behavior-rules', [AgentBehaviorRuleController::class, 'store'])->middleware('permission:agents.manage');
        Route::put('/agent/behavior-rules/{id}', [AgentBehaviorRuleController::class, 'update'])->middleware('permission:agents.manage');
        Route::delete('/agent/behavior-rules/{id}', [AgentBehaviorRuleController::class, 'destroy'])->middleware('permission:agents.manage');
        Route::get('/agent/image-models', [AgentController::class, 'imageModels'])->middleware('permission:agents.view');
        Route::patch('/agent/image-model', [AgentController::class, 'updateImageModel'])->middleware('permission:agents.manage');
        Route::get('/agent/llm-models', [AgentController::class, 'llmModels'])->middleware('permission:agents.view');
        Route::patch('/agent/llm-model', [AgentController::class, 'updateLlmModel'])->middleware('permission:agents.manage');
        Route::post('/agent/assets', [AgentController::class, 'uploadAsset'])->middleware('permission:agents.manage');
        Route::delete('/agent/assets/{id}', [AgentController::class, 'destroyAsset'])->middleware('permission:agents.manage');
        Route::get('/agent-runs/{id}', [AgentController::class, 'run'])->middleware('permission:agents.view');

        Route::get('/agent/chats', [AgentChatsController::class, 'index'])->middleware('permission:agents.view');
        Route::post('/agent/chats', [AgentChatsController::class, 'store'])->middleware('permission:agents.manage');
        Route::delete('/agent/chats/{id}', [AgentChatsController::class, 'destroy'])->middleware('permission:agents.manage');
        Route::get('/agent/chat', [AgentChatController::class, 'index'])->middleware('permission:agents.view');
        Route::post('/agent/chat', [AgentChatController::class, 'store'])->middleware('permission:agents.manage');
        Route::post('/agent/chat/confirm', [AgentChatController::class, 'confirm'])->middleware('permission:agents.manage');
        Route::post('/agent/chat/cancel', [AgentChatController::class, 'cancel'])->middleware('permission:agents.manage');
        Route::post('/agent/chat/pending-channels', [AgentChatController::class, 'updatePendingChannels'])->middleware('permission:agents.manage');
        Route::get('/agent/campaigns', [AiCampaignController::class, 'index'])->middleware('permission:agents.view');
        Route::post('/agent/campaigns', [AiCampaignController::class, 'store'])->middleware('permission:agents.manage');
        Route::post('/agent/campaigns/example', [AiCampaignController::class, 'example'])->middleware('permission:agents.manage');
        Route::post('/agent/campaigns/example/brief', [AiCampaignController::class, 'exampleBrief'])->middleware('permission:agents.manage');
        Route::post('/agent/campaigns/estimate', [AiCampaignController::class, 'estimate'])->middleware('permission:agents.view');
        Route::post('/agent/campaigns/{id}/slots/{slotId}/retry', [AiCampaignController::class, 'retrySlot'])->middleware('permission:agents.manage');
        Route::post('/agent/campaigns/{id}/slots/{slotId}/accept', [AiCampaignController::class, 'acceptSlot'])->middleware('permission:agents.manage');
        Route::post('/agent/campaigns/{id}/slots/{slotId}/edit', [AiCampaignController::class, 'editSlot'])->middleware('permission:agents.manage');
        Route::post('/agent/campaigns/{id}/slots/{slotId}/edit-accept', [AiCampaignController::class, 'editAcceptSlot'])->middleware('permission:agents.manage');
        Route::post('/agent/campaigns/{id}/slots/{slotId}/cancel', [AiCampaignController::class, 'cancelSlot'])->middleware('permission:agents.manage');
        Route::post('/agent/campaigns/{id}/slots/{slotId}/regenerate', [AiCampaignController::class, 'regenerateSlot'])->middleware('permission:agents.manage');
        Route::get('/agent/campaigns/{id}', [AiCampaignController::class, 'show'])->middleware('permission:agents.view');
        Route::post('/agent/campaigns/{id}/cancel', [AiCampaignController::class, 'cancel'])->middleware('permission:agents.manage');
        Route::get('/agent/image-jobs/{id}', [AgentImageJobController::class, 'show'])->middleware('permission:agents.manage');
        Route::get('/agent/profile-interview', [ProfileInterviewController::class, 'show'])->middleware('permission:agents.view');
        Route::post('/agent/profile-interview/start', [ProfileInterviewController::class, 'start'])->middleware('permission:agents.manage');
        Route::post('/agent/profile-interview/abandon', [ProfileInterviewController::class, 'abandon'])->middleware('permission:agents.manage');

        Route::get('/products', [ProductController::class, 'index'])->middleware('permission:products.view');
        Route::get('/products/{id}', [ProductController::class, 'show'])->middleware('permission:products.view');
        Route::post('/products', [ProductController::class, 'store'])->middleware('permission:products.manage');
        Route::put('/products/{id}', [ProductController::class, 'update'])->middleware('permission:products.manage');
        Route::post('/products/{id}/images', [ProductController::class, 'storeImage'])->middleware('permission:products.manage');
        Route::patch('/products/{id}/images/{imageId}/main', [ProductController::class, 'setMainImage'])->middleware('permission:products.manage');
        Route::delete('/products/{id}/images/{imageId}', [ProductController::class, 'destroyImage'])->middleware('permission:products.manage');
        Route::delete('/products/{id}', [ProductController::class, 'destroy'])->middleware('permission:products.manage');

        Route::get('/wilayas', [DeliveryController::class, 'wilayas'])->middleware('permission:knowledge.view');
        Route::get('/delivery-zones', [DeliveryController::class, 'index'])->middleware('permission:knowledge.view');
        Route::post('/delivery-zones', [DeliveryController::class, 'store'])->middleware('permission:knowledge.manage');
        Route::put('/delivery-zones/{id}', [DeliveryController::class, 'update'])->middleware('permission:knowledge.manage');
        Route::delete('/delivery-zones/{id}', [DeliveryController::class, 'destroy'])->middleware('permission:knowledge.manage');
        Route::get('/payment-methods', [PaymentMethodController::class, 'index'])->middleware('permission:knowledge.view');
        Route::put('/payment-methods', [PaymentMethodController::class, 'update'])->middleware('permission:knowledge.manage');

        Route::get('/customers', [CustomerController::class, 'index'])->middleware('permission:contacts.view');
        Route::patch('/customers/{id}', [CustomerController::class, 'update'])->middleware('permission:contacts.view');
        Route::get('/leads', [LeadController::class, 'index'])->middleware('permission:contacts.view');
        Route::get('/leads/{id}', [LeadController::class, 'show'])->middleware('permission:contacts.view');

        Route::get('/orders', [OrderController::class, 'index'])->middleware('permission:orders.view');
        Route::patch('/orders/{id}/status', [OrderController::class, 'updateStatus'])->middleware('permission:orders.manage');

        Route::get('/conversations', [ConversationController::class, 'index'])->middleware('permission:inbox.view');
        Route::post('/conversations/sync', [ConversationController::class, 'sync'])->middleware('permission:inbox.view');
        Route::get('/conversations/{id}', [ConversationController::class, 'show'])->middleware('permission:inbox.view');
        Route::post('/conversations/simulate', [ConversationController::class, 'simulate'])->middleware('permission:inbox.simulate');
        Route::post('/conversations/{id}/messages', [ConversationController::class, 'reply'])->middleware('permission:inbox.reply');
        Route::post('/conversations/{id}/handoff', [ConversationController::class, 'handoff'])->middleware('permission:inbox.handoff');
        Route::post('/conversations/{id}/resume-ai', [ConversationController::class, 'resume'])->middleware('permission:inbox.handoff');
        Route::post('/conversations/{id}/history', [ConversationController::class, 'history'])->middleware('permission:inbox.view');

        Route::get('/posts', [PostController::class, 'index'])->middleware('permission:inbox.view');
        Route::post('/posts/previews', [PostController::class, 'previews'])->middleware('permission:inbox.view');
        Route::patch('/posts/ai-settings', [PostAiSettingController::class, 'update'])->middleware('permission:agents.manage');
        Route::post('/posts/ai-settings/bulk', [PostAiSettingController::class, 'bulk'])->middleware('permission:agents.manage');
        Route::post('/posts/ai-settings/image', [PostAiSettingController::class, 'uploadImage'])->middleware('permission:agents.manage');

        Route::get('/scheduled-posts', [ScheduledPostController::class, 'index'])->middleware('permission:inbox.view');
        Route::get('/scheduled-posts/limits', [ScheduledPostController::class, 'limits'])->middleware('permission:inbox.view');
        Route::post('/scheduled-posts/media', [ScheduledPostController::class, 'uploadMedia'])->middleware('permission:inbox.view');
        Route::post('/scheduled-posts', [ScheduledPostController::class, 'store'])->middleware('permission:inbox.view');
        Route::patch('/scheduled-posts/{id}', [ScheduledPostController::class, 'update'])->middleware('permission:inbox.view');
        Route::delete('/scheduled-posts/{id}', [ScheduledPostController::class, 'destroy'])->middleware('permission:inbox.view');
        Route::post('/scheduled-posts/{id}/publish', [ScheduledPostController::class, 'publish'])->middleware('permission:inbox.view');

        Route::get('/workflows/templates', [WorkflowController::class, 'templates'])->middleware('permission:settings.view');
        Route::get('/workflows', [WorkflowController::class, 'index'])->middleware('permission:settings.view');
        Route::post('/workflows/use', [WorkflowController::class, 'useTemplate'])->middleware('permission:settings.manage');
        Route::patch('/workflows/{id}', [WorkflowController::class, 'update'])->middleware('permission:settings.manage');

        Route::get('/social-accounts', [SocialAccountController::class, 'index'])->middleware('permission:settings.view|inbox.view');
        Route::post('/social-accounts/connect', [SocialAccountController::class, 'connect'])->middleware('permission:settings.manage');
        Route::post('/social-accounts/import', [SocialAccountController::class, 'import'])->middleware('permission:settings.manage');
        Route::post('/social-accounts/whatsapp/complete', [SocialAccountController::class, 'completeWhatsApp'])->middleware('permission:settings.manage');
        Route::get('/social-accounts/pending/{connectionId}', [SocialAccountController::class, 'pending'])->middleware('permission:settings.manage');
        Route::post('/social-accounts/pending/select', [SocialAccountController::class, 'selectPending'])->middleware('permission:settings.manage');
        Route::post('/social-accounts/{id}/logo', [SocialAccountController::class, 'uploadLogo'])->middleware('permission:settings.manage');
        Route::delete('/social-accounts/{id}/logo', [SocialAccountController::class, 'clearLogo'])->middleware('permission:settings.manage');
        Route::post('/social-accounts/{id}/refresh-avatar', [SocialAccountController::class, 'refreshAvatar'])->middleware('permission:settings.manage');
        Route::delete('/social-accounts/{id}', [SocialAccountController::class, 'destroy'])->middleware('permission:settings.manage');

        Route::get('/team', [TeamController::class, 'index'])->middleware('permission:team.manage');
        Route::post('/team', [TeamController::class, 'store'])->middleware('permission:team.manage');
        Route::put('/team/{userId}', [TeamController::class, 'update'])->middleware('permission:team.manage');
        Route::delete('/team/{userId}', [TeamController::class, 'destroy'])->middleware('permission:team.manage');
    });
});
