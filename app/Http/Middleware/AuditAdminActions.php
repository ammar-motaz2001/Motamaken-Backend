<?php

namespace App\Http\Middleware;

use App\Models\AdminLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

/**
 * Writes every successful change made in the admin panel to the audit log: who, what, on which record, with what input.
 * Controllers can add the values before the change under the `audit.before` request attribute.
 */
class AuditAdminActions
{
    /** Never stored, even scrubbed. */
    private const SECRET = ['password', 'password_confirmation', 'mailPassword', 'token', 'secret', 'file', 'image', 'logo'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->isMethodSafe() || $response->getStatusCode() >= 400 || ! $request->user()) {
            return $response;
        }

        $route = $request->route();
        $uri = preg_replace('#^api/admin/#', '', $route->uri());
        $segments = explode('/', $uri);
        $words = array_values(array_filter($segments, fn ($s) => ! str_starts_with($s, '{')));
        $endsOnParam = str_starts_with(end($segments), '{');

        $verb = match (true) {
            $endsOnParam && $request->isMethod('DELETE') => 'delete',
            $endsOnParam => 'update',
            count($words) === 1 && $request->isMethod('POST') => 'create',
            count($words) === 1 => 'update',
            default => null,
        };

        $params = $route->parameters();
        $subjectId = collect($params)->first(fn ($v) => is_numeric($v));
        $input = Arr::except($request->except(self::SECRET), self::SECRET);
        $before = $request->attributes->get('audit.before');

        AdminLog::create([
            'admin_id' => $request->user()->id,
            'action' => str_replace('-', '_', implode('.', $verb ? [...$words, $verb] : $words)),
            'subject_type' => str_replace('-', '_', $words[0] ?? ''),
            'subject_id' => $subjectId ?? ($response->getStatusCode() === 201 ? data_get(json_decode((string) $response->getContent(), true), 'data.id') : null),
            'changes' => array_filter(['input' => $input ?: null, 'before' => $before, 'key' => collect($params)->first(fn ($v) => ! is_numeric($v))]) ?: null,
            'ip' => $request->ip(),
        ]);

        return $response;
    }
}
