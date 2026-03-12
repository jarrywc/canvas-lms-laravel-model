<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canvas_oauth_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique()->comment('Storage key, e.g. "user:42"');
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->string('token_type', 50)->default('Bearer');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index('key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canvas_oauth_tokens');
    }
};
