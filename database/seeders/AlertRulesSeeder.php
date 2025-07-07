<?php

namespace Database\Seeders;

use App\Models\AlertRule;
use App\Models\ApiEndpoint;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AlertRulesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Récupérer toutes les APIs existantes
        $apis = ApiEndpoint::all();

        foreach ($apis as $api) {
            // Règle d'échecs consécutifs
            AlertRule::create([
                'api_endpoint_id' => $api->id,
                'rule_type' => 'consecutive_failures',
                'conditions' => ['failures' => 3],
                'severity' => 'high',
                'description' => 'Alerte après 3 échecs consécutifs',
                'cooldown_minutes' => 15
            ]);

            // Règle de temps de réponse (seulement pour les APIs importantes)
            if ($api->priority === 'high') {
                AlertRule::create([
                    'api_endpoint_id' => $api->id,
                    'rule_type' => 'response_time',
                    'conditions' => ['threshold' => 5000],  // 5 secondes
                    'severity' => 'medium',
                    'description' => 'Alerte si temps de réponse > 5s',
                    'cooldown_minutes' => 30
                ]);
            }

            // Règle de disponibilité
            AlertRule::create([
                'api_endpoint_id' => $api->id,
                'rule_type' => 'uptime_percentage',
                'conditions' => [
                    'min_uptime' => $api->priority === 'high' ? 99.0 : 95.0,
                    'period_hours' => 24
                ],
                'severity' => 'critical',
                'description' => 'Alerte si disponibilité insuffisante sur 24h',
                'cooldown_minutes' => 60
            ]);
        }
    }
}
