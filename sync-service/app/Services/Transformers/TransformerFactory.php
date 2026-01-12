<?php

namespace App\Services\Transformers;

/**
 * Factory for creating appropriate transformers
 * based on the direction of synchronization
 */
class TransformerFactory
{
    public const DIRECTION_LEGACY_TO_REVAMP = 'legacy_to_revamp';
    public const DIRECTION_REVAMP_TO_LEGACY = 'revamp_to_legacy';

    /**
     * Create a transformer for the given direction
     * 
     * @param string $direction One of the DIRECTION_* constants
     * @return TransformerInterface
     * @throws \InvalidArgumentException
     */
    public static function create(string $direction): TransformerInterface
    {
        return match($direction) {
            self::DIRECTION_LEGACY_TO_REVAMP => new LegacyToRevampMapper(),
            self::DIRECTION_REVAMP_TO_LEGACY => new RevampToLegacyMapper(),
            default => throw new \InvalidArgumentException("Invalid transformation direction: {$direction}"),
        };
    }

    /**
     * Get transformer for legacy events (going to revamp)
     */
    public static function forLegacyEvents(): TransformerInterface
    {
        return self::create(self::DIRECTION_LEGACY_TO_REVAMP);
    }

    /**
     * Get transformer for revamp events (going to legacy)
     */
    public static function forRevampEvents(): TransformerInterface
    {
        return self::create(self::DIRECTION_REVAMP_TO_LEGACY);
    }
}


