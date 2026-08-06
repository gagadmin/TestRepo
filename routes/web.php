<?php

use App\Http\Controllers\AdminIdentityController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AdvancedAnalyticsController;
use App\Http\Controllers\AiConversationController;
use App\Http\Controllers\AiToolController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReportScheduleController;
use App\Http\Controllers\SecurityDashboardController;
use App\Http\Controllers\SeoInsightsController;
use App\Http\Controllers\TwoFactorController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'app')->name('app');

Route::middleware('guest')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:6,1')
        ->name('login');

    // Second-factor challenge. Reachable while unauthenticated because the
    // session holds only an unprivileged pending record at this point.
    // Throttled more tightly than the password step: the code space is small.
    Route::post('/auth/two-factor', [AuthController::class, 'twoFactorChallenge'])
        ->middleware('throttle:10,1')
        ->name('two-factor.challenge');

    Route::post('/auth/two-factor/cancel', [AuthController::class, 'cancelTwoFactorChallenge'])
        ->name('two-factor.cancel');
});

Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/api/bootstrap', [PlatformController::class, 'bootstrap'])
        ->name('platform.bootstrap');

    /*
     * Enrolment and password management sit OUTSIDE the mfa/password.current
     * gates by design: they are the escape hatch a confined session uses to
     * satisfy the requirement. Their own middleware list is deliberately short.
     */
    Route::prefix('/api/two-factor')->name('two-factor.')->group(function () {
        Route::get('/', [TwoFactorController::class, 'status'])->name('status');
        Route::post('/setup', [TwoFactorController::class, 'setup'])
            ->middleware('throttle:10,1')->name('setup');
        Route::post('/confirm', [TwoFactorController::class, 'confirm'])
            ->middleware('throttle:10,1')->name('confirm');
        Route::post('/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])
            ->middleware('throttle:5,1')->name('recovery-codes');
        Route::delete('/', [TwoFactorController::class, 'disable'])
            ->middleware('throttle:5,1')->name('disable');
    });

    Route::prefix('/api/account/password')->name('password.')->group(function () {
        Route::get('/policy', [PasswordController::class, 'policy'])->name('policy');
        Route::put('/', [PasswordController::class, 'update'])
            ->middleware('throttle:5,1')->name('update');
    });
});

/*
 * Everything below additionally requires an enrolled second factor and a
 * current password. Applied as a group rather than per route so a newly added
 * endpoint is protected by default.
 */
