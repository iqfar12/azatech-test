<?php

declare(strict_types=1);

namespace App\Enums;

enum StockMovementType: string
{
    case Initial = 'INITIAL';
    case OrderOut = 'ORDER_OUT';
    case CancelRestore = 'CANCEL_RESTORE';
    case Adjustment = 'ADJUSTMENT';
}
