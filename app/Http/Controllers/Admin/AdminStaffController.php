<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AdminLog;
use App\Models\AdminRole;
use App\Models\User;
use App\Support\Paged;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

#[Group('Admin', 'The admin panel. Every call needs an administrator account.', weight: 99)]
class AdminStaffController extends Controller
{
    /**
     * Staff.
     *
     * Every admin with their role, two-factor status, last activity, open sessions and number of logged actions.
     *
     * @return array{data: list<array<string, mixed>>}
     */
    public function index(): array
    {
        $actions = AdminLog::selectRaw('admin_id, count(*) as total, max(created_at) as last')->groupBy('admin_id')->get()->keyBy('admin_id');

        return ['data' => User::with('adminRole')->where('is_admin', true)->orderBy('id')->get()->map(fn (User $u) => [
            ...Paged::user($u),
            'email' => $u->email,
            'phone' => $u->phone,
            'role' => $u->adminRole ? self::role($u->adminRole) : null,
            /** True for owners: an admin without a role has every permission. */
            'fullAccess' => $u->admin_role_id === null || in_array('*', $u->adminRole?->permissions ?? [], true),
            'twoFactor' => $u->hasTwoFactor(),
            'lastSeenAt' => $u->last_seen_at?->toIso8601String(),
            'sessions' => PersonalAccessToken::where('tokenable_type', User::class)->where('tokenable_id', $u->id)->count(),
            'actions' => (int) ($actions[$u->id]->total ?? 0),
            'lastActionAt' => isset($actions[$u->id]) ? (string) $actions[$u->id]->last : null,
        ])->all()];
    }

