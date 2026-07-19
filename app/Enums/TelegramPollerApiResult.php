<?php

namespace App\Enums;

enum TelegramPollerApiResult: string
{
    case Success = 'success';
    case TransientFailure = 'transient_failure';
    case PermanentFailure = 'permanent_failure';
}
