<?php

namespace App\Enums;

enum AuthMethod: string
{
    case Email = 'email';
    case Phone = 'phone';

    /**
     * The users table column that holds this identifier.
     */
    public function column(): string
    {
        return $this->value;
    }

    /**
     * The users table column that records when this identifier was verified.
     */
    public function verifiedColumn(): string
    {
        return $this->value.'_verified_at';
    }
}
