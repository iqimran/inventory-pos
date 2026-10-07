<?php

namespace App\Http\Requests\Reports;

use App\Domain\Reporting\ReportAccess;
use App\Domain\Reporting\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Date range (and day/month grouping) shared by every report. Defaults to the current month to date.
 */
class ReportRequest extends FormRequest
{
    /** Longest range that may be grouped by day. */
    public const MAX_DAILY_DAYS = 366;

    /**
     * reports.view plus the permission of the module the report exposes (see ReportAccess).
     */
    public function authorize(): bool
    {
        $report = str((string) $this->route()?->getName())->after('reports.')->toString();

        return ReportAccess::report($this->user(), $report);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'group_by' => ['nullable', 'in:day,month'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty() || $this->input('group_by', 'day') !== 'day') {
                    return;
                }

                [$from, $to] = $this->range();

                if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) >= self::MAX_DAILY_DAYS) {
                    $validator->errors()->add('group_by', 'Group ranges longer than a year by month.');
                }
            },
        ];
    }

    public function period(?string $groupBy = null): ReportPeriod
    {
        [$from, $to] = $this->range();

        return ReportPeriod::make($from, $to, $groupBy ?? $this->input('group_by', 'day'));
    }

    /**
     * @return array{from: string, to: string, group_by: string}
     */
    public function filters(): array
    {
        [$from, $to] = $this->range();

        return ['from' => $from, 'to' => $to, 'group_by' => $this->input('group_by', 'day')];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function range(): array
    {
        $today = CarbonImmutable::now(ReportPeriod::timezone());

        return [
            $this->input('from') ?: $today->startOfMonth()->toDateString(),
            $this->input('to') ?: $today->toDateString(),
        ];
    }
}
