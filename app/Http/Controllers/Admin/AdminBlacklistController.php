<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BlacklistEntry;
use App\Support\Paged;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group('Admin', 'The admin panel. Every call needs an administrator account.', weight: 99)]
class AdminBlacklistController extends Controller
{
    /**
     * Blacklist.
     *
     * Blocked emails, phone numbers, email domains, IP addresses and words, with the reason, who added each and when it
     * ends. Blocked emails, phones and domains cannot sign up or sign in; blocked addresses cannot post anything; blocked
     * words are refused in projects, offers, messages, reviews and profiles.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>, counts: array<string, int>}
     */
    public function index(Request $request): array
    {
        $request->validate([
            'type' => ['nullable', Rule::in(BlacklistEntry::TYPES)],
            'q' => ['nullable', 'string', 'max:100'],
            /** `1` to include entries that already expired. */
            'expired' => ['nullable', 'boolean'],
        ]);

        $page = BlacklistEntry::with('creator')
            ->when($request->input('type'), fn ($q, $type) => $q->where('type', $type))
            ->when($request->input('q'), fn ($q, $term) => $q->where('value', 'like', '%'.addcslashes(mb_strtolower($term), '%_\\').'%'))
            ->when(! $request->boolean('expired'), fn ($q) => $q->inForce())
            ->latest('id')->paginate($request->integer('perPage', 30));

        return Paged::of($page, fn (BlacklistEntry $e) => self::present($e), [
            'counts' => BlacklistEntry::inForce()->selectRaw('type, count(*) as total')->groupBy('type')->pluck('total', 'type')->map(fn ($n) => (int) $n)->all(),
        ]);
    }

    /**
     * Block something.
     *
     * `email`, `phone`, `domain` (e.g. `spam.com`), `ip` or `keyword`. Without `expiresAt` it lasts until removed.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $entry = BlacklistEntry::updateOrCreate(
            ['type' => $data['type'], 'value' => BlacklistEntry::normalize($data['type'], $data['value'])],
            ['reason' => $data['reason'], 'expires_at' => $data['expiresAt'] ?? null, 'created_by' => $request->user()->id],
        );

        return response()->json(['data' => self::present($entry->load('creator'))], 201);
    }

    /**
     * Change the reason or end date.
     *
     * @return array{data: array<string, mixed>}
     */
    public function update(Request $request, int $entry): array
    {
        $data = $request->validate(['reason' => ['sometimes', 'string', 'min:3', 'max:255'], 'expiresAt' => ['sometimes', 'nullable', 'date']]);
        $entry = BlacklistEntry::findOrFail($entry);
        $entry->update(collect(['reason' => 'reason', 'expiresAt' => 'expires_at'])->filter(fn ($c, $i) => array_key_exists($i, $data))->mapWithKeys(fn ($c, $i) => [$c => $data[$i]])->all());

        return ['data' => self::present($entry->load('creator'))];
    }

    /**
     * Unblock.
     *
     * @return array{success: bool}
     */
    public function destroy(int $entry): array
    {
        BlacklistEntry::findOrFail($entry)->delete();

        return ['success' => true];
    }

    /**
     * Validates a blacklist entry, checking the value fits its type (email, phone, domain, IP or keyword).
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $type = $request->input('type');

        return $request->validate([
            'type' => ['required', Rule::in(BlacklistEntry::TYPES)],
            'value' => ['required', 'string', 'max:255', match ($type) {
                'email' => 'email',
                'ip' => 'ip',
                'domain' => 'regex:/^@?[a-z0-9.-]+\.[a-z]{2,}$/i',
                'phone' => 'regex:/^\+?[\d\s()-]{6,20}$/',
                default => 'min:2',
            }],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'expiresAt' => ['nullable', 'date', 'after:now'],
        ]);
    }

    /**
     * A blacklist entry for the admin list.
     *
     * @return array<string, mixed>
     */
    public static function present(BlacklistEntry $e): array
    {
        return [
            'id' => $e->id, 'type' => $e->type, 'value' => $e->value, 'reason' => $e->reason, 'createdBy' => Paged::user($e->creator),
            'expiresAt' => $e->expires_at?->toIso8601String(), 'expired' => (bool) $e->expires_at?->isPast(), 'createdAt' => $e->created_at?->toIso8601String(),
        ];
    }
}
