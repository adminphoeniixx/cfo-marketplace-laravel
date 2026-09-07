<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $heading
 * @property string $message
 * @property string|null $link
 * @property int $recipients
 * @property Carbon|null $created_at
 */
class PushBroadcast extends Model
{
    protected $guarded = [];

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
