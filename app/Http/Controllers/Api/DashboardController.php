<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\DashboardService;
use Illuminate\Support\Facades\Auth;
use Throwable;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboardService
    ) {}

    public function summary()
    {
        try {
            $userId = (int) Auth::id();
            $data = $this->dashboardService->summary($userId);

            return ApiResponse::json(true, 'Dashboard summary fetched successfully.', $data);
        } catch (Throwable) {
            return ApiResponse::json(false, 'Failed to fetch dashboard summary.', [], 500);
        }
    }
}
