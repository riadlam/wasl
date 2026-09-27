<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class BusinessPaymentMethod extends Model
{
    use BelongsToBusiness;

    public const METHOD_FLEXY = 'flexy';

    public const METHOD_BARIDIMOB = 'baridimob';

    public const METHOD_CCP = 'ccp';

    public const METHODS = [
        self::METHOD_FLEXY,
        self::METHOD_BARIDIMOB,
        self::METHOD_CCP,
    ];

    protected $fillable = [
        'business_id',
        'method',
        'enabled',
        'priority',
        'phone',
        'ccp_cle',
        'ccp_number',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'priority' => 'integer',
        ];
    }
}
