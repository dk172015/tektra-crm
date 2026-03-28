<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\CustomerWarningService;
use Illuminate\Console\Command;

class RefreshCustomerWarnings extends Command
{
    protected $signature = 'customers:refresh-warnings';
    protected $description = 'Refresh customer warning levels';

    public function handle(CustomerWarningService $service): int
    {
        Customer::query()->chunkById(200, function ($customers) use ($service) {
            foreach ($customers as $customer) {
                $service->refreshWarning($customer);
            }
        });

        $this->info('Customer warnings refreshed.');
        return self::SUCCESS;
    }
}