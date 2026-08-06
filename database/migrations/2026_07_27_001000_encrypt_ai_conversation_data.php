<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE conversations MODIFY title TEXT NOT NULL');
            DB::statement('ALTER TABLE messages MODIFY tool_calls LONGTEXT NULL, MODIFY citations LONGTEXT NULL, MODIFY metadata LONGTEXT NULL');
            DB::statement('ALTER TABLE ai_tool_executions MODIFY arguments LONGTEXT NOT NULL, MODIFY result_summary LONGTEXT NULL, MODIFY citations LONGTEXT NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE conversations ALTER COLUMN title TYPE TEXT');
            DB::statement('ALTER TABLE messages ALTER COLUMN tool_calls TYPE TEXT USING tool_calls::text');
            DB::statement('ALTER TABLE messages ALTER COLUMN citations TYPE TEXT USING citations::text');
            DB::statement('ALTER TABLE messages ALTER COLUMN metadata TYPE TEXT USING metadata::text');
            DB::statement('ALTER TABLE ai_tool_executions ALTER COLUMN arguments TYPE TEXT USING arguments::text');
            DB::statement('ALTER TABLE ai_tool_executions ALTER COLUMN result_summary TYPE TEXT USING result_summary::text');
            DB::statement('ALTER TABLE ai_tool_executions ALTER COLUMN citations TYPE TEXT USING citations::text');
        }

        DB::table('conversations')->orderBy('id')->each(function (object $conversation) {
            DB::table('conversations')->where('id', $conversation->id)->update([
                'title' => Crypt::encryptString($conversation->title),
            ]);
        });

        DB::table('messages')->orderBy('id')->each(function (object $message) {
            DB::table('messages')->where('id', $message->id)->update([
                'content' => Crypt::encryptString($message->content),
                'tool_calls' => $this->encryptJson($message->tool_calls),
                'citations' => $this->encryptJson($message->citations),
                'metadata' => $this->encryptJson($message->metadata),
            ]);
        });

        DB::table('ai_tool_executions')->orderBy('id')->each(function (object $execution) {
            DB::table('ai_tool_executions')->where('id', $execution->id)->update([
                'arguments' => $this->encryptJson($execution->arguments),
                'result_summary' => $this->encryptJson($execution->result_summary),
                'citations' => $this->encryptJson($execution->citations),
            ]);
        });
    }

    public function down(): void
    {
        DB::table('conversations')->orderBy('id')->each(function (object $conversation) {
            DB::table('conversations')->where('id', $conversation->id)->update([
                'title' => Crypt::decryptString($conversation->title),
            ]);
        });

        DB::table('messages')->orderBy('id')->each(function (object $message) {
            DB::table('messages')->where('id', $message->id)->update([
                'content' => Crypt::decryptString($message->content),
                'tool_calls' => $this->decryptJson($message->tool_calls),
                'citations' => $this->decryptJson($message->citations),
                'metadata' => $this->decryptJson($message->metadata),
            ]);
        });

        DB::table('ai_tool_executions')->orderBy('id')->each(function (object $execution) {
            DB::table('ai_tool_executions')->where('id', $execution->id)->update([
                'arguments' => $this->decryptJson($execution->arguments),
                'result_summary' => $this->decryptJson($execution->result_summary),
                'citations' => $this->decryptJson($execution->citations),
            ]);
        });

        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE conversations MODIFY title VARCHAR(255) NOT NULL');
            DB::statement('ALTER TABLE messages MODIFY tool_calls JSON NULL, MODIFY citations JSON NULL, MODIFY metadata JSON NULL');
            DB::statement('ALTER TABLE ai_tool_executions MODIFY arguments JSON NOT NULL, MODIFY result_summary JSON NULL, MODIFY citations JSON NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE conversations ALTER COLUMN title TYPE VARCHAR(255)');
            DB::statement('ALTER TABLE messages ALTER COLUMN tool_calls TYPE JSON USING tool_calls::json');
            DB::statement('ALTER TABLE messages ALTER COLUMN citations TYPE JSON USING citations::json');
            DB::statement('ALTER TABLE messages ALTER COLUMN metadata TYPE JSON USING metadata::json');
            DB::statement('ALTER TABLE ai_tool_executions ALTER COLUMN arguments TYPE JSON USING arguments::json');
            DB::statement('ALTER TABLE ai_tool_executions ALTER COLUMN result_summary TYPE JSON USING result_summary::json');
            DB::statement('ALTER TABLE ai_tool_executions ALTER COLUMN citations TYPE JSON USING citations::json');
        }
    }

    private function encryptJson(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $decoded = is_string($value) ? json_decode($value, true, flags: JSON_THROW_ON_ERROR) : $value;

        return Crypt::encryptString(json_encode($decoded, JSON_THROW_ON_ERROR));
    }

    private function decryptJson(?string $value): ?string
    {
        return $value === null ? null : Crypt::decryptString($value);
    }
};
