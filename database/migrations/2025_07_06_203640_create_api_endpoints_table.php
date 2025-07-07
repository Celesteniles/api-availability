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
        Schema::create('api_endpoints', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('url');
            $table->enum('last_status', ['up', 'down']);

            // Configuration de base
            $table->text('description')->nullable()->after('url');
            $table->integer('check_interval')->default(300)->after('description');  // 5 minutes
            $table->boolean('is_active')->default(true)->after('check_interval');
            $table->integer('timeout')->default(10)->after('is_active');

            // Configuration des vérifications
            $table->string('expected_status_code')->default('200')->after('timeout');
            $table->text('expected_content')->nullable()->after('expected_status_code');

            // Métadonnées
            $table->string('environment')->default('production')->after('expected_content');
            $table->enum('priority', ['low', 'medium', 'high'])->default('medium')->after('environment');
            $table->string('team_responsible')->nullable()->after('priority');

            // Statut et historique
            $table->timestamp('last_checked_at')->nullable()->after('last_status');
            $table->integer('consecutive_failures')->default(0)->after('last_checked_at');

            // Fenêtres de maintenance
            $table->timestamp('maintenance_window_start')->nullable()->after('consecutive_failures');
            $table->timestamp('maintenance_window_end')->nullable()->after('maintenance_window_start');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_endpoints');
    }
};
