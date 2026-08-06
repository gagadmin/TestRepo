<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('provider')->nullable()->after('role');
            $table->string('model')->nullable()->after('provider');
            $table->string('response_id')->nullable()->after('model');
            $table->unsignedInteger('input_tokens')->nullable()->after('tokens');
            $table->unsignedInteger('output_tokens')->nullable()->after('input_tokens');
            $table->unsignedInteger('latency_ms')->nullable()->after('output_tokens');
            $table->json('metadata')->nullable()->after('citations');
        });

        Schema::create('ai_tool_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('tool_name')->index();
            $table->string('call_id')->nullable()->index();
            $table->json('arguments');
            $table->json('result_summary')->nullable();
            $table->json('citations')->nullable();
            $table->string('status')->index();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_tool_executions');

        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn([
                'provider',
                'model',
                'response_id',
                'input_tokens',
                'output_tokens',
                'latency_ms',
                'metadata',
            ]);
        });
    }
};
