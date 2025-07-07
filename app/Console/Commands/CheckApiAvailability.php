<?php

namespace App\Console\Commands;

use App\Models\ApiEndpoint;
use App\Models\ApiLog;
use App\Models\User;
use App\Nscreative\Src\Facades\Nscreative;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class CheckApiAvailability extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:api-availability';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $apis = ApiEndpoint::all();
        foreach ($apis as $api) {
            $response = Http::timeout(10)->get($api->url);
            $isAvailable = $response->successful();

            // Log state & trigger alert if necessary
            ApiLog::create([
                'api_endpoint_id' => $api->id,
                'status' => $isAvailable ? 'up' : 'down',
                'response_code' => $response->status(),
                'checked_at' => now()
            ]);

            if ($api->last_status !== ($isAvailable ? 'up' : 'down')) {
                $this->sendAlert($api, $isAvailable);
                $api->update(['last_status' => $isAvailable ? 'up' : 'down']);
            }
        }
    }

    protected function sendAlert($api, $isAvailable)
    {
        $admins = User::all();

        $status = $isAvailable ? 'UP' : 'DOWN';

        foreach ($admins as $admin) {
            $text = "WARNING : YOUR API {$api->name} ({$api->url}) IS {$status}";
            Nscreative::sendSms($admin->phone, $text);
        }
    }
}
