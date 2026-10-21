<?php

namespace App\Http\Requests;

use App\Models\PaymentMethod;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaymentMethodRequest extends FormRequest
{
    /**
     * Validates a saved card (gateway token, or number, expiry and CVC) or a PayPal email.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tokenised = $this->filled('token');
        // A card typed into our own form; with Moyasar the website sends a `token` instead.
        $card = $this->input('type') === PaymentMethod::CARD && ! $tokenised;

        return [
            /** Card: a Moyasar token made in the browser (`token_...`). Replaces holderName, number, expMonth, expYear and cvc. */
            'token' => ['nullable', 'string', 'max:100', 'regex:/^token_[A-Za-z0-9]+$/'],
            /** `card` or `paypal`. */
            'type' => ['required', Rule::in([PaymentMethod::CARD, PaymentMethod::PAYPAL])],
            /** Card: the name printed on the card. */
            'holderName' => [Rule::requiredIf($card), 'nullable', 'string', 'min:2', 'max:100', "regex:/^[\pL\s.'-]+$/u"],
            /** Card: the card number; only the brand and the last 4 digits are kept. */
            'number' => [Rule::requiredIf($card), 'nullable', 'string', $this->luhn(...)],
            'expMonth' => [Rule::requiredIf($card), 'nullable', 'integer', 'between:1,12'],
            'expYear' => [Rule::requiredIf($card), 'nullable', 'integer', 'between:2000,2100', $this->notExpired(...)],
            /** Card: 3 digits (4 for American Express). Checked by the gateway, never stored. */
            'cvc' => [Rule::requiredIf($card), 'nullable', 'string', 'regex:/^\d{3,4}$/'],
            /** PayPal: the account email. */
            'email' => [Rule::requiredIf($this->input('type') === PaymentMethod::PAYPAL), 'nullable', 'email', 'max:255'],
            /** Make it the preferred method. The first saved method always is. */
            'makeDefault' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The card number with spaces and dashes removed.
     */
    public function digits(): string
    {
        return preg_replace('/\D/', '', (string) $this->input('number'));
    }

    /**
     * Rejects card numbers that are not 13 to 19 digits or fail the Luhn checksum.
     */
    private function luhn(string $attribute, mixed $value, Closure $fail): void
    {
        $digits = preg_replace('/[\s-]/', '', (string) $value);

        if (! preg_match('/^\d{13,19}$/', $digits)) {
            $fail(__('payments.card_number_invalid'));

            return;
        }

        $sum = 0;
        foreach (array_reverse(str_split($digits)) as $index => $digit) {
            $digit = (int) $digit * ($index % 2 ? 2 : 1);
            $sum += $digit > 9 ? $digit - 9 : $digit;
        }

        if ($sum % 10 !== 0) {
            $fail(__('payments.card_number_invalid'));
        }
    }

    /**
     * Rejects a card whose expiry month and year are already past.
     */
    private function notExpired(string $attribute, mixed $value, Closure $fail): void
    {
        $month = (int) $this->input('expMonth');

        if ($month && now()->startOfMonth()->greaterThan(now()->setDate((int) $value, $month, 1)->startOfMonth())) {
            $fail(__('payments.card_expired'));
        }
    }
}
