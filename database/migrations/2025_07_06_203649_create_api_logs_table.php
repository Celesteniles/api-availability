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
            $table->float('response_time')->nullable()->comment('Temps de réponse en millisecondes');
            $table->float('check_duration')->nullable()->comment('Durée totale du check');
            $table->float('dns_resolution_time')->nullable()->comment('Temps de résolution DNS');
            $table->text('error_message')->nullable();
            $table->json('response_headers')->nullable();
            $table->text('response_body')->nullable();
            $table->timestamp('ssl_expiry_date')->nullable();
            $table->timestamp('checked_at');
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
