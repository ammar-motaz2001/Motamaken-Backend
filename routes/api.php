<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\AdminAffiliateController;
use App\Http\Controllers\Admin\AdminCampaignController;
use App\Http\Controllers\Admin\AdminCategoryController;
use App\Http\Controllers\Admin\AdminContactController;
use App\Http\Controllers\Admin\AdminDisputeController;
use App\Http\Controllers\Admin\AdminDrawController;
use App\Http\Controllers\Admin\AdminFeedbackController;
use App\Http\Controllers\Admin\AdminKycController;
use App\Http\Controllers\Admin\AdminLedgerController;
use App\Http\Controllers\Admin\AdminOverviewController;
use App\Http\Controllers\Admin\AdminPageController;
use App\Http\Controllers\Admin\AdminPlanController;
use App\Http\Controllers\Admin\AdminProjectController;
use App\Http\Controllers\Admin\AdminReportController;
use App\Http\Controllers\Admin\AdminSettingsController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AdminWithdrawalController;
use App\Http\Controllers\AffiliateController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\OtpController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\QuickAuthController;
use App\Http\Controllers\AvatarController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\CampaignPromotingController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DepositController;
use App\Http\Controllers\DisputeController;
use App\Http\Controllers\DrawController;
use App\Http\Controllers\EscrowController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\GroupConversationController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\KycController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\MilestoneController;
use App\Http\Controllers\MoyasarWebhookController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OptionsController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectManagementController;
use App\Http\Controllers\PromotionController;
use App\Http\Controllers\ProposalController;
use App\Http\Controllers\PublicProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\RewardsController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TeamInvitationController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\WithdrawalController;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureNotSuspended;
use App\Http\Middleware\TouchLastSeen;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

/**
 * Health check.
 *
 * Reports whether Postgres and Redis are reachable.
 *
 * @unauthenticated
 */
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        // Reachability only: versions would tell attackers what to target.
        'database' => DB::selectOne('select 1 as ok') ? 'ok' : 'down',
        'redis' => Redis::ping() ? 'ok' : 'down',
    ]);
});

