<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Admin\NotificationController as BaseNotificationController;

/**
 * The seller panel's notification centre.
 *
 * The parent already reads nothing but `$request->user()`'s own pile, so a
 * seller reaching it through /seller sees exactly what they saw through
 * /admin — only now without ever leaving their own panel.
 */
class NotificationController extends BaseNotificationController
{
    protected string $panel = 'seller';
}
