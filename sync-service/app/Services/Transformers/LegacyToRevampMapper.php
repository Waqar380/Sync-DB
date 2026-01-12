<?php

namespace App\Services\Transformers;

use App\DTOs\SyncEvent;
use App\DTOs\UserDTO;
use App\DTOs\PostDTO;
use App\DTOs\LikeDTO;
use Illuminate\Support\Facades\Log;

/**
 * Legacy (PostgreSQL) to Revamped (MySQL) Transformer
 * 
 * Transforms events from the Legacy platform schema
 * to the Revamped platform schema.
 */
class LegacyToRevampMapper implements TransformerInterface
{
    private array $supportedEntities = ['users', 'posts', 'likes'];

    /**
     * Transform Legacy event to Revamped format
     */
    public function transform(SyncEvent $event): array
    {
        Log::debug('Transforming Legacy to Revamp', [
            'entity_type' => $event->entityType,
            'operation' => $event->operation,
            'primary_key' => $event->primaryKey,
        ]);

        $transformed = match($event->entityType) {
            'users' => $this->transformUser($event),
            'posts' => $this->transformPost($event),
            'likes' => $this->transformLike($event),
            default => throw new \InvalidArgumentException("Unsupported entity type: {$event->entityType}"),
        };

        Log::debug('Transformation complete', [
            'entity_type' => $event->entityType,
            'transformed_keys' => array_keys($transformed),
        ]);

        return $transformed;
    }

    /**
     * Check if this mapper can handle the entity type
     */
    public function canHandle(string $entityType): bool
    {
        return in_array($entityType, $this->supportedEntities);
    }

    /**
     * Transform User from Legacy to Revamped
     * 
     * Legacy Schema:          Revamped Schema:
     * - username           -> user_name
     * - email              -> email_address
     * - full_name          -> display_name
     * - phone_number       -> mobile
     * - status             -> account_status
     */
    private function transformUser(SyncEvent $event): array
    {
        $userDTO = UserDTO::fromLegacy($event->payload);
        
        return $userDTO->toRevamp();
    }

    /**
     * Transform Post from Legacy to Revamped
     * 
     * Legacy Schema:          Revamped Schema:
     * - user_id            -> author_id
     * - post_title         -> title
     * - post_content       -> content
     * - post_status        -> status
     * - view_count         -> views
     */
    private function transformPost(SyncEvent $event): array
    {
        $postDTO = PostDTO::fromLegacy($event->payload);
        
        return $postDTO->toRevamp();
    }

    /**
     * Transform Like from Legacy to Revamped
     * 
     * Legacy Schema:          Revamped Schema:
     * - like_type          -> reaction_type
     */
    private function transformLike(SyncEvent $event): array
    {
        $likeDTO = LikeDTO::fromLegacy($event->payload);
        
        return $likeDTO->toRevamp();
    }

    /**
     * Get supported entity types
     */
    public function getSupportedEntities(): array
    {
        return $this->supportedEntities;
    }
}


