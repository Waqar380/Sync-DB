<?php

namespace App\DTOs;

/**
 * Like Entity Data Transfer Object
 * 
 * Platform-agnostic representation of a Like/Reaction entity
 */
class LikeDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly int $postId,
        public readonly string $reactionType = 'like',
        public readonly ?string $createdAt = null,
    ) {}

    /**
     * Create from Legacy PostgreSQL data
     */
    public static function fromLegacy(array $data): self
    {
        return new self(
            id: $data['id'],
            userId: $data['user_id'],
            postId: $data['post_id'],
            reactionType: strtolower($data['like_type'] ?? 'like'),
            createdAt: $data['created_at'] ?? null,
        );
    }

    /**
     * Create from Revamped MySQL data
     */
    public static function fromRevamp(array $data): self
    {
        return new self(
            id: $data['id'],
            userId: $data['user_id'],
            postId: $data['post_id'],
            reactionType: strtolower($data['reaction_type'] ?? 'like'),
            createdAt: $data['created_at'] ?? null,
        );
    }

    /**
     * Convert to Legacy PostgreSQL format
     */
    public function toLegacy(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->userId,
            'post_id' => $this->postId,
            'like_type' => $this->reactionType,
            'source' => 'sync_service',
        ];
    }

    /**
     * Convert to Revamped MySQL format
     */
    public function toRevamp(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->userId,
            'post_id' => $this->postId,
            'reaction_type' => ucfirst($this->reactionType),
            'source' => 'sync_service',
        ];
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->userId,
            'post_id' => $this->postId,
            'reaction_type' => $this->reactionType,
            'created_at' => $this->createdAt,
        ];
    }
}


