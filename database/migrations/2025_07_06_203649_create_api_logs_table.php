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
        Schema::create('api_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_endpoint_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['up', 'down']);
            $table->string('response_code');
            $table->timestamp('checked_at');

            // Métriques de performance
            $table->float('response_time')->nullable()->after('response_code')->comment('Temps de réponse en millisecondes');
            $table->float('check_duration')->nullable()->after('response_time')->comment('Durée totale du check');
            $table->float('dns_resolution_time')->nullable()->after('check_duration')->comment('Temps de résolution DNS');

            // Détails des erreurs
            $table->text('error_message')->nullable()->after('dns_resolution_time');

            // Réponse HTTP détaillée
            $table->json('response_headers')->nullable()->after('error_message');
            $table->text('response_body')->nullable()->after('response_headers');

            // SSL/TLS
            $table->timestamp('ssl_expiry_date')->nullable()->after('response_body');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_logs');
    }
};
