<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Processed Event Model
 * 
 * Tracks processed events to ensure idempotent processing
 * and prevent duplicate event handling.
 */
class ProcessedEvent extends Model
{
    protected $connection = 'revamp';
    protected $table = 'processed_events';
    protected $primaryKey = 'event_id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'event_id',
        'entity_type',
        'operation',
        'source',
        'primary_key',
        'payload',
        'processed_at',
    ];

    protected $casts = [
        'primary_key' => 'integer',
        'processed_at' => 'datetime',
    ];

    /**
     * Check if an event has been processed
     */
    public static function isProcessed(string $eventId): bool
    {
        return self::where('event_id', $eventId)->exists();
    }

    /**
     * Mark an event as processed
     */
    public static function markAsProcessed(
        string $eventId,
        string $entityType,
        string $operation,
        string $source,
        int|string $primaryKey,
        ?array $payload = null
    ): self {
        return self::create([
            'event_id' => $eventId,
            'entity_type' => $entityType,
            'operation' => $operation,
            'source' => $source,
            'primary_key' => $primaryKey,
            'payload' => $payload ? json_encode($payload) : null,
            'processed_at' => now(),
        ]);
    }

    /**
     * Clean up old processed events (for maintenance)
     * 
     * @param int $daysToKeep Number of days to keep processed events
     * @return int Number of deleted records
     */
    public static function cleanup(int $daysToKeep = 7): int
    {
        return self::where('processed_at', '<', now()->subDays($daysToKeep))->delete();
    }

    /**
     * Get recent processed events for an entity
     */
    public static function getRecentForEntity(string $entityType, int $limit = 100): \Illuminate\Database\Eloquent\Collection
    {
        return self::where('entity_type', $entityType)
            ->orderBy('processed_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get processing statistics
     */
    public static function getStatistics(): array
    {
        return [
            'total_processed' => self::count(),
            'by_entity_type' => self::selectRaw('entity_type, COUNT(*) as count')
                ->groupBy('entity_type')
                ->pluck('count', 'entity_type')
                ->toArray(),
            'by_operation' => self::selectRaw('operation, COUNT(*) as count')
                ->groupBy('operation')
                ->pluck('count', 'operation')
                ->toArray(),
            'by_source' => self::selectRaw('source, COUNT(*) as count')
                ->groupBy('source')
                ->pluck('count', 'source')
                ->toArray(),
            'last_24h' => self::where('processed_at', '>=', now()->subDay())->count(),
        ];
    }
}


