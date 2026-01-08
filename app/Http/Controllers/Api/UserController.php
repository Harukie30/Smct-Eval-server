<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Notifications\EvalNotifications;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class UserController extends Controller
{

    //Create

    public function registerUser(Request $request)
    {
        $validate = $request->validate([
            'fname'                     => ['required', 'string'],
            'lname'                     => ['required', 'string'],
            'email'                     => ['required', Rule::unique('users', 'email'), 'email', 'string', 'lowercase'],
            'position_id'               => ['required', Rule::exists('positions', 'id')],
            'branch_id'                 => ['required', Rule::exists('branches', 'id')],
            'department_id'             => ['nullable', Rule::exists('departments', 'id')],
            'signature'                 => ['required'],
            'employee_id'               => ['required', Rule::unique('users', 'emp_id')],
            'username'                  => ['required', 'string', 'lowercase', Rule::unique('users', 'username')],
            'contact'                   => ['required', 'string'],
            'password'                  => ['required', 'string', 'min: 8', 'max:20']
        ]);

        //file handling | storing
        if ($request->hasFile('signature')) {
            $signature = $request->file('signature');

            $name = time() . '-' . $validate['username'] . '.' . $signature->getClientOriginalExtension();

            $path = $signature->storeAs('user-signatures', $name, 'public');
        } else {
            return response()->json([
                'message'       => 'Signature not found or invalid file.'
            ], 400);
        }

        $user = User::create([
            'fname'                     => $validate['fname'],
            'lname'                     => $validate['lname'],
            'email'                     => $validate['email'],
            'position_id'               => $validate['position_id'],
            'department_id'             => $validate['department_id'],
            'signature'                 => $path ?? null,
            'emp_id'                    => $validate['employee_id'],
            'username'                  => $validate['username'],
            'contact'                   => $validate['contact'],
            'password'                  => $validate['password']
        ]);

        $user->assignRole('employee');
        $user->branches()->sync($validate['branch_id']);

        //notification for admin and hr
        $notificationData =  new EvalNotifications(
            "New user registration: " . $user->fname . " " . $user->lname,
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
            "message"       => "Registered Successfully",
        ], 200);
    }

    public function store(Request $request)
    {
        $validate = $request->validate([
            'fname'                     => ['required', 'string'],
            'lname'                     => ['required', 'string'],
            'email'                     => ['required', Rule::unique('users', 'email'), 'email', 'string', 'lowercase'],
            'position_id'               => ['required', Rule::exists('positions', 'id')],
            'branch_id'                 => ['required', Rule::exists('branches', 'id')],
            'department_id'             => ['nullable', Rule::exists('departments', 'id')],
            'employee_id'               => ['required', Rule::unique('users', 'emp_id')],
            'username'                  => ['required', 'string', 'lowercase', Rule::unique('users', 'username')],
            'contact'                   => ['required', 'string'],
            'password'                  => ['required', 'string', 'min: 8', 'max:20'],
            'role_id'                   => ['required', Rule::exists('roles', 'id')]
        ]);

        $user = User::create([
            'fname'                     => $validate['fname'],
            'lname'                     => $validate['lname'],
            'email'                     => $validate['email'],
            'position_id'               => $validate['position_id'],
            'department_id'             => $validate['department_id'] ?? null,
            'emp_id'                    => $validate['employee_id'],
            'username'                  => $validate['username'],
            'contact'                   => $validate['contact'],
            'password'                  => $validate['password'],
            'is_active'                 => 'active'
        ]);

        $user->assignRole($validate['role_id']);
        $user->branches()->sync($validate['branch_id']);

        return response()->json([
            "message"       => "Registered Successfully",
        ], 200);
    }


    //Auth
    public function userLogin(Request $request)
    {

        $request->validate([
            'email' => ['required', 'string', 'lowercase'],
            'password' => ['required', 'string'],
        ]);

        $user = User::whereAny(['username', 'email'], $request->email)->first();

        if (!$user) {
            return response()->json([
                'message' => 'Username or email not found'
            ], 404);
        }

        if ($user->is_active === "pending") {
            return response()->json([
                "message"   => "Your account is not activated yet. Please wait for admin to approve."
            ], 401);
        }

        if ($user->is_active === "declined") {
            return response()->json([
                "message"   => "Your account has been rejected."
            ], 401);
        }

        $credentials = [
            'username' => !filter_var($request->email, FILTER_VALIDATE_EMAIL) ? $request->email : $user->username,
            'password' => $request->password
        ];

        if (!Auth::attempt($credentials)) {
            return response()->json([
                "status"    => false,
                "message"   => "Email and password do not match our records"
            ], 400);
        }

        $role = $user->getRoleNames();

        return response()->json([
            "role"    => $role,
            "status"  => true,
            "message" => "Login successful. Redirecting you to Dashboard"
        ], 200);
    }

    //Read
    public function getAllUsers(Request $request)
    {
        $search_filter = $request->input('search');
        $department_filter = $request->input('department');
        $branch_filter = $request->input('branch');

        $users  = User::with([
            'branches',
            'departments',
            'positions',
            'evaluations',
            'doesEvaluated',
            'roles'
        ])
            ->search($search_filter)
            ->when(
                $department_filter,
                fn($q)
                =>
                $q->where('department_id', $department_filter)
            )
            ->when(
                $branch_filter,
                function ($q) use ($branch_filter) {
                    $q->whereHas(
                        'branches',
                        function ($subq) use ($branch_filter) {
                            $subq->where('branch_id', $branch_filter);
                        }
                    );
                }
            )
            ->whereNot('id', Auth::id())
            ->latest('updated_at')
            ->get();

        return response()->json([
            'message'       => 'Users fetched successfully',
            'users'         => $users
        ], 200);
    }


    public function getAllPendingUsers(Request $request)
    {
        $perPage = $request->input('per_page', 10);
        $search_filter = $request->input('search');
        $status_filter = $request->input('status');

        $pending_users  = User::with('positions', 'branches', 'departments', 'roles')
            ->whereNot('is_active', "active")
            ->whereNot('id', Auth::id())
            ->when(
                $status_filter,
                fn($status)
                =>
                $status->where('is_active', '=', $status_filter)
            )
            ->search($search_filter)
            ->latest('updated_at')
            ->paginate($perPage);

        return response()->json([
            'user_status'       => $status_filter,
            'message'      => 'ok',
            'users'        => $pending_users
        ], 200);
    }


    public function getAllActiveUsers(Request $request)
    {
        $perPage = $request->input('per_page', 10);
        $role_filter = $request->input('role');
        $search_filter = $request->input('search');
        $branch_filter = $request->input('branch');
        $department_filter = $request->input('department');

        $users  = User::with('branches', 'departments', 'positions', 'roles')
            ->where('is_active', "active")
            ->whereNot('id', Auth::id())
            ->when(
                $role_filter,
                fn($role)
                =>
                $role->whereRelation('roles', 'id', $role_filter)
            )
            ->when(
                $branch_filter,
                fn($q)
                =>
                $q->whereRelation('branches', 'branches.id', $branch_filter)
            )
            ->when(
                $department_filter,
                fn($q)
                =>
                $q->whereRelation('departments', 'departments.id', $department_filter)
            )
            ->search($search_filter)
            ->latest('updated_at')
            ->paginate($perPage);

        return response()->json([
            'message'   => 'ok',
            'users'     => $users
        ], 200);
    }


    public function showUser(User $user)
    {
        $shownUser = $user->load(
            'branches',
            'departments',
            'positions',
            'evaluations',
            'doesEvaluated',
            'roles'
        );
        return response()->json([
            'data'  =>  $shownUser
        ], 200);
    }

    public function getAllBranchHeads(Request $request)
    {
        $search  = $request->input('search');
        $users = User::with([
            'branches',
            'departments',
            'positions',
            'roles'
        ])
            ->where('is_active', "active")
            ->search($search)
            ->whereIn('position_id', [35, 36, 37, 38]) // <--- all branch_manager/supervisor position id
            ->latest('updated_at')
            ->get();

        return response()->json([
            'branch_heads'      =>  $users
        ], 200);
    }

    public function getAllAreaManager(Request $request)
    {
        $search  = $request->input('search');
        $users = User::with([
            'branches',
            'departments',
            'positions',
            'roles'
        ])
            ->where('is_active', "active")
            ->search($search)
            ->where('position_id', 16)
            ->latest('updated_at')
            ->get();

        return response()->json([
            'branch_heads'      =>  $users
        ], 200);
    }

    public function getAllSignatureRequest(Request $request)
    {
        $search = $request->input("search");
        $users = User::with(
            'branches',
            'departments',
            'positions',
        )
            ->where('requestSignatureReset', true)
            ->whereNot('approvedSignatureReset', true)
            ->search($search)
            ->latest('updated_at')
            ->get();

        return response()->json([
            'users'      =>  $users
        ], 200);
    }


    //applicable for area manager / branch manager/supervisor /department manager
    public function getAllEmployeeByAuth(Request $request)
    {
        $manager = Auth::user();

        $search  = $request->input('search');
        $position_filter = $request->input('position_filter');
        $perPage = $request->input('per_page', 10);



        //first test if it is manager
        $isManagerOrSupervisor =
            $manager->positions()
            ->where(function ($q) {
                $q->where('label', 'LIKE', '%manager%')
                    ->orWhere('label', 'LIKE', '%supervisor%');
            })
            ->exists();

        if ($isManagerOrSupervisor) {

            $isHO = $manager->branches()->where('branch_id', 126)->exists();

            //area manager
            if (!$isHO && $manager->position_id === 16 && empty($manager->department_id)) {
                $branches = $manager->branches()->pluck('branches.id');

                $branchHeads = User::with('departments', 'branches', 'positions', 'roles')
                    ->where('is_active', "active")
                    ->whereHas(
                        'branches',
                        fn($query)
                        =>
                        $query->whereIn('branch_id', $branches)
                    )
                    ->when(
                        $position_filter,
                        fn($q)
                        =>
                        $q->where('position_id', $position_filter)
                    )
                    ->where('id', "!=", $manager->id)
                    ->whereIn('position_id', [35, 36, 37, 38]) // <--- all branch_manager/supervisor position id
                    ->search($search)
                    ->latest('updated_at')
                    ->paginate($perPage);

                return response()->json([
                    'employees' => $branchHeads
                ], 200);
            }

            //branch manager/supervisor
            if (
                !$isHO
                &&
                (
                    $manager->position_id === 35 ||
                    $manager->position_id === 36 ||
                    $manager->position_id === 37 ||
                    $manager->position_id === 38
                )
                &&
                empty($manager->department_id)
                &&
                $manager->position_id !== 16
            ) {
                $branches = $manager->branches()->pluck('branches.id');

                $employees = User::with('departments', 'branches', 'positions', 'roles')
                    ->where('is_active', "active")
                    ->whereHas(
                        'branches',
                        fn($query)
                        =>
                        $query->whereIn('branch_id', $branches)
                    )
                    ->when(
                        $position_filter,
                        fn($q)
                        =>
                        $q->where('position_id', $position_filter)
                    )
                    ->where('id', "!=", $manager->id)
                    ->where('position_id', "!=", 16) // <--- area manager id
                    ->search($search)
                    ->latest('updated_at')
                    ->paginate($perPage);

                return response()->json([
                    'employees' => $employees
                ], 200);
            }

            //Department manager
            if ($isHO  && !empty($manager->department_id)) {
                $employees = User::with('departments', 'branches', 'positions', "roles")
                    ->where('is_active', "active")
                    ->whereRelation('branches', 'branch_id', 126) //<--- must branch HO
                    ->when(
                        $position_filter,
                        fn($q)
                        =>
                        $q->where('position_id', $position_filter)
                    )
                    ->whereNot('id', $manager->id)
                    ->where('department_id', $manager->department_id) // <--- must the same department
                    ->search($search)
                    ->latest('updated_at')
                    ->paginate($perPage);

                return response()->json([
                    'employees' => $employees
                ], 200);
            }
            return response()->json([
                'message'   => "failed conditions"
            ]);
        }
        return response()->json([
            'error' => 'Auth user is not a manager'
        ], 401);
    }


    //update
    public function updateUser(User $user, Request $request)
    {
        $validate = $request->validate([
            'fname'                     => ['required', 'string', 'alpha'],
            'lname'                     => ['required', 'string', 'alpha'],
            'email'                     => ['required', Rule::unique('users', 'email')->ignore($user->id), 'email', 'string', 'lowercase'],
            'position_id'               => ['required', Rule::exists('positions', 'id')],
            'branch_id'                 => ['required', Rule::exists('branches', 'id')],
            'department_id'             => ['nullable', Rule::exists('departments', 'id')],
            'employeeId'                => ['required'],
            'username'                  => ['required', 'string', 'lowercase', Rule::unique('users', 'username')->ignore($user->id)],
            'contact'                   => ['required', 'string'],
            'roles'                     => ['required', Rule::exists('roles', 'name')],
            'password'                  => ['nullable', 'string', 'min: 8', 'max:20']
        ]);

        $user->syncRoles([$validate['roles']]);
        $user->branches()->sync([$validate['branch_id']]);

        $updateData = [
            'fname'                     => $validate['fname'],
            'lname'                     => $validate['lname'],
            'email'                     => $validate['email'],
            'position_id'               => $validate['position_id'],
            'department_id'             => $validate['department_id'] ?? $user->department_id ?? null,
            'username'                  => $validate['username'],
            'contact'                   => $validate['contact'],
            'contact'                   => $validate['contact'],
            'emp_id'                    => $validate['employeeId'],
        ];

        if ($request->filled("password")) {
            $updateData['password'] = $validate['password'];
        }

        $user->update($updateData);

        return response()->json([
            'message'   => 'Updated Successfully'
        ], 200);
    }


    public function updateProfileUserAuth(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.'
            ], 401);
        }

        $validated = $request->validate([
            'username'                 => ['nullable', 'string'],
            'email'                    => ['nullable', 'email'],
            'current_password'         => ['required', 'current_password:sanctum'],
            'new_password'             => ['nullable', 'required_with:confirm_password'],
            'confirm_password'         => ['nullable', 'required_with:new_password', 'same:new_password'],
        ]);

        $items = [
            'username'                  => $validated['username'] ?? $user->username,
            'email'                     => $validated['email'] ?? $user->email,
        ];


        if ($request->filled('new_password') && $request->filled('confirm_password')) {
            $items["password"] = $validated["confirm_password"];
        }

        //file handling | storing
        if ($request->file('signature')) {
            $signature = $request->file('signature');
            $name = time() . '-' .  $user->username . '.' . $signature->getClientOriginalExtension();
            $path = $signature->storeAs('user-signatures', $name, 'public');

            if ($user->signature) {
                if (Storage::disk('public')->exists($user->signature)) {
                    Storage::disk('public')->delete($user->signature);
                }
            }

            $items['signature'] = $path ?? null;
            $items['requestSignatureReset'] = false;
            $items['approvedSignatureReset'] = false;
        }

        $user->update($items);

        return response()->json([
            "status"        => true,
            "message"       => "Uploaded Successfully",
        ], 201);
    }


    public function requestSignatureReset()
    {
        $user = Auth::user();

        $user->update([
            'requestSignatureReset'     =>  true,
        ]);

        $notificationData =  new EvalNotifications(
            "Signature reset request from: " . $user->fname . " " . $user->lname,
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
            'message'       =>  'Approved'
        ], 201);
    }

    public function approvedSignatureReset(User $user)
    {
        $user->update([
            'approvedSignatureReset'     =>  true,
        ]);
        $user->notify(new EvalNotifications("Your signature reset request has been approved."));

        return response()->json([
            'message'       =>  'Approved'
        ], 201);
    }

    public function rejectSignatureReset(User $user)
    {
        $user->update([
            'requestSignatureReset'     =>  false,
        ]);
        $user->notify(new EvalNotifications("Unfortunately, your signature reset request has been declined."));


        return response()->json([
            'message'       =>  'Rejected Successfully'
        ], 201);
    }

    public function approveRegistration(User $user)
    {
        $user->update([
            'is_active'     =>  'active'
        ]);

        return response()->json([
            'message'       =>  'Approved'
        ], 201);
    }


    public function rejectRegistration(User $user)
    {
        $user->update([
            'is_active'      =>  'declined'
        ]);

        return response()->json([
            'message'       =>  'Declined successfully'
        ], 201);
    }

    public function updateUserBranch(User $user, Request $request)
    {

        $user->branches()->syncWithoutDetaching($request->branch_ids);

        return response()->json([
            'message'       =>  'User Branch Updated'
        ], 201);
    }

    public function removeUserBranches(User $user)
    {
        $user->branches()->detach();

        return response()->json([
            'message' => 'All user branches removed'
        ], 200);
    }


    //destroy || delete
    public function deleteUser(User $user)
    {
        $user->delete();

        return response()->json([
            'message'       => 'Deleted Successfully'
        ], 200);
    }

    // public function test()
    // {
    //     $user  = User::findOrFail(1);
    //     $user->notify(new EvalNotifications("This is a test notification for broadcasting."));

    //     return response()->json([
    //         'data'  =>  "success"
    //     ], 200);
    // }
}
