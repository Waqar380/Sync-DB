<?php

namespace App\DTOs;

/**
 * User Entity Data Transfer Object
 * 
 * Platform-agnostic representation of a User entity
 */
class UserDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $username,
        public readonly string $email,
        public readonly ?string $displayName = null,
        public readonly ?string $phone = null,
        public readonly string $status = 'active',
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
            username: $data['username'],
            email: $data['email'],
            displayName: $data['full_name'] ?? null,
            phone: $data['phone_number'] ?? null,
            status: strtolower($data['status'] ?? 'active'),
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
            username: $data['user_name'],
            email: $data['email_address'],
            displayName: $data['display_name'] ?? null,
            phone: $data['mobile'] ?? null,
            status: strtolower($data['account_status'] ?? 'active'),
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
            'username' => $this->username,
            'email' => $this->email,
            'full_name' => $this->displayName,
            'phone_number' => $this->phone,
            'status' => $this->status,
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
            'user_name' => $this->username,
            'email_address' => $this->email,
            'display_name' => $this->displayName,
            'mobile' => $this->phone,
            'account_status' => ucfirst($this->status),
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
            'username' => $this->username,
            'email' => $this->email,
            'display_name' => $this->displayName,
            'phone' => $this->phone,
            'status' => $this->status,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}


