<?php

namespace App\Services\Transformers;

use App\DTOs\SyncEvent;
use App\DTOs\UserDTO;
use App\DTOs\PostDTO;
use App\DTOs\LikeDTO;
use Illuminate\Support\Facades\Log;

/**
 * Revamped (MySQL) to Legacy (PostgreSQL) Transformer
 * 
 * Transforms events from the Revamped platform schema
 * to the Legacy platform schema.
 */
class RevampToLegacyMapper implements TransformerInterface
{
    private array $supportedEntities = ['users', 'posts', 'likes'];

    /**
     * Transform Revamped event to Legacy format
     */
    public function transform(SyncEvent $event): array
    {
        Log::debug('Transforming Revamp to Legacy', [
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
     * Transform User from Revamped to Legacy
     * 
     * Revamped Schema:        Legacy Schema:
     * - user_name          -> username
     * - email_address      -> email
     * - display_name       -> full_name
     * - mobile             -> phone_number
     * - account_status     -> status
     */
    private function transformUser(SyncEvent $event): array
    {
        $userDTO = UserDTO::fromRevamp($event->payload);
        
        return $userDTO->toLegacy();
    }

    /**
     * Transform Post from Revamped to Legacy
     * 
     * Revamped Schema:        Legacy Schema:
     * - author_id          -> user_id
     * - title              -> post_title
     * - content            -> post_content
     * - status             -> post_status
     * - views              -> view_count
     */
    private function transformPost(SyncEvent $event): array
    {
        $postDTO = PostDTO::fromRevamp($event->payload);
        
        return $postDTO->toLegacy();
    }

    /**
     * Transform Like from Revamped to Legacy
     * 
     * Revamped Schema:        Legacy Schema:
     * - reaction_type      -> like_type
     */
    private function transformLike(SyncEvent $event): array
    {
        $likeDTO = LikeDTO::fromRevamp($event->payload);
        
        return $likeDTO->toLegacy();
    }

    /**
     * Get supported entity types
     */
    public function getSupportedEntities(): array
    {
        return $this->supportedEntities;
    }
}