    /**
     * Add a staff member.
     *
     * Gives an existing account (found by email or phone) access to the panel with a role, or creates a new account with
     * a password when none exists. Without `roleId` the person gets full access.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            /** Email or phone of the person. */
            'identifier' => ['required', 'string', 'max:255'],
            'roleId' => ['nullable', 'integer', 'exists:admin_roles,id'],
            /** For a new account only. */
            'name' => ['nullable', 'string', 'max:100'],
            'password' => ['nullable', 'string', 'min:10', 'max:255'],
        ]);

        $identifier = mb_strtolower(trim($data['identifier']));
        $user = User::where('email', $identifier)->orWhere('phone', $identifier)->first();

        if (! $user) {
            if (! str_contains($identifier, '@') || empty($data['password'])) {
                throw new ApiException(__('admin.staff_not_found'), 'staff_not_found', 422);
            }
            [$first, $last] = array_pad(explode(' ', trim($data['name'] ?? 'Staff'), 2), 2, null);
            $user = User::forceCreate([
                'email' => $identifier, 'email_verified_at' => now(), 'password' => $data['password'],
                'first_name' => $first, 'last_name' => $last, 'username' => Str::slug($data['name'] ?? 'staff').'-'.Str::lower(Str::random(4)),
            ]);
        }

        $user->forceFill(['is_admin' => true, 'admin_role_id' => $data['roleId'] ?? null, 'suspended_at' => null])->save();

        return response()->json(['data' => ['id' => $user->id]], 201);
    }

    /**
     * Change a staff member.
     *
     * Change their role (`roleId: null` for full access), or take the panel away (`active: false`): the account stays a
     * normal user account and is signed out everywhere. You cannot change your own access.
     *
     * @return array{success: bool}
     */
    public function update(Request $request, int $user): array
    {
        $data = $request->validate([
            'roleId' => ['sometimes', 'nullable', 'integer', 'exists:admin_roles,id'],
            'active' => ['sometimes', 'boolean'],
        ]);
        $user = User::where('is_admin', true)->findOrFail($user);

        if ($user->id === $request->user()->id) {
            throw new ApiException(__('admin.staff_self'), 'staff_self');
        }

        $this->assertKeepsAnOwner($user, $data);

        if (array_key_exists('roleId', $data)) {
            $user->admin_role_id = $data['roleId'];
        }
        if (($data['active'] ?? true) === false) {
            $user->is_admin = false;
            $user->admin_role_id = null;
            $user->tokens()->delete();
        }
        $user->save();

        return ['success' => true];
    }

    /**
     * Sign a staff member out everywhere.
     *
     * @return array{revoked: int}
     */
    public function revokeSessions(int $user): array
    {
        return ['revoked' => User::where('is_admin', true)->findOrFail($user)->tokens()->delete()];
    }

    /**
     * Roles.
     *
     * The staff roles with their permissions and how many people have each, plus every permission that exists.
     *
     * @return array{data: list<array<string, mixed>>, permissions: list<string>}
     */
    public function roles(): array
    {
        return [
            'data' => AdminRole::withCount('users')->orderBy('id')->get()->map(fn (AdminRole $r) => [...self::role($r), 'users' => $r->users_count])->all(),
            'permissions' => config('admin.permissions'),
        ];
    }

    /**
     * Create a role.
     */
    public function storeRole(Request $request): JsonResponse
    {
        $data = $this->roleData($request);
        $role = AdminRole::create([
            'slug' => Str::slug($data['nameEn']).'-'.Str::lower(Str::random(4)), 'name_en' => $data['nameEn'], 'name_ar' => $data['nameAr'],
            'permissions' => array_values(array_unique($data['permissions'])),
        ]);

        return response()->json(['data' => self::role($role)], 201);
    }

    /**
     * Edit a role.
     *
     * The full-access built-in role cannot be changed.
     *
     * @return array{data: array<string, mixed>}
     */
    public function updateRole(Request $request, int $role): array
    {
        $role = AdminRole::findOrFail($role);
        abort_if($role->slug === 'super_admin', 422, __('admin.role_locked'));
        $data = $this->roleData($request, true);
        request()->attributes->set('audit.before', ['permissions' => $role->permissions]);

        $role->update(collect(['nameEn' => 'name_en', 'nameAr' => 'name_ar', 'permissions' => 'permissions'])
            ->filter(fn ($c, $i) => array_key_exists($i, $data))->mapWithKeys(fn ($c, $i) => [$c => $i === 'permissions' ? array_values(array_unique($data[$i])) : $data[$i]])->all());

        return ['data' => self::role($role)];
    }

    /**
     * Delete a role.
     *
     * Only roles nobody has; built-in roles stay.
     *
     * @return array{success: bool}
     */
    public function destroyRole(int $role): array
    {
        $role = AdminRole::withCount('users')->findOrFail($role);
        abort_if($role->is_system || $role->users_count > 0, 422, __('admin.role_in_use'));
        $role->delete();

        return ['success' => true];
    }

    /**
     * Audit log.
     *
     * Every change made in the admin panel: who, what, on which record, with the input sent (passwords and files never
     * kept) and, for settings, the values before. Newest first.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>, actions: list<string>}
     */
    public function audit(Request $request): array
    {
        $request->validate([
            'adminId' => ['nullable', 'integer'],
            /** The start of an action, e.g. `users`, `withdrawals.approve`, `settings`. */
            'action' => ['nullable', 'string', 'max:80'],
            'subjectType' => ['nullable', 'string', 'max:40'],
            'subjectId' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = AdminLog::with('admin')
            ->when($request->input('adminId'), fn ($q, $id) => $q->where('admin_id', $id))
            ->when($request->input('action'), fn ($q, $a) => $q->where('action', 'like', addcslashes($a, '%_\\').'%'))
            ->when($request->input('subjectType'), fn ($q, $t) => $q->where('subject_type', $t))
            ->when($request->input('subjectId'), fn ($q, $id) => $q->where('subject_id', $id))
            ->when($request->input('from'), fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($request->input('to'), fn ($q, $d) => $q->where('created_at', '<', now()->parse($d)->addDay()))
            ->latest('id')->paginate($request->integer('perPage', 30));

        return Paged::of($page, fn (AdminLog $l) => [
            'id' => $l->id, 'admin' => Paged::user($l->admin), 'action' => $l->action, 'subjectType' => $l->subject_type,
            'subjectId' => $l->subject_id, 'changes' => $l->changes, 'ip' => $l->ip, 'createdAt' => $l->created_at?->toIso8601String(),
        ], ['actions' => AdminLog::distinct()->orderBy('action')->pluck('action')->all()]);
    }

    /**
     * Refuses a change that would leave the panel without any admin who has full access.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertKeepsAnOwner(User $user, array $data): void
    {
        $losesFullAccess = (($data['active'] ?? true) === false) || (array_key_exists('roleId', $data) && $data['roleId'] !== null
            && ! in_array('*', AdminRole::find($data['roleId'])?->permissions ?? [], true));
        $isOwner = $user->admin_role_id === null || in_array('*', $user->adminRole?->permissions ?? [], true);

        if ($losesFullAccess && $isOwner) {
            $owners = User::where('is_admin', true)->where('id', '!=', $user->id)
                ->where(fn ($q) => $q->whereNull('admin_role_id')->orWhereHas('adminRole', fn ($r) => $r->where('slug', 'super_admin')))->count();
            if ($owners === 0) {
                throw new ApiException(__('admin.last_owner'), 'last_owner');
            }
        }
    }

    /**
     * Validates a staff role's names and permissions; on edit every field is optional.
     *
     * @return array<string, mixed>
     */
    private function roleData(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'nameEn' => [$required, 'string', 'max:80'],
            'nameAr' => [$required, 'string', 'max:80'],
            'permissions' => [$required, 'array', 'min:1'],
            'permissions.*' => [Rule::in(config('admin.permissions'))],
        ]);
    }

    /**
     * A staff role for the admin screens.
     *
     * @return array<string, mixed>
     */
    private static function role(AdminRole $r): array
    {
        return ['id' => $r->id, 'slug' => $r->slug, 'nameEn' => $r->name_en, 'nameAr' => $r->name_ar, 'permissions' => $r->permissions, 'isSystem' => $r->is_system];
    }
}
