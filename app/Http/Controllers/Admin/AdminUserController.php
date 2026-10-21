<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminUserResource;
use App\Models\Account;
use App\Models\AdminLog;
use App\Models\AdminNote;
use App\Models\BlacklistEntry;
use App\Models\Dispute;
use App\Models\KycEvent;
use App\Models\Medal;
use App\Models\PointTransaction;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Review;
use App\Models\User;
use App\Models\UserMedal;
use App\Services\Payments\LedgerService;
use App\Services\Payments\WalletService;
use App\Support\LikeSearch;
use App\Support\Money;
use App\Support\Paged;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

#[Group('Admin', 'The admin panel. Every call needs an administrator account.', weight: 99)]
class AdminUserController extends Controller
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly LedgerService $ledger,
    ) {}

    /**
     * List users.
     *
     * Search by name, username, email or phone, and filter by state, identity check, account type and activity. Newest
     * first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            /** `active`, `suspended` or `staff`. */
            'status' => ['nullable', Rule::in(['active', 'suspended', 'staff'])],
            'kyc' => ['nullable', Rule::in(['none', 'pending', 'approved', 'rejected', 'needs_update'])],
            /** `client` (no profile), `freelancer` or `influencer`. */
            'type' => ['nullable', Rule::in(['client', 'freelancer', 'influencer'])],
            /** `online` (last 2 minutes), `week`, `month`, or `inactive` (not seen for 30 days). */
            'activity' => ['nullable', Rule::in(['online', 'week', 'month', 'inactive'])],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return AdminUserResource::collection($this->filtered($request)->with(['profile', 'adminRole'])
            ->latest()->latest('id')->paginate($request->integer('perPage', 20)));
    }

    /**
     * The users query behind the list, shared with the CSV export.
     *
     * @return Builder<User>
     */
    public function filtered(Request $request)
    {
        return User::query()
            ->when($request->input('q'), fn ($query, $term) => LikeSearch::apply($query, [
                'first_name', 'last_name', 'username', 'email', 'phone',
            ], $term))
            ->when($request->input('status') === 'suspended', fn ($query) => $query->whereNotNull('suspended_at'))
            ->when($request->input('status') === 'active', fn ($query) => $query->whereNull('suspended_at'))
            ->when($request->input('status') === 'staff', fn ($query) => $query->where('is_admin', true))
            ->when($request->input('kyc'), fn ($query, $kyc) => $query->where('kyc_status', $kyc))
            ->when($request->input('type') === 'client', fn ($query) => $query->whereDoesntHave('profile'))
            ->when(in_array($request->input('type'), ['freelancer', 'influencer'], true), fn ($query) => $query->whereHas('profile', fn ($q) => $q->where('type', $request->input('type'))))
            ->when($request->input('activity'), fn ($query, $activity) => match ($activity) {
                'online' => $query->where('last_seen_at', '>=', now()->subMinutes(User::ONLINE_WINDOW_MINUTES)),
                'week' => $query->where('last_seen_at', '>=', now()->subDays(7)),
                'month' => $query->where('last_seen_at', '>=', now()->subDays(30)),
                'inactive' => $query->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', now()->subDays(30))),
            });
    }

    /**
     * Show a user.
     *
     * With their numbers and wallet balances.
     *
     * @return array{data: array<string, mixed>, wallet: list<array{currency: string, available: float, inEscrow: float, incoming: float, pendingWithdrawals: float}>}
     */
    public function show(int $user): array
    {
        $user = User::with(['profile', 'adminRole'])->findOrFail($user);
        $user->stats = [
            'projects' => Project::where('user_id', $user->id)->count(),
            'offers' => Proposal::where('user_id', $user->id)->count(),
            'acceptedOffers' => Proposal::where('user_id', $user->id)->where('status', 'accepted')->count(),
            'openDisputes' => Dispute::whereIn('status', Dispute::ACTIVE)->where(fn ($q) => $q->where('opened_by', $user->id)->orWhere('against_id', $user->id))->count(),
            'reviewsReceived' => Review::where('reviewee_id', $user->id)->count(),
            'rating' => round((float) Review::where('reviewee_id', $user->id)->avg('rating'), 1),
            'reportsAgainst' => Report::where('target_type', 'user')->where('target_id', $user->id)->count(),
            'referrals' => User::where('referred_by', $user->id)->count(),
        ];

        return ['data' => [
            ...(new AdminUserResource($user))->resolve(),
            'registrationIp' => $user->registration_ip,
            'referredBy' => Paged::user($user->referrer),
            'emailVerifiedAt' => $user->email_verified_at?->toIso8601String(),
            'phoneVerifiedAt' => $user->phone_verified_at?->toIso8601String(),
            'twoFactorMethod' => $user->two_factor_method,
            'profile' => $user->profile ? [
                'id' => $user->profile->id,
                'type' => $user->profile->type,
                'jobTitle' => $user->profile->job_title,
                'bio' => $user->profile->bio,
                'skills' => $user->profile->skills ?? [],
                'channels' => $user->profile->channels ?? [],
                'experiences' => $user->profile->experiences ?? [],
                'isFeatured' => (bool) $user->profile->is_featured,
                'featuredUntil' => $user->profile->featured_until?->toIso8601String(),
            ] : null,
        ], 'wallet' => $this->wallet->balances($user)];
    }

    /**
     * A user's activity.
     *
     * The latest of everything the user did and everything recorded about them: projects, offers, reviews (given and
     * received), points, medals, identity checks, sign-in sessions, reports, staff notes and the admin actions on them.
     *
     * @return array{data: array<string, list<array<string, mixed>>>}
     */
    public function activity(int $user): array
    {
        $user = User::findOrFail($user);
        $time = fn ($value) => $value?->toIso8601String();

        return ['data' => [
            'projects' => Project::where('user_id', $user->id)->withCount('proposals')->latest('id')->limit(20)->get()->map(fn (Project $p) => [
                'id' => $p->id, 'title' => $p->title, 'type' => $p->type, 'status' => $p->status, 'hidden' => $p->hidden_at !== null,
                'budgetMin' => $p->budget_min, 'budgetMax' => $p->budget_max, 'currency' => $p->currency, 'proposals' => $p->proposals_count, 'createdAt' => $time($p->created_at),
            ])->all(),
            'proposals' => Proposal::with('project:id,title,type')->where('user_id', $user->id)->latest('id')->limit(20)->get()->map(fn (Proposal $p) => [
                'id' => $p->id, 'projectId' => $p->project_id, 'project' => $p->project?->title, 'status' => $p->status,
                'amount' => (float) $p->amount, 'currency' => $p->currency, 'deliveryDays' => $p->delivery_days, 'createdAt' => $time($p->created_at),
            ])->all(),
            'reviewsReceived' => Review::withoutGlobalScope('visible')->with('reviewer')->where('reviewee_id', $user->id)->latest('id')->limit(20)->get()
                ->map(fn (Review $r) => $this->review($r, $r->reviewer))->all(),
            'reviewsGiven' => Review::withoutGlobalScope('visible')->with('reviewee')->where('reviewer_id', $user->id)->latest('id')->limit(20)->get()
                ->map(fn (Review $r) => $this->review($r, $r->reviewee))->all(),
            'points' => PointTransaction::where('user_id', $user->id)->latest('id')->limit(30)->get()->map(fn (PointTransaction $t) => [
                'id' => $t->id, 'amount' => $t->amount, 'reason' => $t->reason, 'meta' => $t->meta, 'manual' => $t->created_by !== null, 'createdAt' => $time($t->created_at),
            ])->all(),
            'medals' => UserMedal::where('user_id', $user->id)->get()->map(fn (UserMedal $m) => [
                'code' => $m->code, 'awardedAt' => $time($m->awarded_at), 'manual' => $m->awarded_by !== null, 'reason' => $m->reason,
                'name' => Medal::where('code', $m->code)->first()?->name(),
            ])->all(),
            'kyc' => KycEvent::with('actor')->where('user_id', $user->id)->orderByDesc('id')->limit(20)->get()->map(fn (KycEvent $e) => [
                'id' => $e->id, 'submissionId' => $e->kyc_submission_id, 'status' => $e->status, 'note' => $e->note,
                'actor' => Paged::user($e->actor), 'createdAt' => $time($e->created_at),
            ])->all(),
            'sessions' => PersonalAccessToken::where('tokenable_type', User::class)->where('tokenable_id', $user->id)->latest('id')->get()->map(fn ($t) => [
                'id' => $t->id, 'ip' => $t->ip_address, 'userAgent' => $t->user_agent, 'lastUsedAt' => $time($t->last_used_at), 'createdAt' => $time($t->created_at),
            ])->all(),
            'reports' => Report::with('reporter')->where('target_type', 'user')->where('target_id', $user->id)->latest('id')->limit(20)->get()->map(fn (Report $r) => [
                'id' => $r->id, 'reason' => $r->reason, 'details' => $r->details, 'status' => $r->status, 'reporter' => Paged::user($r->reporter), 'createdAt' => $time($r->created_at),
            ])->all(),
            'notes' => AdminNote::with('author')->where('user_id', $user->id)->latest('id')->get()->map(fn (AdminNote $n) => [
                'id' => $n->id, 'body' => $n->body, 'author' => Paged::user($n->author), 'createdAt' => $time($n->created_at),
            ])->all(),
            'adminLog' => AdminLog::with('admin')->where('subject_type', 'users')->where('subject_id', $user->id)->latest('id')->limit(30)->get()->map(fn (AdminLog $l) => [
                'id' => $l->id, 'action' => $l->action, 'changes' => $l->changes, 'admin' => Paged::user($l->admin), 'createdAt' => $time($l->created_at),
            ])->all(),
        ]];
    }

    /**
     * A review on the user's admin page, with the other person in it.
     *
     * @return array<string, mixed>
     */
    private function review(Review $review, ?User $other): array
    {
        return [
            'id' => $review->id, 'projectId' => $review->project_id, 'rating' => $review->rating, 'comment' => $review->comment,
            'hidden' => $review->hidden_at !== null, 'user' => Paged::user($other), 'createdAt' => $review->created_at?->toIso8601String(),
        ];
    }

    /**
     * Suspend a user.
     *
     * Locks the account out at once (every call answers `account_suspended`) and ends their sessions. Money and projects stay
     * as they are. Send `days` for a suspension that lifts itself; without it, it lasts until lifted. Admins cannot be
     * suspended.
     */
    public function suspend(Request $request, int $user): AdminUserResource
    {
        $data = $request->validate([
            /** Kept in the record and shown to the user. */
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            /** Ends the suspension automatically after this many days. */
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $target = $this->lockOut($user, $data['reason'], isset($data['days']) ? now()->addDays((int) $data['days']) : null);

        return new AdminUserResource($target->load(['profile', 'adminRole']));
    }

    /**
     * Ban a user.
     *
     * A suspension with no end, and optionally their email and phone on the blacklist so they cannot open a new account.
     */
    public function ban(Request $request, int $user): AdminUserResource
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            /** Also block the email address and phone number from signing up again. */
            'blacklist' => ['nullable', 'boolean'],
        ]);

        $target = $this->lockOut($user, $data['reason'], null);

        if ($request->boolean('blacklist')) {
            foreach (['email' => $target->email, 'phone' => $target->phone] as $type => $value) {
                if ($value) {
                    BlacklistEntry::updateOrCreate(
                        ['type' => $type, 'value' => BlacklistEntry::normalize($type, $value)],
                        ['reason' => $data['reason'], 'created_by' => $request->user()->id, 'expires_at' => null],
                    );
                }
            }
        }

        return new AdminUserResource($target->load(['profile', 'adminRole']));
    }

    /**
     * Suspends a user until the given date (or for good) and signs them out; admins cannot be suspended.
     */
    private function lockOut(int $user, string $reason, mixed $until): User
    {
        $target = User::findOrFail($user);

        if ($target->is_admin) {
            throw new ApiException(__('admin.cannot_suspend_admin'), 'cannot_suspend_admin');
        }

        $target->forceFill(['suspended_at' => now(), 'suspended_until' => $until, 'suspension_reason' => $reason])->save();
        $target->tokens()->delete();

        return $target;
    }

    /**
     * Lift a suspension.
     */
    public function unsuspend(int $user): AdminUserResource
    {
        $target = User::findOrFail($user);
        $target->forceFill(['suspended_at' => null, 'suspended_until' => null, 'suspension_reason' => null])->save();

        return new AdminUserResource($target->load(['profile', 'adminRole']));
    }

    /**
     * Sign a user out everywhere.
     *
     * Ends every session of the account (lost phone, shared password).
     *
     * @return array{revoked: int}
     */
    public function revokeSessions(int $user): array
    {
        return ['revoked' => User::findOrFail($user)->tokens()->delete()];
    }

    /**
     * Add a staff note.
     *
     * Private to the staff: never shown to the user.
     *
     * @return array{data: array{id: int, body: string, author: mixed, createdAt: ?string}}
     */
    public function addNote(Request $request, int $user): array
    {
        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:2000']]);
        $note = AdminNote::create(['user_id' => User::findOrFail($user)->id, 'author_id' => $request->user()->id, 'body' => $data['body']]);

        return ['data' => ['id' => $note->id, 'body' => $note->body, 'author' => Paged::user($request->user()), 'createdAt' => $note->created_at?->toIso8601String()]];
    }

    /**
     * Delete a staff note.
     *
     * @return array{success: bool}
     */
    public function deleteNote(int $user, int $note): array
    {
        AdminNote::where('user_id', $user)->findOrFail($note)->delete();

        return ['success' => true];
    }

    /**
     * Adjust a wallet.
     *
     * Support tool: adds money (positive `amount`) or takes it (negative) with a mandatory reason. It is a normal ledger
     * movement against the gateway account, recorded under your name, and cannot take a wallet below zero.
     *
     * @return array{data: list<array{currency: string, available: float, inEscrow: float, incoming: float, pendingWithdrawals: float}>}
     */
    public function adjust(Request $request, int $user): array
    {
        $data = $request->validate([
            /** Positive adds, negative takes. Up to 2 decimals. */
            'amount' => ['required', 'numeric', 'not_in:0', 'between:-100000,100000', 'decimal:0,2'],
            'currency' => ['required', Rule::in(array_column(config('marketplace.currencies'), 'code'))],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $target = User::findOrFail($user);
        $cents = Money::toCents($data['amount']);

        $this->ledger->post('adjustment', [
            [$this->ledger->system(Account::GATEWAY, $data['currency']), -$cents],
            [$this->ledger->wallet($target, $data['currency']), $cents],
        ], ['description' => $data['reason'], 'by' => $request->user()]);

        return ['data' => $this->wallet->balances($target)];
    }
}
