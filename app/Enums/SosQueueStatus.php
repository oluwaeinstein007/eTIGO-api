<?php

namespace App\Enums;

/**
 * The safety console's three-way view of an incident: has an operator taken
 * it yet? This is separate from SosIncidentStatus, where "acknowledged" means
 * the person who raised the SOS confirmed they are safe.
 */
enum SosQueueStatus: string
{
    case Unacknowledged = 'unacknowledged';
    case Acknowledged = 'acknowledged';
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::Unacknowledged => 'Unacknowledged',
            self::Acknowledged => 'Acknowledged',
            self::Resolved => 'Resolved',
        };
    }

    public static function fromIncidentStatus(SosIncidentStatus $status): self
    {
        return match ($status) {
            SosIncidentStatus::OperatorAssigned, SosIncidentStatus::Dispatched => self::Acknowledged,
            SosIncidentStatus::Resolved, SosIncidentStatus::Cancelled => self::Resolved,
            default => self::Unacknowledged,
        };
    }

    /**
     * @return list<string>
     */
    public function incidentStatuses(): array
    {
        return array_values(array_map(
            fn (SosIncidentStatus $status) => $status->value,
            array_filter(SosIncidentStatus::cases(), fn (SosIncidentStatus $status) => self::fromIncidentStatus($status) === $this),
        ));
    }
}
