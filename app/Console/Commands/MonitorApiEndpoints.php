<?php

namespace App\Console\Commands;

use App\Models\ApiEndpoint;
use App\Models\ApiLog;
use App\Models\User;
use App\Nscreative\Src\Facades\Nscreative;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MonitorApiEndpoints extends Command
{
    protected $signature = "api:monitor {--timeout=10 : Timeout en secondes} {--dry-run : Simulation sans envoi d'alertes}";
    protected $description = 'Monitore le statut des API endpoints et envoie des alertes en cas de changement';

    public function handle()
    {
        $timeout = $this->option('timeout');
        $dryRun = $this->option('dry-run');

        $this->info("🔍 Début du monitoring des APIs (timeout: {$timeout}s)");

        // Optimisation : récupération avec les relations nécessaires
        $apis = ApiEndpoint::with('lastLog')->get();

        if ($apis->isEmpty()) {
            $this->warn('⚠️  Aucune API à monitorer trouvée');
            return;
        }

        $this->info("📊 {$apis->count()} API(s) à vérifier");

        $results = [
            'checked' => 0,
            'up' => 0,
            'down' => 0,
            'alerts_sent' => 0,
            'errors' => 0
        ];

        foreach ($apis as $api) {
            try {
                $this->checkApi($api, $timeout, $dryRun, $results);
            } catch (\Exception $e) {
                $this->handleApiError($api, $e, $results);
            }
        }

        $this->displaySummary($results);
    }

    protected function checkApi($api, $timeout, $dryRun, &$results)
    {
        $this->line("🔍 Vérification de {$api->name} ({$api->url})");

        $startTime = microtime(true);

        try {
            $response = Http::timeout($timeout)
                ->retry(2, 1000)  // 2 tentatives avec 1s d'intervalle
                ->get($api->url);

            $responseTime = round((microtime(true) - $startTime) * 1000, 2);
            $isAvailable = $response->successful();
            $newStatus = $isAvailable ? 'up' : 'down';

            // Log détaillé
            $logData = [
                'api_endpoint_id' => $api->id,
                'status' => $newStatus,
                'response_code' => $response->status(),
                'response_time' => $responseTime,
                'checked_at' => now(),
                'error_message' => $isAvailable ? null : $this->getErrorMessage($response)
            ];

            ApiLog::create($logData);

            $results['checked']++;
            $results[$newStatus]++;

            // Affichage du résultat
            $statusIcon = $isAvailable ? '✅' : '❌';
            $this->line("   {$statusIcon} {$newStatus} ({$response->status()}) - {$responseTime}ms");

            // Vérification changement de statut
            if ($this->hasStatusChanged($api, $newStatus)) {
                if (!$dryRun) {
                    $this->sendAlert($api, $isAvailable, $responseTime);
                    $api->update(['last_status' => $newStatus]);
                    $results['alerts_sent']++;
                } else {
                    $this->warn('   📧 [DRY-RUN] Alerte qui serait envoyée pour changement de statut');
                }
            }
        } catch (\Illuminate\Http\Client\RequestException $e) {
            $this->handleRequestException($api, $e, $results);
        }
    }

    protected function hasStatusChanged($api, $newStatus)
    {
        return $api->last_status !== $newStatus;
    }

    protected function getErrorMessage($response)
    {
        $status = $response->status();

        $errorMessages = [
            400 => 'Requête invalide',
            401 => 'Non autorisé',
            403 => 'Accès interdit',
            404 => 'Ressource non trouvée',
            500 => 'Erreur serveur interne',
            502 => 'Mauvaise passerelle',
            503 => 'Service indisponible',
            504 => 'Timeout de la passerelle'
        ];

        return $errorMessages[$status] ?? "Erreur HTTP {$status}";
    }

    protected function handleRequestException($api, $exception, &$results)
    {
        $responseTime = null;
        $errorMessage = $this->getRequestExceptionMessage($exception);

        // Log de l'erreur
        ApiLog::create([
            'api_endpoint_id' => $api->id,
            'status' => 'down',
            'response_code' => null,
            'response_time' => $responseTime,
            'checked_at' => now(),
            'error_message' => $errorMessage
        ]);

        $results['checked']++;
        $results['down']++;

        $this->line('   ❌ down (erreur de connexion)');

        // Alerte si changement de statut
        if ($this->hasStatusChanged($api, 'down')) {
            $this->sendAlert($api, false, null, $errorMessage);
            $api->update(['last_status' => 'down']);
            $results['alerts_sent']++;
        }
    }

    protected function getRequestExceptionMessage($exception)
    {
        if (str_contains($exception->getMessage(), 'timeout')) {
            return 'Timeout de connexion';
        }
        if (str_contains($exception->getMessage(), 'Connection refused')) {
            return 'Connexion refusée';
        }
        if (str_contains($exception->getMessage(), 'Could not resolve host')) {
            return 'Nom de domaine non résolu';
        }

        return 'Erreur de connexion';
    }

    protected function handleApiError($api, $exception, &$results)
    {
        $this->error("❌ Erreur lors de la vérification de {$api->name}: {$exception->getMessage()}");

        Log::error("Erreur monitoring API {$api->name}", [
            'api_id' => $api->id,
            'url' => $api->url,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        $results['errors']++;
    }

    protected function sendAlert($api, $isAvailable, $responseTime = null, $errorDetails = null)
    {
        // Récupération optimisée des admins avec notifications activées
        $admins = User::where('receive_notifications', true)
            ->whereNotNull('phone')
            ->get();

        if ($admins->isEmpty()) {
            $this->warn('⚠️  Aucun administrateur avec notifications activées trouvé');
            return;
        }

        $message = $this->buildAlertMessage($api, $isAvailable, $responseTime, $errorDetails);

        foreach ($admins as $admin) {
            try {
                Nscreative::sendSms($admin->phone, $message);
                $this->line("   📧 Alerte envoyée à {$admin->name} ({$admin->phone})");
            } catch (\Exception $e) {
                $this->error("❌ Échec envoi SMS à {$admin->name}: {$e->getMessage()}");
                Log::error('Échec envoi alerte SMS', [
                    'admin_id' => $admin->id,
                    'phone' => $admin->phone,
                    'api' => $api->name,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    protected function buildAlertMessage($api, $isAvailable, $responseTime = null, $errorDetails = null)
    {
        $timestamp = now()->format('d/m/Y H:i');
        $status = $isAvailable ? 'RÉTABLI' : 'INDISPONIBLE';

        if ($isAvailable) {
            $message = "API RÉTABLIE\n";
            $message .= "{$api->name}\n";
            $message .= "{$api->url}\n";
            if ($responseTime) {
                $message .= "Temps: {$responseTime}ms\n";
            }
            $message .= "{$timestamp}";
        } else {
            $message = "API INDISPONIBLE\n";
            $message .= "{$api->name}\n";
            $message .= "{$api->url}\n";
            if ($errorDetails) {
                $message .= "{$errorDetails}\n";
            }
            $message .= "{$timestamp}\n";
            $message .= 'Vérification automatique en cours...';
        }

        return $message;
    }

    protected function displaySummary($results)
    {
        $this->newLine();
        $this->info('📈 RÉSUMÉ DU MONITORING');
        $this->table(
            ['Métrique', 'Valeur'],
            [
                ['APIs vérifiées', $results['checked']],
                ['Disponibles', $results['up']],
                ['Indisponibles', $results['down']],
                ['Alertes envoyées', $results['alerts_sent']],
                ['Erreurs', $results['errors']]
            ]
        );

        if ($results['down'] > 0) {
            $this->warn("⚠️  {$results['down']} API(s) indisponible(s) détectée(s)");
        } else {
            $this->info('✅ Toutes les APIs sont opérationnelles');
        }
    }
}
