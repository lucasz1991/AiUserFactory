<?php

namespace App\Support\Push;

use Minishlink\WebPush\MessageSentReport;

class WebPushReport
{
    public static function isTransient(MessageSentReport $report): bool
    {
        if ($report->isSuccess() || $report->isSubscriptionExpired()) {
            return false;
        }

        $status = $report->getResponse()?->getStatusCode();

        return $status === null
            || in_array($status, [408, 425, 429], true)
            || $status >= 500;
    }
}
