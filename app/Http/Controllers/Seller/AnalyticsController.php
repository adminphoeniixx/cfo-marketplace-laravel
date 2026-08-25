<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Admin\AnalyticsController as BaseAnalyticsController;

/**
 * The seller panel's analytics screen.
 *
 * Every report in the parent is already built on `resolveVendor()`, which
 * returns the signed-in user's own store for a vendor and ignores the request
 * entirely — so there is nothing to re-scope here. Subclassing rather than
 * copying keeps one implementation of the numbers for both panels: a fix to
 * the margin maths lands on the seller's screen the same day it lands on the
 * marketplace's.
 */
class AnalyticsController extends BaseAnalyticsController
{
    protected string $panel = 'seller';
}
