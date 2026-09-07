<?php

namespace App\Notifications\Customer;

use App\Models\Cancellation;
use App\Models\Refund;

/**
 * The answer to something the shopper asked for.
 *
 * A cancellation or a return is the one place a shopper is genuinely waiting on
 * a decision from us rather than on a parcel from a courier — and until now the
 * only way to learn the answer was to keep opening the app. That is the worst
 * kind of silence: the shopper has already told us something is wrong.
 *
 * Deliberately carries no push preference. Turning off order updates means "do
 * not narrate my parcel"; it cannot reasonably mean "do not tell me whether you
 * agreed to refund me".
 */
class RequestDecided extends ShopperNotification
{
    public function __construct(
        private readonly Cancellation|Refund $request,
        private readonly string $decision,
    ) {}

    public static function worthTelling(string $decision): bool
    {
        return in_array($decision, ['approved', 'rejected', 'processed'], true);
    }

    public static function kind(): string
    {
        return 'request';
    }

    private function isReturn(): bool
    {
        return $this->request instanceof Refund;
    }

    public function title(): string
    {
        $noun = $this->isReturn() ? 'Return' : 'Cancellation';

        return match ($this->decision) {
            'approved' => "{$noun} approved",
            'rejected' => "{$noun} declined",
            'processed' => 'Refund on its way',
            default => "{$noun} updated",
        };
    }

    public function body(): string
    {
        $number = $this->request->number;
        $order = $this->request->order?->number;
        $on = $order ? " on {$order}" : '';

        return match ($this->decision) {
            'approved' => $this->isReturn()
                ? "Your return {$number}{$on} was approved. Send the item back and the refund follows."
                : "Your cancellation {$number}{$on} was approved. Anything already paid is refunded.",
            // The reason lives on the request itself, and the app shows it —
            // repeating it in a push that may be truncated helps nobody.
            'rejected' => "Your request {$number}{$on} was declined. Open it to see why.",
            'processed' => "The refund for {$number}{$on} has been settled.",
            default => "There is an update on {$number}{$on}.",
        };
    }

    public function link(): string
    {
        return ($this->isReturn() ? '/requests/returns/' : '/requests/cancellations/').$this->request->number;
    }

    protected function groupTag(): string
    {
        return 'request-'.$this->request->number;
    }
}
