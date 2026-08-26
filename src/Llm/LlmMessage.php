<?php
declare(strict_types=1);

namespace App\Llm;

final class LlmMessage
{
    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';
    public const ROLE_SYSTEM = 'system';

    public function __construct(
        public readonly string $role,
        public readonly string $content,
    ) {
        if (!in_array($role, [self::ROLE_USER, self::ROLE_ASSISTANT, self::ROLE_SYSTEM], true)) {
            throw new \InvalidArgumentException("Invalid role: {$role}");
        }
    }

    public static function user(string $content): self
    {
        return new self(self::ROLE_USER, $content);
    }

    public static function assistant(string $content): self
    {
        return new self(self::ROLE_ASSISTANT, $content);
    }

    public static function system(string $content): self
    {
        return new self(self::ROLE_SYSTEM, $content);
    }

    public function toArray(): array
    {
        return ['role' => $this->role, 'content' => $this->content];
    }
}
