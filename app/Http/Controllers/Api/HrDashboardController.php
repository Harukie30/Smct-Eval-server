<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UsersEvaluation;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

use function Symfony\Component\Clock\now;

class HrDashboardController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // totals
        $new_eval = UsersEvaluation::where('status', 'pending')
            ->where('evaluatorApprovedAt', '>=', Carbon::now()->subHours(24))
            ->whereNotNull('rating')
            ->count() ?? 0;

        $pending_eval = UsersEvaluation::where('status', 'pending')->whereNotNull('rating')->count() ?? 0;
        $completed_eval = UsersEvaluation::where('status', 'completed')->whereNotNull('rating')->count() ?? 0;
        $employees = User::where('is_active', 'active')->count();

        return response()->json([
            'new_eval'            => $new_eval,
            'pending_eval'        => $pending_eval,
            'completed_eval'      => $completed_eval,
            'total_users'         => $employees
        ], 200);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