// Contract of the frontend's authService (motamaken-front).
Route::prefix('auth')->middleware('throttle:auth')->group(function () {
    Route::post('signup', [AuthController::class, 'signup']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('otp/verify', [OtpController::class, 'verify']);
    Route::post('otp/resend', [OtpController::class, 'resend']);
    Route::post('password/forgot', [PasswordController::class, 'forgot']);
    Route::post('password/reset', [PasswordController::class, 'reset']);
    Route::post('quick/start', [QuickAuthController::class, 'start']);
    Route::post('quick/verify', [QuickAuthController::class, 'verify']);
});

Route::middleware(['auth:sanctum', EnsureNotSuspended::class, TouchLastSeen::class])->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);

    // Header: wallet/points pills, unread badges, language/currency/theme choices.
    Route::get('me/summary', [MeController::class, 'summary']);
    Route::patch('me/preferences', [MeController::class, 'updatePreferences']);
    // Settings: account, password, notifications, two-factor, devices.
    Route::patch('me', [AccountController::class, 'update']);
    Route::post('me/password', [AccountController::class, 'password'])->middleware('throttle:auth');
    Route::get('me/notification-settings', [AccountController::class, 'notifications']);
    Route::patch('me/notification-settings', [AccountController::class, 'updateNotifications']);
    Route::get('me/security', [SecurityController::class, 'show']);
    Route::post('me/two-factor', [SecurityController::class, 'start'])->middleware('throttle:auth');
    Route::post('me/two-factor/resend', [SecurityController::class, 'resend'])->middleware('throttle:auth');
    Route::post('me/two-factor/confirm', [SecurityController::class, 'confirm'])->middleware('throttle:auth');
    Route::post('me/two-factor/disable', [SecurityController::class, 'disable'])->middleware('throttle:auth');
    Route::delete('me/sessions/{session}', [SecurityController::class, 'revoke'])->whereNumber('session');
    Route::post('me/sessions/logout-others', [SecurityController::class, 'revokeOthers']);
    Route::post('me/avatar', [AvatarController::class, 'store'])->middleware('throttle:writes');
    Route::delete('me/avatar', [AvatarController::class, 'destroy']);

    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications/read-all', [NotificationController::class, 'readAll']);
    Route::post('notifications/{notification}/read', [NotificationController::class, 'read']);

    // Own profile ("Add Your Profile"): needed to send offers.
    Route::get('me/profile', [ProfileController::class, 'show']);
    Route::put('me/profile', [ProfileController::class, 'update'])->middleware('throttle:writes');
    Route::post('me/portfolio', [PortfolioController::class, 'store'])->middleware('throttle:writes');
    Route::patch('me/portfolio/{item}', [PortfolioController::class, 'update'])->whereNumber('item');
    Route::delete('me/portfolio/{item}', [PortfolioController::class, 'destroy'])->whereNumber('item');
    Route::post('projects/{project}/reviews', [ReviewController::class, 'store'])->whereNumber('project')->middleware('throttle:writes');
    Route::post('reports', [ReportController::class, 'store'])->middleware('throttle:writes');
    Route::get('me/reports', [ReportController::class, 'mine']);

    // Rewards: points, medals, prize draw, referral programme.
    Route::get('me/points', [RewardsController::class, 'points']);
    Route::get('me/medals', [RewardsController::class, 'medals']);
    Route::post('draws/{draw}/entries', [DrawController::class, 'enter'])->whereNumber('draw')->middleware('throttle:writes');
    Route::post('draw-winners/{winner}/claim', [DrawController::class, 'claim'])->whereNumber('winner')->middleware('throttle:money');
    // The heart icon.
    Route::get('me/favorites', [FavoriteController::class, 'index']);
    Route::get('me/favorites/ids', [FavoriteController::class, 'ids']);
    Route::post('favorites', [FavoriteController::class, 'store'])->middleware('throttle:writes');
    Route::delete('favorites/{type}/{id}', [FavoriteController::class, 'destroy'])->whereNumber('id');

    // Promotion campaigns: the advertiser's side, then the promoter's.
    Route::get('me/campaigns', [CampaignController::class, 'index']);
    Route::post('campaigns', [CampaignController::class, 'store'])->middleware('throttle:writes');
    Route::get('campaigns/{campaign}', [CampaignController::class, 'show'])->whereNumber('campaign');
    Route::patch('campaigns/{campaign}', [CampaignController::class, 'update'])->whereNumber('campaign');
    Route::post('campaigns/{campaign}/launch', [CampaignController::class, 'launch'])->whereNumber('campaign')->middleware('throttle:money');
    Route::post('campaigns/{campaign}/pause', [CampaignController::class, 'pause'])->whereNumber('campaign');
    Route::post('campaigns/{campaign}/resume', [CampaignController::class, 'resume'])->whereNumber('campaign');
    Route::post('campaigns/{campaign}/cancel', [CampaignController::class, 'cancel'])->whereNumber('campaign');
    Route::get('campaigns/{campaign}/promoters', [CampaignController::class, 'promoters'])->whereNumber('campaign');
    Route::get('campaigns/{campaign}/visits', [CampaignController::class, 'visits'])->whereNumber('campaign');
    Route::get('campaigns/{campaign}/daily', [CampaignController::class, 'daily'])->whereNumber('campaign');
    Route::get('campaigns/available', [CampaignPromotingController::class, 'available']);
    Route::post('campaigns/{campaign}/join', [CampaignPromotingController::class, 'join'])->whereNumber('campaign')->middleware('throttle:writes');
    Route::get('me/promoter', [CampaignPromotingController::class, 'dashboard']);

    Route::get('me/affiliate', [AffiliateController::class, 'show']);
    Route::get('me/affiliate/referrals', [AffiliateController::class, 'referrals']);
    Route::get('me/affiliate/earnings', [AffiliateController::class, 'earnings']);

    // Post Your Project, My Projects, My Offers.
    Route::post('projects', [ProjectManagementController::class, 'store'])->middleware('throttle:writes');
    Route::get('me/projects', [ProjectManagementController::class, 'mine']);
    Route::patch('projects/{project}', [ProjectManagementController::class, 'update'])->whereNumber('project');
    Route::delete('projects/{project}', [ProjectManagementController::class, 'destroy'])->whereNumber('project');
    Route::post('projects/{project}/close', [ProjectManagementController::class, 'close'])->whereNumber('project');
    Route::post('projects/{project}/complete', [ProjectManagementController::class, 'complete'])->whereNumber('project');

    Route::get('projects/{project}/proposals', [ProposalController::class, 'index'])->whereNumber('project');
    Route::post('projects/{project}/proposals', [ProposalController::class, 'store'])
        ->whereNumber('project')->middleware('throttle:writes');
    Route::get('me/proposals', [ProposalController::class, 'mine']);
    Route::get('proposals/{proposal}', [ProposalController::class, 'show'])->whereNumber('proposal');
    Route::patch('proposals/{proposal}', [ProposalController::class, 'update'])->whereNumber('proposal');
    Route::post('proposals/{proposal}/withdraw', [ProposalController::class, 'withdraw'])->whereNumber('proposal');
    Route::post('proposals/{proposal}/accept', [ProposalController::class, 'accept'])->whereNumber('proposal');
    Route::post('proposals/{proposal}/decline', [ProposalController::class, 'decline'])->whereNumber('proposal');

    // Plans, subscriptions and paid placement.
    Route::get('me/subscription', [SubscriptionController::class, 'show']);
    Route::post('subscriptions', [SubscriptionController::class, 'store'])->middleware('throttle:money');
    Route::post('subscriptions/cancel', [SubscriptionController::class, 'cancel']);
    Route::post('subscriptions/resume', [SubscriptionController::class, 'resume']);
    Route::get('promotions/prices', [PromotionController::class, 'prices']);
    Route::get('promotions/packages', [PromotionController::class, 'packages']);
    Route::post('promotions', [PromotionController::class, 'store'])->middleware('throttle:money');
    Route::get('me/promotions', [PromotionController::class, 'index']);

    // Teams: freelancers who share a project and its pay.
    Route::get('teams', [TeamController::class, 'index']);
    Route::post('teams', [TeamController::class, 'store'])->middleware('throttle:writes');
    Route::get('teams/{team}', [TeamController::class, 'show'])->whereNumber('team');
    Route::patch('teams/{team}', [TeamController::class, 'update'])->whereNumber('team');
    Route::post('teams/{team}/members', [TeamController::class, 'invite'])->whereNumber('team')->middleware('throttle:writes');
    Route::patch('teams/{team}/members/{member}', [TeamController::class, 'updateMember'])->whereNumber(['team', 'member']);
    Route::delete('teams/{team}/members/{member}', [TeamController::class, 'removeMember'])->whereNumber(['team', 'member']);
    Route::post('teams/{team}/members/{member}/resend', [TeamController::class, 'resend'])->whereNumber(['team', 'member']);
    Route::post('teams/{team}/publish', [TeamController::class, 'publish'])->whereNumber('team');
    Route::post('teams/{team}/unpublish', [TeamController::class, 'unpublish'])->whereNumber('team');
    Route::post('teams/{team}/disband', [TeamController::class, 'disband'])->whereNumber('team');
    Route::post('teams/{team}/leave', [TeamController::class, 'leave'])->whereNumber('team');
    Route::get('me/team-invitations', [TeamInvitationController::class, 'index']);
    Route::post('team-invitations/{member}/accept', [TeamInvitationController::class, 'accept'])->whereNumber('member');
    Route::post('team-invitations/{member}/decline', [TeamInvitationController::class, 'decline'])->whereNumber('member');

    // Wallet, deposits, withdrawals.
    Route::get('wallet', [WalletController::class, 'show']);
    Route::get('wallet/transactions', [WalletController::class, 'transactions']);
    Route::get('wallet/stats', [WalletController::class, 'stats']);
    Route::get('wallet/deposits', [DepositController::class, 'index']);
    Route::post('wallet/deposits', [DepositController::class, 'store'])->middleware('throttle:money');
    Route::post('wallet/deposits/{deposit}/refresh', [DepositController::class, 'refresh'])->whereNumber('deposit');
    Route::post('wallet/deposits/{deposit}/simulate', [DepositController::class, 'simulate'])->whereNumber('deposit');
    Route::get('wallet/withdrawals', [WithdrawalController::class, 'index']);
    Route::post('wallet/withdrawals', [WithdrawalController::class, 'store'])->middleware('throttle:money');
    Route::post('feedback', [FeedbackController::class, 'store'])->middleware('throttle:writes');
    Route::get('me/payment-methods', [PaymentMethodController::class, 'index']);
    Route::post('me/payment-methods', [PaymentMethodController::class, 'store'])->middleware('throttle:money');
    Route::post('me/payment-methods/{method}/default', [PaymentMethodController::class, 'makeDefault'])->whereNumber('method');
    Route::delete('me/payment-methods/{method}', [PaymentMethodController::class, 'destroy'])->whereNumber('method');
    Route::post('wallet/withdrawals/{withdrawal}/cancel', [WithdrawalController::class, 'cancel'])->whereNumber('withdrawal');

    // Escrow: payment protection with milestones.
    Route::get('me/escrows', [EscrowController::class, 'mine']);
    Route::post('projects/{project}/escrow', [EscrowController::class, 'store'])->whereNumber('project')->middleware('throttle:money');
    Route::get('projects/{project}/escrow', [EscrowController::class, 'show'])->whereNumber('project');
    Route::post('projects/{project}/escrow/fund', [EscrowController::class, 'fund'])->whereNumber('project')->middleware('throttle:money');
    Route::post('projects/{project}/escrow/split/approve', [EscrowController::class, 'approveSplit'])->whereNumber('project');
    Route::post('projects/{project}/escrow/split/object', [EscrowController::class, 'objectSplit'])->whereNumber('project');
    Route::post('projects/{project}/escrow/tip', [EscrowController::class, 'tip'])->whereNumber('project')->middleware('throttle:money');
    Route::post('projects/{project}/escrow/cancel', [EscrowController::class, 'cancel'])->whereNumber('project');
    Route::post('milestones/{milestone}/fund', [MilestoneController::class, 'fund'])->whereNumber('milestone')->middleware('throttle:money');
    Route::post('milestones/{milestone}/submit', [MilestoneController::class, 'submit'])->whereNumber('milestone');
    Route::post('milestones/{milestone}/approve', [MilestoneController::class, 'approve'])->whereNumber('milestone')->middleware('throttle:money');
    Route::post('milestones/{milestone}/request-changes', [MilestoneController::class, 'requestChanges'])
        ->whereNumber('milestone')->name('milestones.changes');

    // Identity verification and disputes.
    Route::get('me/kyc', [KycController::class, 'show']);
    Route::post('me/kyc', [KycController::class, 'store'])->middleware('throttle:writes');
    Route::post('milestones/{milestone}/dispute', [DisputeController::class, 'store'])->whereNumber('milestone')->middleware('throttle:writes');
    Route::get('disputes', [DisputeController::class, 'index']);
    Route::get('disputes/{dispute}', [DisputeController::class, 'show'])->whereNumber('dispute');
    Route::post('disputes/{dispute}/messages', [DisputeController::class, 'message'])->whereNumber('dispute')->middleware('throttle:writes');
    Route::post('disputes/{dispute}/withdraw', [DisputeController::class, 'withdraw'])->whereNumber('dispute');
    Route::post('disputes/{dispute}/concede', [DisputeController::class, 'concede'])->whereNumber('dispute');

    // Admin panel.
    Route::prefix('admin')->middleware(EnsureAdmin::class)->group(function () {
        Route::get('overview', AdminOverviewController::class);
        Route::get('users', [AdminUserController::class, 'index']);
        Route::get('users/{user}', [AdminUserController::class, 'show'])->whereNumber('user');
        Route::post('users/{user}/suspend', [AdminUserController::class, 'suspend'])->whereNumber('user');
        Route::post('users/{user}/unsuspend', [AdminUserController::class, 'unsuspend'])->whereNumber('user');
        Route::post('users/{user}/adjust', [AdminUserController::class, 'adjust'])->whereNumber('user')->middleware('throttle:money');
        Route::get('projects', [AdminProjectController::class, 'index']);
        Route::post('projects/{project}/close', [AdminProjectController::class, 'close'])->whereNumber('project');
        Route::post('projects/{project}/feature', [AdminProjectController::class, 'feature'])->whereNumber('project');
        Route::post('projects/{project}/unfeature', [AdminProjectController::class, 'unfeature'])->whereNumber('project');
        Route::post('profiles/{profile}/feature', [AdminProjectController::class, 'featureProfile'])->whereNumber('profile');
        Route::get('kyc', [AdminKycController::class, 'index']);
        Route::get('kyc/{submission}', [AdminKycController::class, 'show'])->whereNumber('submission');
        Route::post('kyc/{submission}/approve', [AdminKycController::class, 'approve'])->whereNumber('submission');
        Route::post('kyc/{submission}/reject', [AdminKycController::class, 'reject'])->whereNumber('submission');
        Route::get('disputes', [AdminDisputeController::class, 'index']);
        Route::post('disputes/{dispute}/review', [AdminDisputeController::class, 'review'])->whereNumber('dispute');
        Route::post('disputes/{dispute}/resolve', [AdminDisputeController::class, 'resolve'])->whereNumber('dispute');
        Route::post('disputes/{dispute}/messages', [AdminDisputeController::class, 'message'])->whereNumber('dispute');
        Route::get('pages', [AdminPageController::class, 'index']);
        Route::get('pages/{slug}', [AdminPageController::class, 'show']);
        Route::put('pages/{slug}', [AdminPageController::class, 'update']);
        Route::delete('pages/{slug}', [AdminPageController::class, 'destroy']);
        Route::get('contact-messages', [AdminContactController::class, 'index']);
        Route::post('contact-messages/{message}/handle', [AdminContactController::class, 'handle'])->whereNumber('message');
        Route::get('affiliate', AdminAffiliateController::class);
        Route::get('campaigns', [AdminCampaignController::class, 'index']);
        Route::post('campaigns/{campaign}/cancel', [AdminCampaignController::class, 'cancel'])->whereNumber('campaign');
        Route::get('withdrawals', [AdminWithdrawalController::class, 'index']);
        Route::post('withdrawals/{withdrawal}/approve', [AdminWithdrawalController::class, 'approve'])->whereNumber('withdrawal');
        Route::post('withdrawals/{withdrawal}/reject', [AdminWithdrawalController::class, 'reject'])->whereNumber('withdrawal');
        Route::get('ledger/transactions', [AdminLedgerController::class, 'transactions']);
        Route::get('ledger/trial-balance', [AdminLedgerController::class, 'trialBalance']);
        Route::get('plans', [AdminPlanController::class, 'index']);
        Route::patch('plans/{plan}', [AdminPlanController::class, 'update'])->whereNumber('plan');
        Route::get('draws', [AdminDrawController::class, 'index']);
        Route::post('draws', [AdminDrawController::class, 'store']);
        Route::post('draws/{draw}/run', [AdminDrawController::class, 'run'])->whereNumber('draw');
        Route::get('reports', [AdminReportController::class, 'index']);
        Route::post('reports/{report}/resolve', [AdminReportController::class, 'resolve'])->whereNumber('report');
        Route::get('feedback', AdminFeedbackController::class);
        Route::get('categories', [AdminCategoryController::class, 'index']);
        Route::post('categories', [AdminCategoryController::class, 'store']);
        Route::patch('categories/{category}', [AdminCategoryController::class, 'update'])->whereNumber('category');
        Route::delete('categories/{category}', [AdminCategoryController::class, 'destroy'])->whereNumber('category');
        Route::get('settings', [AdminSettingsController::class, 'show']);
        Route::put('settings', [AdminSettingsController::class, 'update']);
    });

    // Messages page.
    Route::get('conversations', [ConversationController::class, 'index']);
    Route::post('conversations', [ConversationController::class, 'store'])->middleware('throttle:messages');
    Route::post('conversations/group', [GroupConversationController::class, 'store'])->middleware('throttle:messages')->name('conversations.group');
    Route::post('conversations/{conversation}/members', [GroupConversationController::class, 'addMembers'])->whereNumber('conversation');
    Route::delete('conversations/{conversation}/members/{user}', [GroupConversationController::class, 'removeMember'])->whereNumber(['conversation', 'user']);
    Route::post('conversations/{conversation}/leave', [GroupConversationController::class, 'leave'])->whereNumber('conversation');
    Route::get('conversations/{conversation}', [ConversationController::class, 'show'])->whereNumber('conversation');
    Route::post('conversations/{conversation}/read', [ConversationController::class, 'read'])->whereNumber('conversation');
    Route::get('conversations/{conversation}/messages', [MessageController::class, 'index'])->whereNumber('conversation');
    Route::post('conversations/{conversation}/messages', [MessageController::class, 'store'])
        ->whereNumber('conversation')->middleware('throttle:messages');
});

