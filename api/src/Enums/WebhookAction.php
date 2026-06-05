<?php

namespace App\Enums;

/**
 * What a robot does with an incoming signal. Carried by the payload `action`
 * field; defaults to OPEN when absent (back-compat with open-only payloads).
 */
enum WebhookAction: string
{
    case OPEN = 'OPEN';     // place a new order
    case MODIFY = 'MODIFY'; // move SL/TP of a live order/position
    case CLOSE = 'CLOSE';   // close a position (optional size = partial)
    case CANCEL = 'CANCEL'; // cancel a pending order
}
