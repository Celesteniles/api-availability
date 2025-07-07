<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class AlertRule extends Model
{
    protected $casts = [
        'conditions' => 'array',
        'is_active' => 'boolean'
    ];

    public function apiEndpoint(): BelongsTo
    {
        return $this->belongsTo(ApiEndpoint::class);
    }

    public function shouldTrigger(ApiLog $log, ApiEndpoint $api): bool
    {
        if (!$this->is_active) {
            return false;
        }

        return match ($this->rule_type) {
            'consecutive_failures' => $this->checkConsecutiveFailures($api),
            'response_time' => $this->checkResponseTime($log),
            'status_code' => $this->checkStatusCode($log),
            default => false
        };
    }

    private function checkConsecutiveFailures(ApiEndpoint $api): bool
    {
        $threshold = $this->conditions['failures'] ?? 3;
        return $api->consecutive_failures >= $threshold;
    }

    private function checkResponseTime(ApiLog $log): bool
    {
        $threshold = $this->conditions['threshold'] ?? 5000;  // 5 secondes
        return $log->response_time && $log->response_time > $threshold;
    }

    private function checkStatusCode(ApiLog $log): bool
    {
        $expectedCodes = $this->conditions['expected_codes'] ?? [200, 201, 204];
        return !in_array($log->response_code, $expectedCodes);
    }
}
