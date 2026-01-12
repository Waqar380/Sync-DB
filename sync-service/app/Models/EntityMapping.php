<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Entity Mapping Model
 * 
 * Stores the mapping between Legacy and Revamped entity IDs
 * to maintain referential integrity across platforms.
 */
class EntityMapping extends Model
{
    protected $connection = 'revamp';
    protected $table = 'entity_mappings';

    protected $fillable = [
        'entity_type',
        'legacy_id',
        'revamp_id',
    ];

    protected $casts = [
        'legacy_id' => 'integer',
        'revamp_id' => 'integer',
    ];

    /**
     * Find mapping by legacy ID
     */
    public static function findByLegacyId(string $entityType, int $legacyId): ?self
    {
        return self::where('entity_type', $entityType)
            ->where('legacy_id', $legacyId)
            ->first();
    }

    /**
     * Find mapping by revamp ID
     */
    public static function findByRevampId(string $entityType, int $revampId): ?self
    {
        return self::where('entity_type', $entityType)
            ->where('revamp_id', $revampId)
            ->first();
    }

    /**
     * Create or update mapping
     */
    public static function createOrUpdateMapping(string $entityType, int $legacyId, int $revampId): self
    {
        return self::updateOrCreate(
            [
                'entity_type' => $entityType,
                'legacy_id' => $legacyId,
            ],
            [
                'revamp_id' => $revampId,
            ]
        );
    }

    /**
     * Get revamp ID from legacy ID
     */
    public static function getRevampId(string $entityType, int $legacyId): ?int
    {
        $mapping = self::findByLegacyId($entityType, $legacyId);
        return $mapping?->revamp_id;
    }

    /**
     * Get legacy ID from revamp ID
     */
    public static function getLegacyId(string $entityType, int $revampId): ?int
    {
        $mapping = self::findByRevampId($entityType, $revampId);
        return $mapping?->legacy_id;
    }

    /**
     * Delete mapping
     */
    public static function deleteMapping(string $entityType, int $legacyId, int $revampId): bool
    {
        return self::where('entity_type', $entityType)
            ->where('legacy_id', $legacyId)
            ->where('revamp_id', $revampId)
            ->delete() > 0;
    }

    /**
     * Get all mappings for an entity type
     */
    public static function getMappingsForEntity(string $entityType): \Illuminate\Database\Eloquent\Collection
    {
        return self::where('entity_type', $entityType)
            ->orderBy('created_at', 'desc')
            ->get();
    }
}


