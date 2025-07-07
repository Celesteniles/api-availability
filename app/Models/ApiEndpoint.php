<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ApiEndpoint extends Model
{
    protected $fillable = [
        'name',
        'url',
        'description',
        'last_status',
        'check_interval',
        'is_active',
        'timeout',
        'expected_status_code',
        'expected_content',
        'last_checked_at',
        'consecutive_failures',
        // 'environment',
        // 'priority',
        'team_responsible',
        // 'maintenance_window_start',
        // 'maintenance_window_end'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_checked_at' => 'datetime',
        'consecutive_failures' => 'integer',
        'check_interval' => 'integer',
        'timeout' => 'integer',
        'maintenance_window_start' => 'datetime',
        'maintenance_window_end' => 'datetime'
    ];

    protected $attributes = [
        'check_interval' => 300,  // 5 minutes par défaut
        'is_active' => true,
        'timeout' => 10,
        'expected_status_code' => '200',
        'consecutive_failures' => 0,
        'priority' => 'medium',
        'environment' => 'production'
    ];

    // =================== RELATIONS ===================

    /**
     * Tous les logs de cette API
     */
    public function logs(): HasMany
    {
        return $this->hasMany(ApiLog::class)->orderBy('checked_at', 'desc');
    }

    /**
     * Le dernier log enregistré
     */
    public function lastLog(): HasOne
    {
        return $this->hasOne(ApiLog::class)->latestOfMany('checked_at');
    }

    /**
     * Les logs des dernières 24h
     */
    public function recentLogs(): HasMany
    {
        return $this
            ->hasMany(ApiLog::class)
            ->where('checked_at', '>=', now()->subDay())
            ->orderBy('checked_at', 'desc');
    }

    /**
     * Les règles d'alertes configurées
     */
    public function alertRules(): HasMany
    {
        return $this->hasMany(AlertRule::class);
    }

    /**
     * Les logs de pannes uniquement
     */
    public function downLogs(): HasMany
    {
        return $this->hasMany(ApiLog::class)->where('status', 'down');
    }

    // =================== SCOPES ===================

    /**
     * APIs actives seulement
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * APIs en panne actuellement
     */
    public function scopeDown(Builder $query): Builder
    {
        return $query->where('last_status', 'down');
    }

    /**
     * APIs opérationnelles
     */
    public function scopeUp(Builder $query): Builder
    {
        return $query->where('last_status', 'up');
    }

    /**
     * APIs par environnement
     */
    public function scopeEnvironment(Builder $query, string $env): Builder
    {
        return $query->where('environment', $env);
    }

    /**
     * APIs par priorité
     */
    public function scopePriority(Builder $query, string $priority): Builder
    {
        return $query->where('priority', $priority);
    }

    /**
     * APIs qui doivent être vérifiées maintenant
     */
    public function scopeDueForCheck(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q
                ->whereNull('last_checked_at')
                ->orWhereRaw('last_checked_at <= NOW() - INTERVAL check_interval SECOND');
        });
    }

    /**
     * APIs en maintenance
     */
    public function scopeInMaintenance(Builder $query): Builder
    {
        $now = now();
        return $query->where(function ($q) use ($now) {
            $q
                ->where('maintenance_window_start', '<=', $now)
                ->where('maintenance_window_end', '>=', $now);
        });
    }

    // =================== MUTATORS & ACCESSORS ===================

    /**
     * Nettoie l'URL avant sauvegarde
     */
    public function setUrlAttribute($value): void
    {
        $this->attributes['url'] = rtrim($value, '/');
    }

    /**
     * Formate le nom pour l'affichage
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->name . ' (' . $this->environment . ')';
    }

    /**
     * Retourne l'icône de statut
     */
    public function getStatusIconAttribute(): string
    {
        return match ($this->last_status) {
            'up' => '🟢',
            'down' => '🔴',
            default => '⚪'
        };
    }

    /**
     * Retourne la classe CSS du statut
     */
    public function getStatusClassAttribute(): string
    {
        return match ($this->last_status) {
            'up' => 'success',
            'down' => 'danger',
            default => 'secondary'
        };
    }

    /**
     * Temps jusqu'à la prochaine vérification
     */
    public function getNextCheckInAttribute(): ?int
    {
        if (!$this->last_checked_at) {
            return 0;
        }

        $nextCheck = $this->last_checked_at->addSeconds($this->check_interval);
        return max(0, $nextCheck->diffInSeconds(now()));
    }

    // =================== MÉTHODES MÉTIER ===================

    /**
     * Vérifie si l'API est en maintenance
     */
    public function isInMaintenance(): bool
    {
        if (!$this->maintenance_window_start || !$this->maintenance_window_end) {
            return false;
        }

        $now = now();
        return $now->between($this->maintenance_window_start, $this->maintenance_window_end);
    }

    /**
     * Marque l'API comme vérifiée
     */
    public function markAsChecked(): void
    {
        $this->update(['last_checked_at' => now()]);
    }

    /**
     * Incrémente le compteur d'échecs consécutifs
     */
    public function incrementFailures(): void
    {
        $this->increment('consecutive_failures');
    }

    /**
     * Remet à zéro le compteur d'échecs
     */
    public function resetFailures(): void
    {
        $this->update(['consecutive_failures' => 0]);
    }

    /**
     * Met à jour le statut et gère les échecs consécutifs
     */
    public function updateStatus(string $status): void
    {
        $oldStatus = $this->last_status;

        $this->update(['last_status' => $status]);

        if ($status === 'down') {
            $this->incrementFailures();
        } elseif ($status === 'up' && $oldStatus === 'down') {
            $this->resetFailures();
        }
    }

    /**
     * Calcule le taux de disponibilité sur une période
     */
    public function getUptimePercentage(Carbon $startDate = null, Carbon $endDate = null): float
    {
        $startDate = $startDate ?? now()->subDays(30);
        $endDate = $endDate ?? now();

        $totalLogs = $this
            ->logs()
            ->whereBetween('checked_at', [$startDate, $endDate])
            ->count();

        if ($totalLogs === 0) {
            return 100.0;
        }

        $upLogs = $this
            ->logs()
            ->whereBetween('checked_at', [$startDate, $endDate])
            ->where('status', 'up')
            ->count();

        return round(($upLogs / $totalLogs) * 100, 2);
    }

    /**
     * Temps de réponse moyen sur une période
     */
    public function getAverageResponseTime(Carbon $startDate = null, Carbon $endDate = null): ?float
    {
        $startDate = $startDate ?? now()->subDays(7);
        $endDate = $endDate ?? now();

        return $this
            ->logs()
            ->whereBetween('checked_at', [$startDate, $endDate])
            ->whereNotNull('response_time')
            ->where('status', 'up')
            ->avg('response_time');
    }

    /**
     * Dernière panne enregistrée
     */
    public function getLastDowntime(): ?ApiLog
    {
        return $this
            ->logs()
            ->where('status', 'down')
            ->first();
    }

    /**
     * Durée de la panne actuelle (si en panne)
     */
    public function getCurrentDowntimeDuration(): ?int
    {
        if ($this->last_status !== 'down') {
            return null;
        }

        $lastDownLog = $this->getLastDowntime();

        return $lastDownLog
            ? $lastDownLog->checked_at->diffInMinutes(now())
            : null;
    }

    /**
     * Vérifie si l'API doit être contrôlée maintenant
     */
    public function isDueForCheck(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if ($this->isInMaintenance()) {
            return false;
        }

        if (!$this->last_checked_at) {
            return true;
        }

        return $this->last_checked_at->addSeconds($this->check_interval)->isPast();
    }

    /**
     * Obtient la configuration de timeout avec fallback
     */
    public function getEffectiveTimeout(): int
    {
        return $this->timeout ?: config('monitoring.default_timeout', 10);
    }

    /**
     * Formate les codes de statut attendus
     */
    public function getExpectedStatusCodes(): array
    {
        if (is_string($this->expected_status_code)) {
            return explode(',', $this->expected_status_code);
        }

        return [(string) $this->expected_status_code];
    }

    // =================== MÉTHODES STATIQUES ===================

    /**
     * Trouve les APIs qui nécessitent une vérification
     */
    public static function needingCheck(): Builder
    {
        return static::active()->dueForCheck();
    }

    /**
     * Statistiques globales des APIs
     */
    public static function getGlobalStats(): array
    {
        $total = static::count();
        $active = static::active()->count();
        $up = static::up()->count();
        $down = static::down()->count();

        return compact('total', 'active', 'up', 'down');
    }
}
