<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Admin\OrderController as MarketplaceOrderController;

/**
 * Manual order entry for a seller — the marketplace flow, rendered into the
 * seller panel.
 *
 * `create()` and `store()` are the two methods on the marketplace controller
 * that already scope by store on their own: `create()` narrows the vendor list
 * and the product picker to the signed-in vendor, and `store()` takes the
 * vendor id from the user rather than the payload for anyone who `isVendor()`.
 * There is nothing left to re-scope, so this subclasses rather than copies.
 *
 * Only those two are routed under /seller. The rest of the parent — the
 * marketplace-wide index, show, status and payment screens — stays unreachable
 * here, and `Seller\OrderController` serves store-scoped versions instead.
 */
class ManualOrderController extends MarketplaceOrderController
{
    protected string $panel = 'seller';
}
