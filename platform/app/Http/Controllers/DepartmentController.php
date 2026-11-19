<?php

namespace App\Http\Controllers;

use App\Http\Responses\Api;
use App\Models\Department;
use App\Models\Role;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Departments categorize people and gate access. They never hold token allowance. Admin and Super Admin manage them. */
class DepartmentController extends Controller
{
    private function guard(Request $request): void
    {
        if (! $request->user()->hasRole(Role::ADMIN, Role::SUPER_ADMIN)) {
            throw new AuthorizationException;
        }
    }

    private function present(Department $d): array
    {
        return ['id' => $d->id, 'name' => $d->name, 'description' => $d->description, 'members' => $d->users_count ?? $d->users()->count()];
    }

    public function index(Request $request): JsonResponse
    {
        $this->guard($request);

        return Api::ok(Department::withCount('users')->orderBy('name')->get()->map(fn ($d) => $this->present($d))->values());
    }

    public function store(Request $request): JsonResponse
    {
        $this->guard($request);
        $d = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:departments,name'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
        $dept = Department::create($d);
        Audit::log('department.created', $request->user(), $dept, ['name' => $dept->name]);

        return Api::ok($this->present($dept), status: 201);
    }

    public function update(Request $request, Department $department): JsonResponse
    {
        $this->guard($request);
        $d = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('departments', 'name')->ignore($department->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $department->update($d);
        Audit::log('department.updated', $request->user(), $department, ['fields' => array_keys($d)]);

        return Api::ok($this->present($department->fresh()));
    }

    public function destroy(Request $request, Department $department): JsonResponse
    {
        $this->guard($request);
        if ($department->users()->exists()) {
            return Api::error('department_in_use', 'Move or remove the members first. A department with members cannot be deleted.', 422);
        }
        Audit::log('department.deleted', $request->user(), $department, ['name' => $department->name]);
        $department->delete();

        return Api::ok(['deleted' => true]);
    }
}
