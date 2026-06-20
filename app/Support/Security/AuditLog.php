<?php

namespace App\Support\Security;

use Illuminate\Support\Facades\Log;

class AuditLog
{
    public static function warning(string $action, array $context = []): void
    {
        Log::warning('[AUDIT] '.$action, $context);
    }

    public static function info(string $action, array $context = []): void
    {
        Log::info('[AUDIT] '.$action, $context);
    }
}
