<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete(); // Who made the change
            $table->string('model_type'); // What was changed (e.g., App\Models\User)
            $table->unsignedBigInteger('model_id'); // The ID of what was changed
            $table->string('action'); // created, updated, deleted
            $table->json('old_values')->nullable(); // The Red Card
            $table->json('new_values')->nullable(); // The Green Card
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
