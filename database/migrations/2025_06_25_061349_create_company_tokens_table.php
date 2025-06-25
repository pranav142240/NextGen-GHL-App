<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('company_id')->index();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->datetime('expires_at')->nullable();
            $table->string('token_type')->default('Bearer');
            $table->boolean('active_status')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_tokens');
    }
};