// Public: the homepage works for visitors too.
Route::get('home', [HomeController::class, 'index']);
Route::get('projects', [ProjectController::class, 'index']);
Route::get('projects/{project}', [ProjectController::class, 'show'])->whereNumber('project');
Route::get('search', SearchController::class);
Route::get('search/skills', [SearchController::class, 'skills']);
Route::get('users/{user}', [PublicProfileController::class, 'show'])->whereNumber('user');
Route::get('draws/current', [DrawController::class, 'current']);
Route::get('draws/history', [DrawController::class, 'history']);
Route::post('affiliate/visits', [AffiliateController::class, 'visit'])->middleware('throttle:writes');
Route::get('pages', [PageController::class, 'index']);
Route::get('pages/{slug}', [PageController::class, 'show']);
Route::post('contact', ContactController::class)->middleware('throttle:contact');
// Reported by the website's /r/{code} page from the visitor's browser.
Route::post('campaign-visits', [CampaignPromotingController::class, 'visit'])->middleware('throttle:campaign-visits');
Route::get('users/{user}/reviews', [ReviewController::class, 'user'])->whereNumber('user');
Route::get('projects/{project}/reviews', [ReviewController::class, 'project'])->whereNumber('project');
Route::get('categories', [CategoryController::class, 'index']);
Route::get('countries', [LocationController::class, 'countries']);
Route::get('countries/{country}/cities', [LocationController::class, 'cities'])->whereNumber('country');
Route::get('plans', [PlanController::class, 'index']);
Route::get('currencies', [OptionsController::class, 'currencies']);
Route::get('languages', [OptionsController::class, 'languages']);

// Called by the payment provider, signed with the shared secret instead of a token.
Route::post('webhooks/payments', PaymentWebhookController::class);
Route::post('webhooks/moyasar', MoyasarWebhookController::class)->middleware('throttle:60,1');

// Signed links: documents and evidence open in <img> tags and new tabs, which cannot send a token.
Route::get('kyc-files/{submission}/{kind}', [AdminKycController::class, 'file'])->whereNumber('submission')->middleware('signed')->name('kyc.file');
Route::get('dispute-files/{message}', [DisputeController::class, 'attachment'])->whereNumber('message')->middleware('signed')->name('disputes.attachment');

// Opened by <img>/<audio> tags, so it is protected by its signature instead of the token header.
Route::get('messages/{message}/attachment', [MessageController::class, 'attachment'])
    ->whereNumber('message')->middleware('signed')->name('messages.attachment');
