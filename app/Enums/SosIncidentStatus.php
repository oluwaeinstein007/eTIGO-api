<?php

namespace App\Enums;

enum SosIncidentStatus: string
{
    case Triggered = 'triggered';
    case CheckInSent = 'check_in_sent';
    case Acknowledged = 'acknowledged';
    case Escalated = 'escalated';
    case OperatorAssigned = 'operator_assigned';
    case Dispatched = 'dispatched';
    case Resolved = 'resolved';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Triggered => 'Triggered',
            self::CheckInSent => 'Check-In Sent',
            self::Acknowledged => 'Acknowledged',
            self::Escalated => 'Escalated',
            self::OperatorAssigned => 'Operator Assigned',
            self::Dispatched => 'Dispatched',
            self::Resolved => 'Resolved',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Resolved, self::Cancelled]);
    }

    public function isActive(): bool
    {
        return ! $this->isTerminal();
    }

    public function requiresAttention(): bool
    {
        return in_array($this, [self::Triggered, self::CheckInSent, self::Escalated]);
    }
}
