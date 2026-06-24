<?php

namespace App\Jobs;

use App\Services\Mail\MailAccountRegistrationRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckMailRegistrationWebmailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 85;

    public int $tries = 1;

    public function __construct(
        public string $runId,
    ) {
        $this->onConnection('database');
    }

    public function handle(MailAccountRegistrationRunner $runner): void
    {
        $runner->checkVerificationWebmail($this->runId);
    }
}
