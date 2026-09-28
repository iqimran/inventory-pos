<?php

namespace App\Enums;

/**
 * Service job workflow:
 * RECEIVED → DIAGNOSING → WAITING_FOR_APPROVAL → IN_PROGRESS → READY → DELIVERED,
 * with CANCELLED for abandoned jobs. Guards that depend on the job (diagnosis, approval,
 * invoice) are enforced by App\Actions\MobileService\ChangeServiceJobStatus.
 */
enum ServiceJobStatus: string
{
    case Received = 'RECEIVED';
    case Diagnosing = 'DIAGNOSING';
    case WaitingForApproval = 'WAITING_FOR_APPROVAL';
    case InProgress = 'IN_PROGRESS';
    case Ready = 'READY';
    case Delivered = 'DELIVERED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Received',
            self::Diagnosing => 'Diagnosing',
            self::WaitingForApproval => 'Waiting for approval',
            self::InProgress => 'In progress',
            self::Ready => 'Ready',
            self::Delivered => 'Delivered',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Statuses this one may move to. Backward steps cover re-approval (extra fault found)
     * and rework (device failed the final check).
     *
     * @return list<self>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::Received => [self::Diagnosing, self::Cancelled],
            self::Diagnosing => [self::WaitingForApproval, self::Cancelled],
            self::WaitingForApproval => [self::InProgress, self::Cancelled],
            self::InProgress => [self::Ready, self::WaitingForApproval, self::Cancelled],
            self::Ready => [self::Delivered, self::InProgress, self::Cancelled],
            self::Delivered, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->transitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this === self::Delivered || $this === self::Cancelled;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $status) => ['value' => $status->value, 'label' => $status->label()], self::cases());
    }
}
