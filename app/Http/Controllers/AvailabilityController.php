<?php

namespace App\Http\Controllers;

use App\Models\ApiEndpoint;
use App\Models\ApiLog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AvailabilityController extends Controller
{
    /**
     * Affiche le tableau de bord des disponibilités
     */
    public function index(Request $request)
    {
        $period = $request->get('period', '24h');
        $environment = $request->get('environment');
        $priority = $request->get('priority');

        // Calcul des dates selon la période
        $dates = $this->calculatePeriodDates($period);

        // Récupération des APIs avec filtres
        $apisQuery = ApiEndpoint::with(['lastLog', 'recentLogs']);

        if ($environment) {
            $apisQuery->environment($environment);
        }

        if ($priority) {
            $apisQuery->priority($priority);
        }

        $apis = $apisQuery->get();

        // Calcul des statistiques pour chaque API
        $apiStats = [];
        foreach ($apis as $api) {
            $stats = $this->calculateApiAvailability($api, $dates['start'], $dates['end']);
            $apiStats[] = [
                'api' => $api,
                'stats' => $stats
            ];
        }

        // Statistiques globales
        $globalStats = $this->calculateGlobalStats($dates['start'], $dates['end']);

        return view('monitoring.availability.index', compact(
            'apiStats',
            'globalStats',
            'period',
            'environment',
            'priority'
        ));
    }

    /**
     * Affiche les détails de disponibilité d'une API spécifique
     */
    public function show(Request $request, ApiEndpoint $api)
    {
        $period = $request->get('period', '7d');
        $dates = $this->calculatePeriodDates($period);

        // Statistiques détaillées
        $stats = $this->calculateApiAvailability($api, $dates['start'], $dates['end']);

        // Données pour les graphiques (par heure/jour selon la période)
        $chartData = $this->getChartData($api, $dates['start'], $dates['end'], $period);

        // Incidents récents
        $recentIncidents = $this->getRecentIncidents($api, $dates['start'], $dates['end']);

        // Temps de réponse moyen par jour
        $responseTimeData = $this->getResponseTimeData($api, $dates['start'], $dates['end']);

        return view('monitoring.availability.show', compact(
            'api',
            'stats',
            'chartData',
            'recentIncidents',
            'responseTimeData',
            'period'
        ));
    }

    /**
     * API endpoint pour récupérer les statistiques en JSON
     */
    public function stats(Request $request): JsonResponse
    {
        $period = $request->get('period', '24h');
        $apiId = $request->get('api_id');

        $dates = $this->calculatePeriodDates($period);

        if ($apiId) {
            // Statistiques pour une API spécifique
            $api = ApiEndpoint::findOrFail($apiId);
            $stats = $this->calculateApiAvailability($api, $dates['start'], $dates['end']);

            return response()->json([
                'api' => $api->only(['id', 'name', 'url', 'last_status']),
                'period' => $period,
                'stats' => $stats,
                'chart_data' => $this->getChartData($api, $dates['start'], $dates['end'], $period)
            ]);
        } else {
            // Statistiques globales
            $globalStats = $this->calculateGlobalStats($dates['start'], $dates['end']);
            $apiStats = [];

            $apis = ApiEndpoint::all();
            foreach ($apis as $api) {
                $apiStats[] = [
                    'api' => $api->only(['id', 'name', 'url', 'last_status', 'environment', 'priority']),
                    'stats' => $this->calculateApiAvailability($api, $dates['start'], $dates['end'])
                ];
            }

            return response()->json([
                'period' => $period,
                'global_stats' => $globalStats,
                'apis' => $apiStats
            ]);
        }
    }

    /**
     * Données en temps réel pour le dashboard
     */
    public function realtime(): JsonResponse
    {
        $apis = ApiEndpoint::with('lastLog')->get();

        $realTimeData = [];
        foreach ($apis as $api) {
            $consecutiveFailures = $api->getConsecutiveFailures();
            $lastCheck = $api->lastLog;

            $realTimeData[] = [
                'id' => $api->id,
                'name' => $api->name,
                'status' => $api->last_status,
                'status_icon' => $api->status_icon,
                'consecutive_failures' => $consecutiveFailures,
                'last_check' => $lastCheck ? [
                    'checked_at' => $lastCheck->checked_at->format('Y-m-d H:i:s'),
                    'response_code' => $lastCheck->response_code,
                    'response_time' => $lastCheck->response_time,
                    'status_message' => $lastCheck->status_message
                ] : null,
                'next_check_in' => $api->next_check_in ?? 0
            ];
        }

        return response()->json([
            'timestamp' => now()->toISOString(),
            'apis' => $realTimeData,
            'global_stats' => ApiEndpoint::getGlobalStats()
        ]);
    }

    /**
     * Comparaison de disponibilité entre APIs
     */
    public function compare(Request $request): JsonResponse
    {
        $apiIds = $request->get('api_ids', []);
        $period = $request->get('period', '7d');

        if (empty($apiIds)) {
            return response()->json(['error' => 'Au moins une API doit être sélectionnée'], 400);
        }

        $dates = $this->calculatePeriodDates($period);
        $comparison = [];

        foreach ($apiIds as $apiId) {
            $api = ApiEndpoint::find($apiId);
            if ($api) {
                $stats = $this->calculateApiAvailability($api, $dates['start'], $dates['end']);
                $comparison[] = [
                    'api' => $api->only(['id', 'name', 'url', 'environment']),
                    'stats' => $stats
                ];
            }
        }

        return response()->json([
            'period' => $period,
            'comparison' => $comparison
        ]);
    }

    /**
     * Export des données de disponibilité
     */
    public function export(Request $request)
    {
        $period = $request->get('period', '30d');
        $format = $request->get('format', 'csv');  // csv, xlsx, json

        $dates = $this->calculatePeriodDates($period);
        $apis = ApiEndpoint::all();

        $exportData = [];
        foreach ($apis as $api) {
            $stats = $this->calculateApiAvailability($api, $dates['start'], $dates['end']);
            $exportData[] = [
                'API Name' => $api->name,
                'URL' => $api->url,
                'Environment' => $api->environment,
                'Priority' => $api->priority,
                'Current Status' => $api->last_status,
                'Uptime %' => $stats['uptime_percentage'],
                'Total Checks' => $stats['total_checks'],
                'Successful Checks' => $stats['successful_checks'],
                'Failed Checks' => $stats['failed_checks'],
                'Avg Response Time (ms)' => $stats['average_response_time'],
                'Max Response Time (ms)' => $stats['max_response_time'],
                'Total Downtime (min)' => $stats['total_downtime_minutes'],
                'Longest Outage (min)' => $stats['longest_outage_minutes']
            ];
        }

        $filename = "api_availability_report_{$period}_" . now()->format('Y-m-d_H-i-s');

        switch ($format) {
            case 'json':
                return response()
                    ->json($exportData)
                    ->header('Content-Disposition', "attachment; filename=\"{$filename}.json\"");

            case 'xlsx':
                // return Excel::download(new AvailabilityExport($exportData), "{$filename}.xlsx");

            case 'csv':
            default:
                $csv = $this->arrayToCsv($exportData);
                return response($csv)
                    ->header('Content-Type', 'text/csv')
                    ->header('Content-Disposition', "attachment; filename=\"{$filename}.csv\"");
        }
    }

    /**
     * Historique des incidents
     */
    public function incidents(Request $request): JsonResponse
    {
        $period = $request->get('period', '30d');
        $apiId = $request->get('api_id');
        $severity = $request->get('severity');

        $dates = $this->calculatePeriodDates($period);

        $incidentsQuery = ApiLog::betweenDates($dates['start'], $dates['end'])
            ->where('status', 'down')
            ->with('apiEndpoint');

        if ($apiId) {
            $incidentsQuery->where('api_endpoint_id', $apiId);
        }

        $incidents = $incidentsQuery->orderBy('checked_at', 'desc')->get();

        // Grouper les incidents consécutifs
        $groupedIncidents = $this->groupConsecutiveIncidents($incidents);

        return response()->json([
            'period' => $period,
            'incidents' => $groupedIncidents
        ]);
    }

    // =================== MÉTHODES PRIVÉES ===================

    /**
     * Calcule les dates de début et fin selon la période
     */
    private function calculatePeriodDates(string $period): array
    {
        $end = now();

        $start = match ($period) {
            '1h' => $end->copy()->subHour(),
            '6h' => $end->copy()->subHours(6),
            '24h' => $end->copy()->subDay(),
            '7d' => $end->copy()->subWeek(),
            '30d' => $end->copy()->subDays(30),
            '90d' => $end->copy()->subDays(90),
            '1y' => $end->copy()->subYear(),
            default => $end->copy()->subDay()
        };

        return compact('start', 'end');
    }

    /**
     * Calcule les statistiques de disponibilité d'une API
     */
    private function calculateApiAvailability(ApiEndpoint $api, Carbon $start, Carbon $end): array
    {
        $logs = $api->logs()->betweenDates($start, $end)->get();

        $totalChecks = $logs->count();
        $successfulChecks = $logs->where('status', 'up')->count();
        $failedChecks = $logs->where('status', 'down')->count();

        $uptimePercentage = $totalChecks > 0 ? round(($successfulChecks / $totalChecks) * 100, 2) : 100;

        $successfulLogs = $logs->where('status', 'up')->where('response_time', '>', 0);
        $averageResponseTime = $successfulLogs->count() > 0
            ? round($successfulLogs->avg('response_time'), 2)
            : null;
        $maxResponseTime = $successfulLogs->max('response_time');

        // Calcul du temps d'arrêt total
        $downtimeStats = $this->calculateDowntimeStats($api, $start, $end);

        return [
            'uptime_percentage' => $uptimePercentage,
            'total_checks' => $totalChecks,
            'successful_checks' => $successfulChecks,
            'failed_checks' => $failedChecks,
            'average_response_time' => $averageResponseTime,
            'max_response_time' => $maxResponseTime,
            'total_downtime_minutes' => $downtimeStats['total_minutes'],
            'longest_outage_minutes' => $downtimeStats['longest_outage'],
            'incident_count' => $downtimeStats['incident_count']
        ];
    }

    /**
     * Calcule les statistiques de temps d'arrêt
     */
    private function calculateDowntimeStats(ApiEndpoint $api, Carbon $start, Carbon $end): array
    {
        $downLogs = $api
            ->logs()
            ->betweenDates($start, $end)
            ->where('status', 'down')
            ->orderBy('checked_at')
            ->get();

        if ($downLogs->isEmpty()) {
            return [
                'total_minutes' => 0,
                'longest_outage' => 0,
                'incident_count' => 0
            ];
        }

        $incidents = $this->groupConsecutiveIncidents($downLogs);
        $totalMinutes = 0;
        $longestOutage = 0;

        foreach ($incidents as $incident) {
            $duration = $incident['duration_minutes'];
            $totalMinutes += $duration;
            $longestOutage = max($longestOutage, $duration);
        }

        return [
            'total_minutes' => round($totalMinutes, 2),
            'longest_outage' => round($longestOutage, 2),
            'incident_count' => count($incidents)
        ];
    }

    /**
     * Groupe les logs d'erreur consécutifs en incidents
     */
    private function groupConsecutiveIncidents($downLogs): array
    {
        if ($downLogs->isEmpty()) {
            return [];
        }

        $incidents = [];
        $currentIncident = null;

        foreach ($downLogs as $log) {
            if (!$currentIncident) {
                $currentIncident = [
                    'start_time' => $log->checked_at,
                    'end_time' => $log->checked_at,
                    'api_name' => $log->apiEndpoint->name,
                    'api_id' => $log->api_endpoint_id,
                    'logs_count' => 1,
                    'error_messages' => [$log->error_message]
                ];
            } else {
                // Si moins de 10 minutes d'écart, c'est le même incident
                $timeDiff = $log->checked_at->diffInMinutes($currentIncident['end_time']);

                if ($timeDiff <= 10) {
                    $currentIncident['end_time'] = $log->checked_at;
                    $currentIncident['logs_count']++;
                    if ($log->error_message) {
                        $currentIncident['error_messages'][] = $log->error_message;
                    }
                } else {
                    // Finaliser l'incident précédent
                    $currentIncident['duration_minutes'] = $currentIncident['start_time']
                        ->diffInMinutes($currentIncident['end_time']);
                    $currentIncident['error_messages'] = array_unique($currentIncident['error_messages']);
                    $incidents[] = $currentIncident;

                    // Commencer un nouveau incident
                    $currentIncident = [
                        'start_time' => $log->checked_at,
                        'end_time' => $log->checked_at,
                        'api_name' => $log->apiEndpoint->name,
                        'api_id' => $log->api_endpoint_id,
                        'logs_count' => 1,
                        'error_messages' => [$log->error_message]
                    ];
                }
            }
        }

        // Finaliser le dernier incident
        if ($currentIncident) {
            $currentIncident['duration_minutes'] = $currentIncident['start_time']
                ->diffInMinutes($currentIncident['end_time']);
            $currentIncident['error_messages'] = array_unique($currentIncident['error_messages']);
            $incidents[] = $currentIncident;
        }

        return $incidents;
    }

    /**
     * Calcule les statistiques globales
     */
    private function calculateGlobalStats(Carbon $start, Carbon $end): array
    {
        $apis = ApiEndpoint::all();
        $totalApis = $apis->count();
        $upApis = $apis->where('last_status', 'up')->count();
        $downApis = $apis->where('last_status', 'down')->count();

        $globalLogs = ApiLog::betweenDates($start, $end)->get();
        $totalChecks = $globalLogs->count();
        $successfulChecks = $globalLogs->where('status', 'up')->count();

        $globalUptime = $totalChecks > 0
            ? round(($successfulChecks / $totalChecks) * 100, 2)
            : 100;

        $averageResponseTime = $globalLogs
            ->where('status', 'up')
            ->where('response_time', '>', 0)
            ->avg('response_time');

        return [
            'total_apis' => $totalApis,
            'up_apis' => $upApis,
            'down_apis' => $downApis,
            'global_uptime' => $globalUptime,
            'total_checks' => $totalChecks,
            'average_response_time' => $averageResponseTime ? round($averageResponseTime, 2) : null
        ];
    }

    /**
     * Génère les données pour les graphiques
     */
    private function getChartData(ApiEndpoint $api, Carbon $start, Carbon $end, string $period): array
    {
        $interval = $this->getChartInterval($period);

        $data = $api
            ->logs()
            ->betweenDates($start, $end)
            ->selectRaw("
                DATE_FORMAT(checked_at, '{$interval['format']}') as period,
                COUNT(*) as total_checks,
                SUM(CASE WHEN status = 'up' THEN 1 ELSE 0 END) as successful_checks,
                AVG(CASE WHEN status = 'up' AND response_time IS NOT NULL THEN response_time END) as avg_response_time
            ")
            ->groupBy('period')
            ->orderBy('period')
            ->get();

        return $data->map(function ($item) {
            return [
                'period' => $item->period,
                'uptime_percentage' => $item->total_checks > 0
                    ? round(($item->successful_checks / $item->total_checks) * 100, 2)
                    : 100,
                'avg_response_time' => $item->avg_response_time ? round($item->avg_response_time, 2) : null
            ];
        })->toArray();
    }

    /**
     * Détermine l'intervalle pour les graphiques selon la période
     */
    private function getChartInterval(string $period): array
    {
        return match ($period) {
            '1h', '6h', '24h' => ['format' => '%Y-%m-%d %H:00:00', 'unit' => 'hour'],
            '7d' => ['format' => '%Y-%m-%d', 'unit' => 'day'],
            '30d', '90d' => ['format' => '%Y-%m-%d', 'unit' => 'day'],
            '1y' => ['format' => '%Y-%m', 'unit' => 'month'],
            default => ['format' => '%Y-%m-%d', 'unit' => 'day']
        };
    }

    /**
     * Récupère les incidents récents
     */
    private function getRecentIncidents(ApiEndpoint $api, Carbon $start, Carbon $end): array
    {
        $downLogs = $api
            ->logs()
            ->betweenDates($start, $end)
            ->where('status', 'down')
            ->orderBy('checked_at', 'desc')
            ->get();

        return $this->groupConsecutiveIncidents($downLogs);
    }

    /**
     * Récupère les données de temps de réponse
     */
    private function getResponseTimeData(ApiEndpoint $api, Carbon $start, Carbon $end): array
    {
        return $api
            ->logs()
            ->betweenDates($start, $end)
            ->where('status', 'up')
            ->whereNotNull('response_time')
            ->selectRaw('
                DATE(checked_at) as date,
                AVG(response_time) as avg_response_time,
                MAX(response_time) as max_response_time,
                MIN(response_time) as min_response_time
            ')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(function ($item) {
                return [
                    'date' => $item->date,
                    'avg_response_time' => round($item->avg_response_time, 2),
                    'max_response_time' => round($item->max_response_time, 2),
                    'min_response_time' => round($item->min_response_time, 2)
                ];
            })
            ->toArray();
    }

    /**
     * Convertit un tableau en CSV
     */
    private function arrayToCsv(array $data): string
    {
        if (empty($data)) {
            return '';
        }

        $csv = '';
        $headers = array_keys($data[0]);
        $csv .= implode(',', $headers) . "\n";

        foreach ($data as $row) {
            $csv .= implode(',', array_map(function ($value) {
                return '"' . str_replace('"', '""', $value) . '"';
            }, $row)) . "\n";
        }

        return $csv;
    }
}
