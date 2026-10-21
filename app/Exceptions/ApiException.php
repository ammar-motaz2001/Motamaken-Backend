<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * A business-rule refusal. Answers `{message, code}`: the message is for the user (translated), the code is
 * stable so the frontend can react, e.g. send people without a profile to "Add Your Profile".
 */
class ApiException extends Exception
{
    /**
     * @param  array<string, mixed>  $extra  More fields for the body, e.g. the list of things blocking an action.
     */
    public function __construct(string $message, private readonly string $errorCode, private readonly int $status = 422, private readonly array $extra = [])
    {
        parent::__construct($message);
    }

    /**
     * The JSON answer: the message, the stable error code and any extra fields, with the chosen status.
     */
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode, ...$this->extra], $this->status);
    }
}
