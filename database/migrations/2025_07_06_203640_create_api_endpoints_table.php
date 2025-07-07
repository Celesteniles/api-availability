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
            $table->text('description')->nullable();
            $table->integer('check_interval')->default(300);  // 5 minutes
            $table->boolean('is_active')->default(true);
            $table->integer('timeout')->default(10);
            $table->string('expected_status_code')->default('200');
            $table->text('expected_content')->nullable();
            $table->string('environment')->default('production');
            $table->enum('priority', ['low', 'medium', 'high'])->default('medium');
            $table->string('team_responsible')->nullable();
            $table->string('url');
            $table->enum('last_status', ['up', 'down']);
            $table->timestamp('last_checked_at')->nullable();
            $table->integer('consecutive_failures')->default(0);
            $table->timestamp('maintenance_window_start')->nullable();
            $table->timestamp('maintenance_window_end')->nullable();
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
