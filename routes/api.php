<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\AdminAffiliateController;
use App\Http\Controllers\Admin\AdminAnalyticsController;
use App\Http\Controllers\Admin\AdminAppealController;
use App\Http\Controllers\Admin\AdminBlacklistController;
use App\Http\Controllers\Admin\AdminBroadcastController;
use App\Http\Controllers\Admin\AdminCampaignController;
use App\Http\Controllers\Admin\AdminCategoryController;
use App\Http\Controllers\Admin\AdminContactController;
use App\Http\Controllers\Admin\AdminCurrencyController;
use App\Http\Controllers\Admin\AdminDepositController;
use App\Http\Controllers\Admin\AdminDisputeController;
use App\Http\Controllers\Admin\AdminDrawController;
use App\Http\Controllers\Admin\AdminEscrowController;
use App\Http\Controllers\Admin\AdminExportController;
use App\Http\Controllers\Admin\AdminFaqController;
use App\Http\Controllers\Admin\AdminFeedbackController;
use App\Http\Controllers\Admin\AdminIntegrationController;
use App\Http\Controllers\Admin\AdminKycController;
use App\Http\Controllers\Admin\AdminLedgerController;
use App\Http\Controllers\Admin\AdminLocationController;
use App\Http\Controllers\Admin\AdminMailController;
use App\Http\Controllers\Admin\AdminMedalController;
use App\Http\Controllers\Admin\AdminMessageController;
use App\Http\Controllers\Admin\AdminNotificationTemplateController;
use App\Http\Controllers\Admin\AdminOverviewController;
use App\Http\Controllers\Admin\AdminPageController;
use App\Http\Controllers\Admin\AdminPlanController;
use App\Http\Controllers\Admin\AdminProjectController;
use App\Http\Controllers\Admin\AdminPromotionController;
use App\Http\Controllers\Admin\AdminProposalController;
use App\Http\Controllers\Admin\AdminReportController;
use App\Http\Controllers\Admin\AdminReviewController;
use App\Http\Controllers\Admin\AdminRewardsController;
use App\Http\Controllers\Admin\AdminSettingsController;
use App\Http\Controllers\Admin\AdminSiteSettingsController;
use App\Http\Controllers\Admin\AdminSkillController;
use App\Http\Controllers\Admin\AdminStaffController;
use App\Http\Controllers\Admin\AdminSubscriptionController;
use App\Http\Controllers\Admin\AdminSupportController;
use App\Http\Controllers\Admin\AdminTeamController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AdminWithdrawalController;
use App\Http\Controllers\AffiliateController;
use App\Http\Controllers\AgreementChangeController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\GoogleAuthController;
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
use App\Http\Controllers\FaqController;
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
use App\Http\Controllers\SiteSettingsController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TeamInvitationController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\WithdrawalController;
use App\Http\Middleware\AuditAdminActions;
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
    // Only up or down: which services sit behind the API is not anyone's business. 503 lets monitors alert.
    $up = rescue(fn () => DB::selectOne('select 1 as ok') && Redis::ping(), false, false);

    return response()->json(['status' => $up ? 'ok' : 'down'], $up ? 200 : 503);
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
    Route::post('google', [GoogleAuthController::class, 'login']);
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
    Route::post('reviews/{review}/reply', [ReviewController::class, 'reply'])->whereNumber('review')->middleware('throttle:writes');
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
    Route::post('projects/{project}/publish', [ProjectManagementController::class, 'publish'])->whereNumber('project');
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
    // Changing or cancelling an accepted offer by agreement of both sides.
    Route::get('projects/{project}/agreement-changes', [AgreementChangeController::class, 'index'])->whereNumber('project');
    Route::post('projects/{project}/agreement-changes', [AgreementChangeController::class, 'store'])->whereNumber('project')->middleware('throttle:writes');
    Route::post('agreement-changes/{change}/accept', [AgreementChangeController::class, 'accept'])->whereNumber('change')->middleware('throttle:money');
    Route::post('agreement-changes/{change}/decline', [AgreementChangeController::class, 'decline'])->whereNumber('change');
    Route::post('agreement-changes/{change}/withdraw', [AgreementChangeController::class, 'withdraw'])->whereNumber('change');
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

    // Admin panel: every route needs the permission of its area; every change lands in the audit log.
    Route::prefix('admin')->middleware([EnsureAdmin::class, AuditAdminActions::class])->group(function () {
        Route::middleware('admin.can:overview')->group(function () {
            Route::get('overview', AdminOverviewController::class);
            Route::get('analytics', AdminAnalyticsController::class);
        });
        Route::get('export/{dataset}', AdminExportController::class)->middleware('admin.can:exports');

        Route::middleware('admin.can:users.view')->group(function () {
            Route::get('users', [AdminUserController::class, 'index']);
            Route::get('users/{user}', [AdminUserController::class, 'show'])->whereNumber('user');
            Route::get('users/{user}/activity', [AdminUserController::class, 'activity'])->whereNumber('user');
        });
        Route::middleware('admin.can:users.manage')->group(function () {
            Route::patch('users/{user}', [AdminUserController::class, 'update'])->whereNumber('user');
            Route::post('users/{user}/password', [AdminUserController::class, 'password'])->whereNumber('user');
            Route::delete('users/{user}', [AdminUserController::class, 'destroy'])->whereNumber('user');
            Route::post('users/{user}/suspend', [AdminUserController::class, 'suspend'])->whereNumber('user');
            Route::post('users/{user}/ban', [AdminUserController::class, 'ban'])->whereNumber('user');
            Route::post('users/{user}/unsuspend', [AdminUserController::class, 'unsuspend'])->whereNumber('user');
            Route::post('users/{user}/sessions/revoke', [AdminUserController::class, 'revokeSessions'])->whereNumber('user');
            Route::post('users/{user}/notes', [AdminUserController::class, 'addNote'])->whereNumber('user');
            Route::delete('users/{user}/notes/{note}', [AdminUserController::class, 'deleteNote'])->whereNumber(['user', 'note']);
            Route::post('profiles/{profile}/feature', [AdminProjectController::class, 'featureProfile'])->whereNumber('profile');
        });
        Route::post('users/{user}/adjust', [AdminUserController::class, 'adjust'])->whereNumber('user')->middleware(['admin.can:users.wallet', 'throttle:money']);

        Route::middleware('admin.can:kyc')->group(function () {
            Route::get('kyc', [AdminKycController::class, 'index']);
            Route::get('kyc/{submission}', [AdminKycController::class, 'show'])->whereNumber('submission');
            Route::post('kyc/{submission}/approve', [AdminKycController::class, 'approve'])->whereNumber('submission');
            Route::post('kyc/{submission}/reject', [AdminKycController::class, 'reject'])->whereNumber('submission');
            Route::post('kyc/{submission}/request-update', [AdminKycController::class, 'requestUpdate'])->whereNumber('submission');
        });

        Route::middleware('admin.can:reports')->group(function () {
            Route::get('reports', [AdminReportController::class, 'index']);
            Route::post('reports/{report}/resolve', [AdminReportController::class, 'resolve'])->whereNumber('report');
            Route::post('reports/{report}/priority', [AdminReportController::class, 'priority'])->whereNumber('report');
        });

        Route::middleware('admin.can:marketplace')->group(function () {
            Route::get('projects', [AdminProjectController::class, 'index']);
            Route::get('projects/{project}', [AdminProjectController::class, 'show'])->whereNumber('project');
            Route::post('projects/{project}/close', [AdminProjectController::class, 'close'])->whereNumber('project');
            Route::post('projects/{project}/hide', [AdminProjectController::class, 'hide'])->whereNumber('project');
            Route::post('projects/{project}/reactivate', [AdminProjectController::class, 'reactivate'])->whereNumber('project');
            Route::post('projects/{project}/feature', [AdminProjectController::class, 'feature'])->whereNumber('project');
            Route::post('projects/{project}/unfeature', [AdminProjectController::class, 'unfeature'])->whereNumber('project');
            Route::get('proposals', [AdminProposalController::class, 'index']);
            Route::get('teams', [AdminTeamController::class, 'index']);
            Route::get('teams/{team}', [AdminTeamController::class, 'show'])->whereNumber('team');
            Route::post('teams/{team}/disband', [AdminTeamController::class, 'disband'])->whereNumber('team');
            Route::delete('teams/{team}/members/{member}', [AdminTeamController::class, 'removeMember'])->whereNumber(['team', 'member']);
            Route::get('reviews', [AdminReviewController::class, 'index']);
            Route::post('reviews/{review}/hide', [AdminReviewController::class, 'hide'])->whereNumber('review');
            Route::post('reviews/{review}/unhide', [AdminReviewController::class, 'unhide'])->whereNumber('review');
            Route::get('campaigns', [AdminCampaignController::class, 'index']);
            Route::post('campaigns/{campaign}/cancel', [AdminCampaignController::class, 'cancel'])->whereNumber('campaign');
        });

        Route::middleware('admin.can:disputes')->group(function () {
            Route::get('disputes', [AdminDisputeController::class, 'index']);
            Route::post('disputes/{dispute}/review', [AdminDisputeController::class, 'review'])->whereNumber('dispute');
            Route::post('disputes/{dispute}/resolve', [AdminDisputeController::class, 'resolve'])->whereNumber('dispute');
            Route::post('disputes/{dispute}/messages', [AdminDisputeController::class, 'message'])->whereNumber('dispute');
            Route::get('disputes/{dispute}/conversation', [AdminDisputeController::class, 'conversation'])->whereNumber('dispute');
            Route::get('appeals', [AdminAppealController::class, 'index']);
            Route::post('appeals/{appeal}/decide', [AdminAppealController::class, 'decide'])->whereNumber('appeal')->middleware('throttle:money');
        });

        Route::middleware('admin.can:finance.view|finance.manage')->group(function () {
            Route::get('withdrawals', [AdminWithdrawalController::class, 'index']);
            Route::get('ledger/transactions', [AdminLedgerController::class, 'transactions']);
            Route::get('ledger/trial-balance', [AdminLedgerController::class, 'trialBalance']);
            Route::get('deposits', [AdminDepositController::class, 'index']);
            Route::get('escrows', [AdminEscrowController::class, 'index']);
            Route::get('escrows/{escrow}', [AdminEscrowController::class, 'show'])->whereNumber('escrow');
            Route::get('subscriptions', [AdminSubscriptionController::class, 'index']);
            Route::get('promotions', [AdminPromotionController::class, 'index']);
            Route::get('promotions/prices', [AdminPromotionController::class, 'prices']);
            Route::get('plans', [AdminPlanController::class, 'index']);
            Route::get('settings', [AdminSettingsController::class, 'show']);
        });
        Route::middleware('admin.can:finance.manage')->group(function () {
            Route::post('withdrawals/{withdrawal}/approve', [AdminWithdrawalController::class, 'approve'])->whereNumber('withdrawal');
            Route::post('withdrawals/{withdrawal}/reject', [AdminWithdrawalController::class, 'reject'])->whereNumber('withdrawal');
            Route::post('deposits/{deposit}/refresh', [AdminDepositController::class, 'refresh'])->whereNumber('deposit');
            Route::post('escrows/{escrow}/cancel', [AdminEscrowController::class, 'cancel'])->whereNumber('escrow')->middleware('throttle:money');
            Route::post('promotions/{promotion}/end', [AdminPromotionController::class, 'end'])->whereNumber('promotion');
            Route::put('promotions/prices', [AdminPromotionController::class, 'updatePrices']);
            Route::patch('plans/{plan}', [AdminPlanController::class, 'update'])->whereNumber('plan');
            Route::put('settings', [AdminSettingsController::class, 'update']);
        });

        Route::middleware('admin.can:rewards')->group(function () {
            Route::get('affiliate', AdminAffiliateController::class);
            Route::get('affiliate/suspicious', [AdminAffiliateController::class, 'suspicious']);
            Route::post('affiliate/referrals/{user}/revoke', [AdminAffiliateController::class, 'revoke'])->whereNumber('user');
            Route::post('affiliate/referrals/{user}/approve', [AdminAffiliateController::class, 'approve'])->whereNumber('user');
            Route::get('draws', [AdminDrawController::class, 'index']);
            Route::post('draws', [AdminDrawController::class, 'store']);
            Route::post('draws/{draw}/run', [AdminDrawController::class, 'run'])->whereNumber('draw');
            Route::get('points/rules', [AdminRewardsController::class, 'rules']);
            Route::put('points/rules', [AdminRewardsController::class, 'updateRules']);
            Route::get('points/transactions', [AdminRewardsController::class, 'transactions']);
            Route::get('points/report', [AdminRewardsController::class, 'report']);
            Route::post('users/{user}/points', [AdminRewardsController::class, 'adjust'])->whereNumber('user');
            Route::get('medals', [AdminMedalController::class, 'index']);
            Route::post('medals', [AdminMedalController::class, 'store']);
            Route::patch('medals/{medal}', [AdminMedalController::class, 'update'])->whereNumber('medal');
            Route::get('medals/{medal}/holders', [AdminMedalController::class, 'holders'])->whereNumber('medal');
            Route::post('medals/{medal}/grant', [AdminMedalController::class, 'grant'])->whereNumber('medal');
            Route::delete('medals/{medal}/holders/{user}', [AdminMedalController::class, 'revoke'])->whereNumber(['medal', 'user']);
        });

        Route::middleware('admin.can:content')->group(function () {
            Route::get('pages', [AdminPageController::class, 'index']);
            Route::get('pages/{slug}', [AdminPageController::class, 'show']);
            Route::put('pages/{slug}', [AdminPageController::class, 'update']);
            Route::delete('pages/{slug}', [AdminPageController::class, 'destroy']);
            Route::get('categories', [AdminCategoryController::class, 'index']);
            Route::post('categories', [AdminCategoryController::class, 'store']);
            Route::patch('categories/{category}', [AdminCategoryController::class, 'update'])->whereNumber('category');
            Route::delete('categories/{category}', [AdminCategoryController::class, 'destroy'])->whereNumber('category');
            Route::get('skills', [AdminSkillController::class, 'index']);
            Route::post('skills', [AdminSkillController::class, 'store']);
            Route::patch('skills/{skill}', [AdminSkillController::class, 'update'])->whereNumber('skill');
            Route::delete('skills/{skill}', [AdminSkillController::class, 'destroy'])->whereNumber('skill');
            Route::get('countries', [AdminLocationController::class, 'countries']);
            Route::post('countries', [AdminLocationController::class, 'storeCountry']);
            Route::patch('countries/{country}', [AdminLocationController::class, 'updateCountry'])->whereNumber('country');
            Route::get('countries/{country}/cities', [AdminLocationController::class, 'cities'])->whereNumber('country');
            Route::post('countries/{country}/cities', [AdminLocationController::class, 'storeCity'])->whereNumber('country');
            Route::patch('cities/{city}', [AdminLocationController::class, 'updateCity'])->whereNumber('city');
            Route::get('faqs', [AdminFaqController::class, 'index']);
            Route::post('faq-categories', [AdminFaqController::class, 'storeCategory']);
            Route::patch('faq-categories/{category}', [AdminFaqController::class, 'updateCategory'])->whereNumber('category');
            Route::delete('faq-categories/{category}', [AdminFaqController::class, 'destroyCategory'])->whereNumber('category');
            Route::post('faqs', [AdminFaqController::class, 'store']);
            Route::patch('faqs/{faq}', [AdminFaqController::class, 'update'])->whereNumber('faq');
            Route::delete('faqs/{faq}', [AdminFaqController::class, 'destroy'])->whereNumber('faq');
        });

        Route::middleware('admin.can:support')->group(function () {
            Route::get('contact-messages', [AdminContactController::class, 'index']);
            Route::post('contact-messages/{message}/handle', [AdminContactController::class, 'handle'])->whereNumber('message');
            Route::post('contact-messages/{message}/reply', [AdminContactController::class, 'reply'])->whereNumber('message');
            Route::post('contact-messages/{message}/archive', [AdminContactController::class, 'archive'])->whereNumber('message');
            Route::get('support', [AdminSupportController::class, 'index']);
            Route::get('support/{ticket}', [AdminSupportController::class, 'show'])->whereNumber('ticket');
            Route::post('support/{ticket}/reply', [AdminSupportController::class, 'reply'])->whereNumber('ticket');
            Route::patch('support/{ticket}', [AdminSupportController::class, 'update'])->whereNumber('ticket');
            Route::get('messages/stats', [AdminMessageController::class, 'stats']);
            Route::get('feedback', AdminFeedbackController::class);
        });

        Route::middleware('admin.can:notifications')->group(function () {
            Route::get('broadcasts', [AdminBroadcastController::class, 'index']);
            Route::post('broadcasts', [AdminBroadcastController::class, 'store']);
            Route::get('notification-templates', [AdminNotificationTemplateController::class, 'index']);
            Route::put('notification-templates/{type}', [AdminNotificationTemplateController::class, 'update']);
            Route::delete('notification-templates/{type}', [AdminNotificationTemplateController::class, 'destroy']);
            Route::get('mail/logs', [AdminMailController::class, 'logs']);
        });

        Route::middleware('admin.can:settings')->group(function () {
            Route::get('site-settings', [AdminSiteSettingsController::class, 'show']);
            Route::get('currencies', [AdminCurrencyController::class, 'index']);
            Route::put('currencies', [AdminCurrencyController::class, 'update']);
            Route::put('site-settings', [AdminSiteSettingsController::class, 'update']);
            Route::post('site-settings/logo', [AdminSiteSettingsController::class, 'logo']);
            Route::get('mail', [AdminMailController::class, 'show']);
            Route::put('mail', [AdminMailController::class, 'update']);
            Route::post('mail/test', [AdminMailController::class, 'test']);
            Route::get('integrations', [AdminIntegrationController::class, 'status']);
            Route::put('integrations/payments/mode', [AdminIntegrationController::class, 'paymentsMode']);
            Route::get('integrations/logs', [AdminIntegrationController::class, 'logs']);
            Route::get('integrations/failed-jobs', [AdminIntegrationController::class, 'failedJobs']);
            Route::post('integrations/failed-jobs/{uuid}/retry', [AdminIntegrationController::class, 'retry']);
            Route::get('blacklist', [AdminBlacklistController::class, 'index']);
            Route::post('blacklist', [AdminBlacklistController::class, 'store']);
            Route::patch('blacklist/{entry}', [AdminBlacklistController::class, 'update'])->whereNumber('entry');
            Route::delete('blacklist/{entry}', [AdminBlacklistController::class, 'destroy'])->whereNumber('entry');
        });

        Route::middleware('admin.can:staff')->group(function () {
            Route::get('staff', [AdminStaffController::class, 'index']);
            Route::post('staff', [AdminStaffController::class, 'store']);
            Route::patch('staff/{user}', [AdminStaffController::class, 'update'])->whereNumber('user');
            Route::post('staff/{user}/sessions/revoke', [AdminStaffController::class, 'revokeSessions'])->whereNumber('user');
            Route::get('roles', [AdminStaffController::class, 'roles']);
            Route::post('roles', [AdminStaffController::class, 'storeRole']);
            Route::patch('roles/{role}', [AdminStaffController::class, 'updateRole'])->whereNumber('role');
            Route::delete('roles/{role}', [AdminStaffController::class, 'destroyRole'])->whereNumber('role');
            Route::get('audit', [AdminStaffController::class, 'audit']);
        });
    });

    // Support tickets ("Contact support").
    Route::get('support', [SupportController::class, 'index']);
    Route::post('support', [SupportController::class, 'store'])->middleware('throttle:writes');
    Route::get('support/{ticket}', [SupportController::class, 'show'])->whereNumber('ticket');
    Route::post('support/{ticket}/reply', [SupportController::class, 'reply'])->whereNumber('ticket')->middleware('throttle:writes');
    Route::post('support/{ticket}/close', [SupportController::class, 'close'])->whereNumber('ticket');
    Route::post('disputes/{dispute}/appeal', [DisputeController::class, 'appeal'])->whereNumber('dispute')->middleware('throttle:writes');

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
Route::get('users/{user}', [PublicProfileController::class, 'show'])->whereNumber('user')->middleware('throttle:60,1');
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
Route::get('faqs', [FaqController::class, 'index']);
Route::get('site-settings', SiteSettingsController::class);
Route::get('countries', [LocationController::class, 'countries']);
Route::get('countries/{country}/cities', [LocationController::class, 'cities'])->whereNumber('country');
Route::get('plans', [PlanController::class, 'index']);
Route::get('currencies', [OptionsController::class, 'currencies']);
Route::get('languages', [OptionsController::class, 'languages']);

// Called by the payment provider, signed with the shared secret instead of a token.
Route::post('webhooks/payments', PaymentWebhookController::class);
Route::post('webhooks/moyasar', MoyasarWebhookController::class)->middleware('throttle:60,1');

// Signed links: documents and evidence open in <img> tags and new tabs, which cannot send a token.
Route::get('kyc-files/{submission}/{kind}', [AdminKycController::class, 'file'])->whereNumber('submission')->middleware('signed:relative')->name('kyc.file');
Route::get('support-files/{message}', [SupportController::class, 'attachment'])->whereNumber('message')->middleware('signed:relative')->name('support.attachment');
Route::get('dispute-files/{message}', [DisputeController::class, 'attachment'])->whereNumber('message')->middleware('signed:relative')->name('disputes.attachment');

// Opened by <img>/<audio> tags, so it is protected by its signature instead of the token header.
Route::get('messages/{message}/attachment', [MessageController::class, 'attachment'])
    ->whereNumber('message')->middleware('signed:relative')->name('messages.attachment');
