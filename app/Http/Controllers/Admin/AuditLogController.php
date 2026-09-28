<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only audit trail viewer (T047).
 */
class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize(Permission::AuditView->value);

        $filters = $request->validate([
            'event' => ['nullable', 'string', 'max:60'],
            'user_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $logs = AuditLog::query()
            ->with('user:id,name')
            ->when($filters['event'] ?? null, function (Builder $q, string $event) {
                // "product" matches every product.* event; "product.updated" matches exactly.
                str_contains($event, '.') ? $q->where('event', $event) : $q->where('event', 'like', "{$event}.%");
            })
            ->when($filters['user_id'] ?? null, fn (Builder $q, $id) => $q->where('user_id', $id))
            ->when($filters['q'] ?? null, fn (Builder $q, $term) => $q->where('description', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%'))
            ->when($filters['from'] ?? null, fn (Builder $q, $from) => $q->where('created_at', '>=', CarbonImmutable::parse($from)->startOfDay()))
            ->when($filters['to'] ?? null, fn (Builder $q, $to) => $q->where('created_at', '<=', CarbonImmutable::parse($to)->endOfDay()))
            ->latest('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (AuditLog $log) => [
                'id' => $log->id,
                'event' => $log->event,
                'subject' => $log->auditable_type ? ['type' => $log->auditable_type, 'id' => $log->auditable_id] : null,
                'description' => $log->description,
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
                'user' => $log->user?->name,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at?->toIso8601String(),
            ]);

        return Inertia::render('admin/audit-logs/index', [
            'logs' => $logs,
            'filters' => array_merge(['event' => '', 'user_id' => '', 'q' => '', 'from' => '', 'to' => ''], array_filter($filters, fn ($v) => $v !== null)),
            'events' => AuditLog::query()->distinct()->orderBy('event')->pluck('event'),
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
