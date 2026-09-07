<?php

namespace App\Contracts;

/**
 * One install Firebase will deliver to, whoever it belongs to.
 *
 * The seller app and the shopper app keep their tokens in separate tables —
 * they are separate audiences with separate ids, and mixing them would be one
 * cast away from delivering a seller's payout to a shopper. The delivery job
 * does not care which of the two it holds, though: a token is a token, a dead
 * one is dropped, and a sent one is stamped. That is the whole of this
 * contract, and the reason `SendFcmMessage` can serve both.
 */
interface PushDevice
{
    /** The registration token to hand to Firebase. */
    public function pushToken(): string;

    /** Remember that a message went out, for the panel's "last seen" column. */
    public function markPushSent(): void;
}
