<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\CustomerPortalOtp;

class DeleteExpiredCustomerPortalOtps extends Command
{
    protected $signature = 'customer-portal:delete-expired-otps';

    protected $description = 'Delete expired customer portal OTPs';

    public function handle(): int
    {
        $deleted = CustomerPortalOtp::where(
            'expires_at',
            '<=',
            now()
        )->delete();

        $this->info("Deleted {$deleted} expired OTP records.");

        return self::SUCCESS;
    }
}
