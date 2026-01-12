<?php

namespace App\DTOs;

/**
 * Post Entity Data Transfer Object
 * 
 * Platform-agnostic representation of a Post entity
 */
class PostDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $authorId,
        public readonly string $title,
        public readonly ?string $content = null,
        public readonly string $status = 'published',
        public readonly int $views = 0,
        public readonly ?string $createdAt = null,
        public readonly ?string $updatedAt = null,
    ) {}

    /**
     * Create from Legacy PostgreSQL data
     */
    public static function fromLegacy(array $data): self
    {
        return new self(
            id: $data['id'],
            authorId: $data['user_id'],
            title: $data['post_title'],
            content: $data['post_content'] ?? null,
            status: strtolower($data['post_status'] ?? 'published'),
            views: $data['view_count'] ?? 0,
            createdAt: $data['created_at'] ?? null,
            updatedAt: $data['updated_at'] ?? null,
        );
    }

    /**
     * Create from Revamped MySQL data
     */
    public static function fromRevamp(array $data): self
    {
        return new self(
            id: $data['id'],
            authorId: $data['author_id'],
            title: $data['title'],
            content: $data['content'] ?? null,
            status: strtolower($data['status'] ?? 'published'),
            views: $data['views'] ?? 0,
            createdAt: $data['created_at'] ?? null,
            updatedAt: $data['updated_at'] ?? null,
        );
    }

    /**
     * Convert to Legacy PostgreSQL format
     */
    public function toLegacy(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->authorId,
            'post_title' => $this->title,
            'post_content' => $this->content,
            'post_status' => $this->status,
            'view_count' => $this->views,
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
            'author_id' => $this->authorId,
            'title' => $this->title,
            'content' => $this->content,
            'status' => ucfirst($this->status),
            'views' => $this->views,
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
            'author_id' => $this->authorId,
            'title' => $this->title,
            'content' => $this->content,
            'status' => $this->status,
            'views' => $this->views,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}


