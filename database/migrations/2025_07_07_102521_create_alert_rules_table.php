<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();

            // Relation avec l'API
            $table->foreignId('api_endpoint_id')->constrained('api_endpoints')->onDelete('cascade');

            // Type de règle
            $table->enum('rule_type', [
                'consecutive_failures',
                'response_time',
                'status_code',
                'uptime_percentage',
                'ssl_expiry'
            ]);

            // Configuration de la règle (JSON)
            $table->json('conditions')->comment('Configuration spécifique à chaque type de règle');

            // Métadonnées
            $table->boolean('is_active')->default(true);
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->text('description')->nullable();

            // Gestion du cooldown (délai entre alertes)
            $table->integer('cooldown_minutes')->default(10)->comment('Délai minimum entre deux alertes');

            $table->timestamps();

            // Index pour les requêtes fréquentes
            $table->index(['api_endpoint_id', 'is_active']);
            $table->index(['rule_type', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alert_rules');
    }
};
