<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CustomerActivityController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\LeadSourceController;
use App\Http\Controllers\Api\PropertyController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\ViewingController;
use App\Http\Controllers\Api\CustomerDealController;
use App\Http\Controllers\Api\CustomerLossController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\RevenueAnalyticsController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\CustomerReportController;
use App\Http\Controllers\Api\RevenueReportController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AssignmentStatusReportController;
use App\Http\Controllers\Api\PerformanceScoreController;

Route::post('/login', [AuthController::class, 'login']);
Route::get('/lead-sources', [LeadSourceController::class, 'index']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/customers', [CustomerController::class, 'index']);
    Route::post('/customers', [CustomerController::class, 'store']);
    Route::get('/customers/{customer}', [CustomerController::class, 'show']);
    Route::put('/customers/{customer}', [CustomerController::class, 'update']);
    Route::put('/customers/{customer}/status', [CustomerController::class, 'updateStatus']);
    Route::post('/customers/{customer}/assign', [CustomerController::class, 'assign']);
    Route::put('/customers/{customer}/requirement', [CustomerController::class, 'updateRequirement']);
    Route::post('/customers/{customer}/add-support-sale', [CustomerController::class, 'addSupportSale']);
    Route::post('/customers/{customer}/change-primary-sale', [CustomerController::class, 'changePrimarySale']);

    Route::post('/customers/{customer}/activities', [CustomerActivityController::class, 'store']);
    Route::post('/customers/{customer}/viewings', [ViewingController::class, 'store']);
    Route::put('/customers/{customer}/toggle-priority', [CustomerController::class, 'togglePriority']);

    Route::post('/customers/{customer}/close-deal', [CustomerDealController::class, 'store']);
    Route::get('/customers/{customer}/deal', [CustomerDealController::class, 'show']);
    Route::put('/customers/{customer}/deal', [CustomerDealController::class, 'update']);

    Route::get('/customer-deals', [CustomerDealController::class, 'index']);
    Route::get('/customer-deals/{deal}', [CustomerDealController::class, 'detail']);
    Route::post('/customer-deals/{deal}/create-new-customer', [CustomerDealController::class, 'createNewCustomer']);

    Route::get('/customer-losses', [CustomerLossController::class, 'index']);
    Route::get('/customer-losses/{loss}', [CustomerLossController::class, 'detail']);
    Route::post('/customer-losses/{loss}/create-new-customer', [CustomerLossController::class, 'createNewCustomer']);

    Route::post('/customers/{customer}/mark-lost', [CustomerLossController::class, 'store']);
    Route::get('/customers/{customer}/loss', [CustomerLossController::class, 'show']);
    Route::put('/customers/{customer}/loss', [CustomerLossController::class, 'update']);

    Route::get('/properties', [PropertyController::class, 'index']);
    Route::post('/properties', [PropertyController::class, 'store']);

    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::put('/users/{user}', [UserController::class, 'update']);
    Route::put('/users/{user}/toggle-status', [UserController::class, 'toggleStatus']);

    Route::get('/profile', [UserController::class, 'profile']);
    Route::post('/profile', [UserController::class, 'updateProfile']);

    Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
    Route::get('/dashboard/revenue-daily', [DashboardController::class, 'revenueDaily']);
    Route::get('/dashboard/revenue-by-sale', [DashboardController::class, 'revenueBySale']);
    Route::get('/dashboard/customers-by-sale', [DashboardController::class, 'customersBySale']);
    Route::get('/dashboard/pipeline-result', [DashboardController::class, 'pipelineResult']);
    Route::get('/dashboard/top-sale', [DashboardController::class, 'topSale']);
    Route::get('/dashboard/my-rank', [DashboardController::class, 'myRank']);
    Route::get('/dashboard/ranking-sale', [DashboardController::class, 'rankingSale']);
    Route::get('/dashboard/conversion-by-sale', [DashboardController::class, 'conversionBySale']);
    Route::get('/dashboard/source-performance', [DashboardController::class, 'sourcePerformance']);
    Route::get('/dashboard/building-performance', [DashboardController::class, 'buildingPerformance']);
    Route::get('/dashboard/recycle-leads', [DashboardController::class, 'recycleLeads']);
    Route::get('/dashboard/aging-pipeline', [DashboardController::class, 'agingPipeline']);
    Route::get('/dashboard/assigned-current', [DashboardController::class, 'assignedCurrent']);
    Route::get('/dashboard/assigned-in-period', [DashboardController::class, 'assignedInPeriod']);

    Route::get('/analytics/revenue/summary', [RevenueAnalyticsController::class, 'summary']);
    Route::get('/analytics/revenue/trend', [RevenueAnalyticsController::class, 'trend']);
    Route::get('/analytics/revenue/by-sale', [RevenueAnalyticsController::class, 'bySale']);
    Route::get('/analytics/revenue/by-building', [RevenueAnalyticsController::class, 'byBuilding']);
    Route::get('/analytics/revenue/by-source', [RevenueAnalyticsController::class, 'bySource']);
    Route::get('/analytics/revenue/top-deals', [RevenueAnalyticsController::class, 'topDeals']);

    Route::get('/reports/revenue/summary', [ReportController::class, 'revenueSummary']);
    Route::get('/reports/revenue/by-period', [ReportController::class, 'revenueByPeriod']);
    Route::get('/reports/revenue/by-sale', [ReportController::class, 'revenueBySale']);

    Route::prefix('reports/revenue-new')->group(function () {
        Route::get('/summary', [RevenueReportController::class, 'summary']);
        Route::get('/deals', [RevenueReportController::class, 'deals']);
    });

    Route::get('/reports/customers/summary', [ReportController::class, 'customerSummary']);
    Route::get('/reports/customers/by-status', [ReportController::class, 'customerByStatus']);
    Route::get('/reports/customers/by-sale', [ReportController::class, 'customerBySale']);
   
    Route::prefix('reports/customers')->group(function () {
        Route::get('/summary', [CustomerReportController::class, 'summary']);
        Route::get('/pipeline', [CustomerReportController::class, 'pipeline']);
        Route::get('/by-sale', [CustomerReportController::class, 'bySale']);
        Route::get('/assigned-in-period', [CustomerReportController::class, 'assignedInPeriod']);
        Route::get('/conversion-by-sale', [CustomerReportController::class, 'conversionBySale']);
        Route::get('/warning', [CustomerReportController::class, 'warning']);
        Route::get('/aging', [CustomerReportController::class, 'aging']);
        Route::get('/tabs', [CustomerReportController::class, 'tabData']);
    });

    Route::prefix('reports/assignment-status')->group(function () {
        Route::get('/summary', [AssignmentStatusReportController::class, 'summary']);
        Route::get('/detail', [AssignmentStatusReportController::class, 'detail']);
    });
    
    Route::prefix('reports/performance-score')->group(function () {
        Route::get('/', [PerformanceScoreController::class, 'index']);
    });
    
});