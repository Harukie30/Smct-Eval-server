<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UsersEvaluation;
use App\Notifications\EvalNotifications;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

use function Symfony\Component\Clock\now;

class UsersEvaluationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 10);
        $search = $request->input('search');
        $status = $request->input('status');
        $quarter = $request->input('quarter');
        $year = $request->input('year');

        $all_evaluations = UsersEvaluation::with(
            'employee',
            'employee.branches',
            'employee.positions',
            'evaluator',
            'evaluator.branches',
            'evaluator.positions',
            'jobKnowledge',
            'adaptability',
            'qualityOfWorks',
            'teamworks',
            'reliabilities',
            'ethicals',
            'customerServices'
        )
            ->orderBy('id', 'desc')
            ->search($search)
            ->when($status, fn($q)  => $q->where('status', $status))
            ->when(
                $quarter,
                fn($q) =>
                $q->where(
                    fn($sub) =>
                    $sub->where('reviewTypeRegular', $quarter)
                        ->orWhere('reviewTypeProbationary', $quarter)
                )
            )
            ->when($year, fn($q)    => $q->whereYear('created_at', $year))
            ->latest('updated_at')
            ->paginate($perPage);

        return response()->json([
            'evaluations'   =>  $all_evaluations
        ], 200);
    }

    public function getQuarters(User $user)
    {
        $data = UsersEvaluation::where('employee_id', $user->id)
            ->where(function ($q) {
                $q->whereNotNull('reviewTypeProbationary')
                    ->orWhereNotNull('reviewTypeRegular');
            })
            ->whereYear('created_at', Carbon::now()->year)
            ->get(['reviewTypeProbationary', 'reviewTypeRegular']);

        return response()->json([
            'data'      =>  $data,
        ]);
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

    public function store(Request $request, User $user)
    {
        $auth_user_evaluator = Auth::user();


        $validated  = $request->validate([
            //main
            'hireDate'                              => ['required', 'date'],
            'rating'                                => ['required', 'numeric'],
            'coverageFrom'                          => ['required', 'date'],
            'coverageTo'                            => ['required', 'date'],
            'reviewTypeProbationary'                => ['nullable', 'numeric'],
            'reviewTypeRegular'                     => ['nullable', 'string'],
            'reviewTypeOthersImprovement'           => ['nullable', 'boolean'],
            'reviewTypeOthersCustom'                => ['nullable', 'string'],
            'priorityArea1'                         => ['nullable', 'string'],
            'priorityArea2'                         => ['nullable', 'string'],
            'priorityArea3'                         => ['nullable', 'string'],
            'remarks'                               => ['nullable', 'string'],
            //jobKnowledge
            'jobKnowledgeScore1'                    => ['required', 'numeric'],
            'jobKnowledgeScore2'                    => ['required', 'numeric'],
            'jobKnowledgeScore3'                    => ['required', 'numeric'],
            'jobKnowledgeComments1'                 => ['nullable', 'string'],
            'jobKnowledgeComments2'                 => ['nullable', 'string'],
            'jobKnowledgeComments3'                 => ['nullable', 'string'],
            //qualityOfWork
            'qualityOfWorkScore1'                   => ['required', 'numeric'],
            'qualityOfWorkScore2'                   => ['required', 'numeric'],
            'qualityOfWorkScore3'                   => ['required', 'numeric'],
            'qualityOfWorkScore4'                   => ['required', 'numeric'],
            'qualityOfWorkScore5'                   => ['required', 'numeric'],
            'qualityOfWorkComments1'                => ['nullable', 'string'],
            'qualityOfWorkComments2'                => ['nullable', 'string'],
            'qualityOfWorkComments3'                => ['nullable', 'string'],
            'qualityOfWorkComments4'                => ['nullable', 'string'],
            'qualityOfWorkComments5'                => ['nullable', 'string'],
            //adaptability
            'adaptabilityScore1'                    => ['required', 'numeric'],
            'adaptabilityScore2'                    => ['required', 'numeric'],
            'adaptabilityScore3'                    => ['required', 'numeric'],
            'adaptabilityComments1'                 => ['nullable', 'string'],
            'adaptabilityComments2'                 => ['nullable', 'string'],
            'adaptabilityComments3'                 => ['nullable', 'string'],
            //teamwork
            'teamworkScore1'                        => ['required', 'numeric'],
            'teamworkScore2'                        => ['required', 'numeric'],
            'teamworkScore3'                        => ['required', 'numeric'],
            'teamworkComments1'                     => ['nullable', 'string'],
            'teamworkComments2'                     => ['nullable', 'string'],
            'teamworkComments3'                     => ['nullable', 'string'],
            //reliability
            'reliabilityScore1'                     => ['required', 'numeric'],
            'reliabilityScore2'                     => ['required', 'numeric'],
            'reliabilityScore3'                     => ['required', 'numeric'],
            'reliabilityScore4'                     => ['required', 'numeric'],
            'reliabilityComments1'                  => ['nullable', 'string'],
            'reliabilityComments2'                  => ['nullable', 'string'],
            'reliabilityComments3'                  => ['nullable', 'string'],
            'reliabilityComments4'                  => ['nullable', 'string'],
            //ethical
            'ethicalScore1'                         => ['required', 'numeric'],
            'ethicalScore2'                         => ['required', 'numeric'],
            'ethicalScore3'                         => ['required', 'numeric'],
            'ethicalScore4'                         => ['required', 'numeric'],
            'ethicalExplanation1'                   => ['nullable', 'string'],
            'ethicalExplanation2'                   => ['nullable', 'string'],
            'ethicalExplanation3'                   => ['nullable', 'string'],
            'ethicalExplanation4'                   => ['nullable', 'string'],
            //customerService
            'customerServiceScore1'                 => ['required', 'numeric'],
            'customerServiceScore2'                 => ['required', 'numeric'],
            'customerServiceScore3'                 => ['required', 'numeric'],
            'customerServiceScore4'                 => ['required', 'numeric'],
            'customerServiceScore5'                 => ['required', 'numeric'],
            'customerServiceExplanation1'           => ['nullable', 'string'],
            'customerServiceExplanation2'           => ['nullable', 'string'],
            'customerServiceExplanation3'           => ['nullable', 'string'],
            'customerServiceExplanation4'           => ['nullable', 'string'],
            'customerServiceExplanation5'           => ['nullable', 'string'],

        ]);

        $submission  =  UsersEvaluation::create([
            'employee_id'                       =>  $user->id,
            'evaluator_id'                      =>  $auth_user_evaluator->id,
            'hireDate'                          =>  $validated['hireDate'],
            'rating'                            =>  $validated['rating'],
            'coverageFrom'                      =>  $validated['coverageFrom'],
            'coverageTo'                        =>  $validated['coverageTo'],
            'reviewTypeProbationary'            =>  $validated['reviewTypeProbationary'] ?? null,
            'reviewTypeRegular'                 =>  $validated['reviewTypeRegular'] ?? null,
            'reviewTypeOthersImprovement'       =>  $validated['reviewTypeOthersImprovement'] ?? null,
            'reviewTypeOthersCustom'            =>  $validated['reviewTypeOthersCustom'] ?? null,
            'priorityArea1'                     =>  $validated['priorityArea1'] ?? null,
            'priorityArea2'                     =>  $validated['priorityArea2'] ?? null,
            'priorityArea3'                     =>  $validated['priorityArea3'] ?? null,
            'remarks'                           =>  $validated['remarks'] ?? null,
            'evaluatorApprovedAt'               =>  now(),
        ]);

        for ($i = 1; $i <= 3; $i++) {
            $submission->jobKnowledge()->create([
                'users_evaluation_id'       => $submission->id,
                'question_number'           => $i,
                'score'                     => $validated['jobKnowledgeScore' . $i],
                'comment'                   => $validated['jobKnowledgeComments' . $i]
            ]);
        }

        for ($i = 1; $i <= 5; $i++) {
            $submission->qualityOfWorks()->create([
                'users_evaluation_id'       => $submission->id,
                'question_number'           => $i,
                'score'                     => $validated['qualityOfWorkScore' . $i],
                'comment'                   => $validated['qualityOfWorkComments' . $i]
            ]);
        }

        for ($i = 1; $i <= 3; $i++) {
            $submission->adaptability()->create([
                'users_evaluation_id'       => $submission->id,
                'question_number'           => $i,
                'score'                     => $validated['adaptabilityScore' . $i],
                'comment'                   => $validated['adaptabilityComments' . $i]
            ]);
        }

        for ($i = 1; $i <= 3; $i++) {
            $submission->teamworks()->create([
                'users_evaluation_id'       => $submission->id,
                'question_number'           => $i,
                'score'                     => $validated['teamworkScore' . $i],
                'comment'                   => $validated['teamworkComments' . $i]
            ]);
        }

        for ($i = 1; $i <= 4; $i++) {
            $submission->reliabilities()->create([
                'users_evaluation_id'       => $submission->id,
                'question_number'           => $i,
                'score'                     => $validated['reliabilityScore' . $i],
                'comment'                   => $validated['reliabilityComments' . $i]
            ]);
        }

        for ($i = 1; $i <= 4; $i++) {
            $submission->ethicals()->create([
                'users_evaluation_id'       => $submission->id,
                'question_number'           => $i,
                'score'                     => $validated['ethicalScore' . $i],
                'explanation'               => $validated['ethicalExplanation' . $i]
            ]);
        }

        for ($i = 1; $i <= 5; $i++) {
            $submission->customerServices()->create([
                'users_evaluation_id'       => $submission->id,
                'question_number'           => $i,
                'score'                     => $validated['customerServiceScore' . $i],
                'explanation'               => $validated['customerServiceExplanation' . $i]
            ]);
        }
        //notification for employee
        $user->notify(new EvalNotifications(
            "An evaluation submitted by " . $auth_user_evaluator->fname . ' ' . $auth_user_evaluator->lname . " is awaiting your approval.",
        ));

        //notification for admin and hr
        $notificationData =  new EvalNotifications(
            "A new evaluation submitted for " . $user->fname . ' ' . $user->lname . " was submitted by " . $auth_user_evaluator->fname . ' ' . $auth_user_evaluator->lname,
        );

        User::with('roles')
            ->whereHas(
                'roles',
                fn($q)
                =>
                $q->where('name', 'hr')->orWhere('name', 'admin')
            )
            ->chunk(
                100,
                function ($hrs) use ($notificationData) {
                    Notification::send($hrs, $notificationData);
                }
            );

        return response()->json([
            'message'       =>  'Submitted Successfully'
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(UsersEvaluation $usersEvaluation)
    {
        $user_eval = $usersEvaluation->load(
            'employee',
            'employee.branches',
            'employee.positions',
            'evaluator',
            'evaluator.branches',
            'evaluator.positions',
            'jobKnowledge',
            'adaptability',
            'qualityOfWorks',
            'teamworks',
            'reliabilities',
            'ethicals',
            'customerServices'
        );

        return response()->json([
            'user_eval'         =>   $user_eval
        ], 200);
    }

    public function getMyEvalAuthEmployee(Request $request)
    {
        $perPage = $request->input('per_page', 10);
        $search = $request->input('search');
        $status = $request->input('status');
        $quarter = $request->input('quarter');
        $year = $request->input('year');

        $user = Auth::user();
        $user_eval = UsersEvaluation::with([
            'employee',
            'employee.branches',
            'employee.positions',
            'evaluator',
            'evaluator.branches',
            'evaluator.positions',
            'jobKnowledge',
            'adaptability',
            'qualityOfWorks',
            'teamworks',
            'reliabilities',
            'ethicals',
            'customerServices'
        ])
            ->orderBy('id', 'desc')
            ->where('employee_id', $user->id)
            ->search($search)
            ->when($status,  fn($q) =>  $q->where('status', $status))
            ->when(
                $quarter,
                fn($q)
                =>
                $q->where(function ($subq) use ($quarter) {
                    $subq->where('reviewTypeProbationary', $quarter)
                        ->orWhere('reviewTypeRegular', $quarter);
                })
            )
            ->when($year,    fn($q) =>  $q->whereYear('created_at', $year))
            ->latest('updated_at')
            ->paginate($perPage);

        $years = UsersEvaluation::selectRaw("YEAR(created_at) as year")
            ->groupBy('year')
            ->where('employee_id', $user->id)
            ->latest('updated_at')
            ->get();

        return response()->json([
            'myEval_as_Employee'         =>   $user_eval,
            'years'                      =>   $years
        ], 200);
    }


    public function getEvalAuthEvaluator(Request $request)
    {
        $perPage = $request->input('per_page', 10);
        $search = $request->input('search');
        $status = $request->input('status');
        $quarter = $request->input('quarter');
        $year = $request->input('year');

        $user = Auth::user();
        $user_eval = UsersEvaluation::with(
            'employee',
            'employee.branches',
            'employee.positions',
            'evaluator',
            'evaluator.branches',
            'evaluator.positions',
            'jobKnowledge',
            'adaptability',
            'qualityOfWorks',
            'teamworks',
            'reliabilities',
            'ethicals',
            'customerServices'
        )
            ->orderBy('id', 'desc')
            ->where('evaluator_id', $user->id)
            ->search($search)
            ->when($status, fn($q) =>  $q->where('status', $status))
            ->when(
                $quarter,
                fn($q)
                =>
                $q->where(function ($subq) use ($quarter) {
                    $subq->where('reviewTypeProbationary', $quarter)
                        ->orWhere('reviewTypeRegular', $quarter);
                })
            )
            ->when($year,   fn($q) =>  $q->whereYear('created_at', $year))
            ->latest('updated_at')
            ->paginate($perPage);

        return response()->json([
            'myEval_as_Evaluator'         =>   $user_eval
        ], 200);
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
    public function approvedByEmployee(UsersEvaluation $usersEvaluation)
    {
        $usersEvaluation->update([
            'status'                => 'completed',
            'employeeApprovedAt'    => now()
        ]);

        $evaluator = User::findOrFail($usersEvaluation->evaluator_id);
        $auth_user_employee = Auth::user();


        $evaluator->notify(new EvalNotifications(
            "Your submitted evaluation for " . $auth_user_employee->fname . ' ' . $auth_user_employee->lname . " has been successfully approved.",
        ));

        $notificationData =  new EvalNotifications(
            "An evaluation submitted by " . $evaluator->fname . ' ' . $evaluator->lname . " for " . $auth_user_employee->fname . ' ' . $auth_user_employee->lname . " has been approved ",
        );

        User::with('roles')
            ->whereHas(
                'roles',
                fn($q)
                =>
                $q->where('name', 'hr')->orWhere('name', 'admin')
            )
            ->chunk(
                100,
                function ($hrs) use ($notificationData) {
                    Notification::send($hrs, $notificationData);
                }
            );

        return response()->json([
            'message'       => 'Evaluation approved by employee successfully'
        ], 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(UsersEvaluation $usersEvaluation)
    {
        $usersEvaluation->delete();

        return response()->json([
            'message'       => 'Deleted Successfully'
        ], 200);
    }

    public function getAllYears()
    {
        $years = UsersEvaluation::select(DB::raw("YEAR(created_at) as year"))
            ->distinct()
            ->orderBy('year', 'DESC')
            ->get();

        return response()->json([
            "years" => $years
        ]);
    }
}
