<?php

namespace App\Services\Bycel;

/**
 * The result of trying to match a Bycel purchase intent to a row in
 * /last_pin_report. Deliberately a value object with no HTTP or database
 * dependencies, so every judgement call in BycelPinResolver is unit-testable in
 * isolation — this is the logic that decides whether a customer is handed a card.
 */
class BycelClaimOutcome
{
    /** Exactly one row matched; it is ours. */
    public const CLAIMED = 'claimed';
    /** Nothing matched yet, but a purchase may still have happened. Do not refund. */
    public const PENDING = 'pending';
    /** More than one row matched. NEVER auto-deliver — a human must choose. */
    public const AMBIGUOUS = 'ambiguous';
    /** Provably nothing was bought: the window is closed and no row matched. */
    public const NOT_PURCHASED = 'not_purchased';

    private function __construct(
        public string $status,
        public ?array $row = null,
        public array $candidates = [],
        public string $reason = '',
    ) {
    }

    public static function claimed(array $row): self
    {
        return new self(self::CLAIMED, $row);
    }

    public static function pending(string $reason): self
    {
        return new self(self::PENDING, null, [], $reason);
    }

    public static function ambiguous(array $candidates, string $reason = 'multiple candidate rows'): self
    {
        return new self(self::AMBIGUOUS, null, $candidates, $reason);
    }

    public static function notPurchased(string $reason): self
    {
        return new self(self::NOT_PURCHASED, null, [], $reason);
    }

    public function is(string $status): bool
    {
        return $this->status === $status;
    }
}
