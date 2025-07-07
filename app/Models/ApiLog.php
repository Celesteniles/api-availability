<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ApiLog extends Model
{
    protected $fillable = [
        'api_endpoint_id',
        'status',
        'response_code',
        'response_time',
        'checked_at',
        'error_message',
        'response_headers',
        'response_body',
        'check_duration',
        'ssl_expiry_date',
        'dns_resolution_time'
    ];

    protected $casts = [
        'checked_at' => 'datetime',
        'response_time' => 'float',
        'response_code' => 'integer',
        'response_headers' => 'array',
        'check_duration' => 'float',
        'ssl_expiry_date' => 'datetime',
        'dns_resolution_time' => 'float'
    ];

    // Pas de timestamps automatiques, on utilise checked_at
    public $timestamps = false;

    // =================== RELATIONS ===================

    /**
     * L'API endpoint associé à ce log
     */
    public function apiEndpoint(): BelongsTo
    {
        return $this->belongsTo(ApiEndpoint::class);
    }

    // =================== SCOPES ===================

    /**
     * Logs de statut UP uniquement
     */
    public function scopeUp(Builder $query): Builder
    {
        return $query->where('status', 'up');
    }

    /**
     * Logs de statut DOWN uniquement
     */
    public function scopeDown(Builder $query): Builder
    {
        return $query->where('status', 'down');
    }

    /**
     * Logs des dernières 24h
     */
    public function scopeRecent(Builder $query): Builder
    {
        return $query->where('checked_at', '>=', now()->subDay());
    }

    /**
     * Logs de la dernière semaine
     */
    public function scopeLastWeek(Builder $query): Builder
    {
        return $query->where('checked_at', '>=', now()->subWeek());
    }

    /**
     * Logs avec erreurs
     */
    public function scopeWithErrors(Builder $query): Builder
    {
        return $query->whereNotNull('error_message');
    }

    /**
     * Logs avec temps de réponse lent
     */
    public function scopeSlowResponses(Builder $query, float $threshold = 5000): Builder
    {
        return $query->where('response_time', '>', $threshold);
    }

    /**
     * Logs par code de statut HTTP
     */
    public function scopeByStatusCode(Builder $query, int $code): Builder
    {
        return $query->where('response_code', $code);
    }

    /**
     * Logs dans une plage de dates
     */
    public function scopeBetweenDates(Builder $query, Carbon $start, Carbon $end): Builder
    {
        return $query->whereBetween('checked_at', [$start, $end]);
    }

    // =================== ACCESSORS ===================

    /**
     * Formatte le temps de réponse pour l'affichage
     */
    public function getFormattedResponseTimeAttribute(): string
    {
        if (!$this->response_time) {
            return 'N/A';
        }

        if ($this->response_time >= 1000) {
            return round($this->response_time / 1000, 2) . 's';
        }

        return round($this->response_time, 0) . 'ms';
    }

    /**
     * Icône du statut HTTP
     */
    public function getStatusIconAttribute(): string
    {
        if (!$this->response_code) {
            return '❌';
        }

        return match (true) {
            $this->response_code >= 200 && $this->response_code < 300 => '✅',
            $this->response_code >= 300 && $this->response_code < 400 => '↩️',
            $this->response_code >= 400 && $this->response_code < 500 => '⚠️',
            $this->response_code >= 500 => '🚨',
            default => '❓'
        };
    }

    /**
     * Message d'erreur formaté
     */
    public function getFormattedErrorAttribute(): ?string
    {
        if (!$this->error_message) {
            return null;
        }

        // Limite la longueur pour l'affichage
        return strlen($this->error_message) > 100
            ? substr($this->error_message, 0, 97) . '...'
            : $this->error_message;
    }

    /**
     * Classe CSS basée sur le temps de réponse
     */
    public function getResponseTimeClassAttribute(): string
    {
        if (!$this->response_time) {
            return 'text-muted';
        }

        return match (true) {
            $this->response_time <= 500 => 'text-success',
            $this->response_time <= 2000 => 'text-warning',
            default => 'text-danger'
        };
    }

    // =================== MÉTHODES MÉTIER ===================

    /**
     * Vérifie si ce log indique une API en bonne santé
     */
    public function isHealthy(): bool
    {
        return $this->status === 'up' &&
            $this->response_code >= 200 &&
            $this->response_code < 400;
    }

    /**
     * Vérifie si le temps de réponse est acceptable
     */
    public function hasAcceptableResponseTime(float $threshold = 5000): bool
    {
        return $this->response_time && $this->response_time <= $threshold;
    }

    /**
     * Obtient la catégorie du code de statut HTTP
     */
    public function getStatusCategory(): string
    {
        if (!$this->response_code) {
            return 'unknown';
        }

        return match (true) {
            $this->response_code >= 200 && $this->response_code < 300 => 'success',
            $this->response_code >= 300 && $this->response_code < 400 => 'redirect',
            $this->response_code >= 400 && $this->response_code < 500 => 'client_error',
            $this->response_code >= 500 => 'server_error',
            default => 'unknown'
        };
    }

    /**
     * Vérifie si le SSL expire bientôt
     */
    public function hasSslExpiryWarning(int $daysThreshold = 30): bool
    {
        if (!$this->ssl_expiry_date) {
            return false;
        }

        return $this->ssl_expiry_date->diffInDays(now()) <= $daysThreshold;
    }

    // =================== MÉTHODES STATIQUES ===================

    /**
     * Crée un log d'erreur de connexion
     */
    public static function createConnectionError(int $apiEndpointId, string $errorMessage): self
    {
        return static::create([
            'api_endpoint_id' => $apiEndpointId,
            'status' => 'down',
            'response_code' => null,
            'response_time' => null,
            'checked_at' => now(),
            'error_message' => $errorMessage
        ]);
    }

    /**
     * Crée un log de succès
     */
    public static function createSuccess(int $apiEndpointId, int $responseCode, float $responseTime, array $headers = []): self
    {
        return static::create([
            'api_endpoint_id' => $apiEndpointId,
            'status' => 'up',
            'response_code' => $responseCode,
            'response_time' => $responseTime,
            'checked_at' => now(),
            'response_headers' => $headers
        ]);
    }

    /**
     * Statistiques des logs pour une période
     */
    public static function getStatsForPeriod(Carbon $start, Carbon $end): array
    {
        $logs = static::betweenDates($start, $end);

        return [
            'total_checks' => $logs->count(),
            'successful_checks' => $logs->up()->count(),
            'failed_checks' => $logs->down()->count(),
            'average_response_time' => $logs->up()->avg('response_time'),
            'max_response_time' => $logs->up()->max('response_time'),
            'uptime_percentage' => $logs->count() > 0
                ? round(($logs->up()->count() / $logs->count()) * 100, 2)
                : 100
        ];
    }

    /**
     * Trouve les pics de latence
     */
    public static function findLatencySpikes(float $threshold = 5000, Carbon $since = null): Builder
    {
        $since = $since ?? now()->subDays(7);

        return static::where('checked_at', '>=', $since)
            ->where('response_time', '>', $threshold)
            ->orderBy('response_time', 'desc');
    }
}
