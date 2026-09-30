<?php

namespace App\Entity;

/**
 * Lifecycle of an agent run, as exposed to the UI.
 */
enum AgentRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case LimitReached = 'limit_reached';

    public function isFinished(): bool
    {
        return \in_array($this, [self::Completed, self::Failed, self::Cancelled, self::LimitReached], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::Running => 'En cours',
            self::Completed => 'Terminé',
            self::Failed => 'Échec',
            self::Cancelled => 'Annulé',
            self::LimitReached => 'Limite atteinte',
        };
    }
}
