<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row, edited by the System Administrator; .env values apply until it exists.
        Schema::create('mail_settings', function (Blueprint $table) {
            $table->id();
            $table->string('host');
            $table->unsignedInteger('port');
            $table->string('username');
            $table->text('password'); // encrypted with APP_KEY (model cast)
            $table->string('from_address');
            $table->string('from_name');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_settings');
    }
};