Route::middleware(['auth', 'active', 'mfa', 'password.current'])->group(function () {
    Route::prefix('/api/admin')->name('admin.')->group(function () {
        Route::get('/users', [AdminUserController::class, 'index'])
            ->middleware('permission:users.view')->name('users.index');
        Route::post('/users', [AdminUserController::class, 'store'])
            ->middleware(['permission:users.manage', 'throttle:20,1'])->name('users.store');
        Route::put('/users/{user}', [AdminUserController::class, 'update'])
            ->whereNumber('user')
            ->middleware('permission:users.manage')->name('users.update');
        Route::get('/audit', [AuditLogController::class, 'index'])
            ->middleware('permission:audit.view')->name('audit.index');

        // Identity recovery. Requires users.manage because each action relaxes
        // a security control for one account.
        Route::middleware('permission:users.manage')->group(function () {
            Route::post('/users/{user}/unlock', [AdminIdentityController::class, 'unlock'])
                ->whereNumber('user')->name('users.unlock');
            Route::post('/users/{user}/reset-two-factor', [AdminIdentityController::class, 'resetTwoFactor'])
                ->whereNumber('user')->name('users.reset-two-factor');
            Route::post('/users/{user}/require-password-change', [AdminIdentityController::class, 'requirePasswordChange'])
                ->whereNumber('user')->name('users.require-password-change');
        });
    });

    Route::prefix('/api/integrations')
        ->middleware('permission:integrations.manage')
        ->name('integrations.')
        ->group(function () {
            Route::get('/', [IntegrationController::class, 'index'])->name('index');
            Route::post('/', [IntegrationController::class, 'store'])->name('store');
            Route::post('/search-console/test', [IntegrationController::class, 'testSearchConsole'])
                ->middleware('throttle:5,1')
                ->name('search-console.test');
            Route::put('/{dataSource}', [IntegrationController::class, 'update'])->name('update');
            Route::post('/{dataSource}/test', [IntegrationController::class, 'test'])->name('test');
            Route::get('/{dataSource}/preview', [IntegrationController::class, 'preview'])
                ->middleware('throttle:10,1')
                ->name('preview');
            Route::delete('/{dataSource}', [IntegrationController::class, 'destroy'])->name('destroy');
        });

    Route::prefix('/api/ai')
        ->middleware('permission:ai.chat')
        ->name('ai.')
        ->group(function () {
            Route::get('/status', [AiConversationController::class, 'status'])->name('status');
            Route::get('/conversations', [AiConversationController::class, 'index'])->name('conversations.index');
            Route::get('/conversations/{conversation}', [AiConversationController::class, 'show'])->name('conversations.show');
            Route::delete('/conversations/{conversation}', [AiConversationController::class, 'destroy'])->name('conversations.destroy');
            Route::post('/chat', [AiConversationController::class, 'chat'])
                ->middleware('throttle:20,1')
                ->name('chat');

            // Any ai.chat user may report a wrong answer. It stays pending until
            // an administrator approves it, so reporting cannot steer answers.
            Route::post('/corrections', [AiConversationController::class, 'reportCorrection'])
                ->middleware('throttle:10,1')
                ->name('corrections.report');
        });

    /*
     * AI capability administration.
     *
     * Governs which data sources the assistant can read and which corrections
     * shape its answers, so it carries integrations.manage and is fully audited.
     */
    Route::prefix('/api/admin/ai-tools')
        ->middleware('permission:integrations.manage')
        ->name('ai-tools.')
        ->group(function () {
            Route::get('/', [AiToolController::class, 'index'])->name('index');
            Route::post('/', [AiToolController::class, 'store'])->name('store');
            Route::put('/{tool}', [AiToolController::class, 'update'])
                ->whereNumber('tool')->name('update');
            Route::patch('/{tool}/toggle', [AiToolController::class, 'toggle'])
                ->whereNumber('tool')->name('toggle');
            Route::delete('/{tool}', [AiToolController::class, 'destroy'])
                ->whereNumber('tool')->name('destroy');

            Route::get('/failures', [AiToolController::class, 'failures'])->name('failures');
            Route::post('/failures/{failure}/resolve', [AiToolController::class, 'resolveFailure'])
                ->whereNumber('failure')->name('failures.resolve');

            Route::get('/corrections', [AiToolController::class, 'corrections'])->name('corrections');
            Route::post('/corrections/{correction}/review', [AiToolController::class, 'reviewCorrection'])
                ->whereNumber('correction')->name('corrections.review');
        });

    Route::prefix('/api/dashboards')
        ->middleware('permission:dashboards.view')
        ->name('dashboards.')
        ->group(function () {
            Route::get('/', [DashboardController::class, 'index'])->name('index');
            Route::get('/search-console', [DashboardController::class, 'searchConsole'])
                ->middleware('throttle:20,1')
                ->name('search-console');
            Route::get('/freshservice', [DashboardController::class, 'freshservice'])
                ->middleware('throttle:10,1')
                ->name('freshservice');
            Route::get('/{slug}', [DashboardController::class, 'show'])->name('show');
        });

    Route::prefix('/api/reports')
        ->middleware('permission:reports.view')
        ->name('reports.')
        ->group(function () {
            Route::get('/', [ReportController::class, 'index'])->name('index');
            Route::post('/', [ReportController::class, 'store'])
                ->middleware('permission:reports.create')->name('store');
            Route::get('/{report}', [ReportController::class, 'show'])
                ->whereNumber('report')->name('show');
            Route::put('/{report}', [ReportController::class, 'update'])
                ->whereNumber('report')->middleware('permission:reports.create')->name('update');
            Route::post('/{report}/generate', [ReportController::class, 'generate'])
                ->whereNumber('report')->middleware(['permission:reports.create', 'throttle:10,1'])->name('generate');
            Route::get('/{report}/export/{format}', [ReportController::class, 'export'])
                ->whereNumber('report')->whereIn('format', ['xlsx', 'pdf'])
                ->middleware('throttle:30,1')->name('export');
        });

    Route::prefix('/api/schedules')
        ->middleware(['permission:reports.schedule'])
        ->name('schedules.')
        ->group(function () {
            Route::get('/', [ReportScheduleController::class, 'index'])->name('index');
            Route::post('/', [ReportScheduleController::class, 'store'])->name('store');
            Route::put('/{schedule}', [ReportScheduleController::class, 'update'])
                ->whereNumber('schedule')->name('update');
            Route::delete('/{schedule}', [ReportScheduleController::class, 'destroy'])
                ->whereNumber('schedule')->name('destroy');
            Route::post('/{schedule}/run', [ReportScheduleController::class, 'runNow'])
                ->whereNumber('schedule')->middleware('throttle:5,1')->name('run');
        });

    // Security telemetry requires BOTH the security.view permission and
    // membership of the IT department or a privileged security role.
    Route::prefix('/api/security')
        ->middleware(['permission:security.view', 'security.access'])
        ->name('security.')
        ->group(function () {
            Route::get('/', [SecurityDashboardController::class, 'index'])
                ->middleware('throttle:30,1')->name('index');
            Route::get('/events', [SecurityDashboardController::class, 'events'])
                ->middleware('throttle:30,1')->name('events');
            Route::put('/events/{event}', [SecurityDashboardController::class, 'updateEvent'])
                ->whereNumber('event')
                ->middleware('permission:security.manage')->name('events.update');
            Route::post('/scan', [SecurityDashboardController::class, 'scan'])
                ->middleware(['permission:security.manage', 'throttle:5,1'])->name('scan');
        });

    Route::prefix('/api/analytics')
        ->middleware('permission:analytics.view')
        ->name('analytics.')
        ->group(function () {
            Route::get('/', [AdvancedAnalyticsController::class, 'index'])->name('index');
            Route::post('/reports/{report}', [AdvancedAnalyticsController::class, 'generate'])
                ->whereNumber('report')
                ->middleware(['permission:analytics.run', 'throttle:10,1'])
                ->name('generate');
        });

    /*
     * SEO insights. Deterministic Search Console analysis and per-property
     * category/region profiles. Read-only; each property is additionally checked
     * for the caller's visibility inside the controller.
     */
    Route::prefix('/api/seo')
        ->middleware('permission:seo.view')
        ->name('seo.')
        ->group(function () {
            Route::get('/', [SeoInsightsController::class, 'index'])->name('index');
            Route::get('/{dataSource}', [SeoInsightsController::class, 'show'])
                ->whereNumber('dataSource')
                ->middleware('throttle:30,1')
                ->name('show');
            Route::put('/{dataSource}/profile', [SeoInsightsController::class, 'saveProfile'])
                ->whereNumber('dataSource')
                ->name('profile.update');

            Route::get('/{dataSource}/action-plans', [SeoInsightsController::class, 'actionPlans'])
                ->whereNumber('dataSource')
                ->name('action-plans');
            Route::post('/{dataSource}/action-plan', [SeoInsightsController::class, 'generateActionPlan'])
                ->whereNumber('dataSource')
                ->middleware('throttle:10,1')
                ->name('action-plan.generate');

            Route::get('/{dataSource}/research', [SeoInsightsController::class, 'research'])
                ->whereNumber('dataSource')
                ->name('research');
            Route::post('/{dataSource}/research', [SeoInsightsController::class, 'generateResearch'])
                ->whereNumber('dataSource')
                ->middleware('throttle:5,1')
                ->name('research.generate');
        });
});

Route::fallback([PlatformController::class, 'app']);
