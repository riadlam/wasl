<?php

namespace App\Mcp;

use App\Models\Business;
use App\Models\CustomerAiSetting;

class CustomerAiPolicy
{
    public static function allowsOrders(Business $business): bool
    {
        return (bool) CustomerAiSetting::forBusiness($business)->allow_order_creation;
    }
}
