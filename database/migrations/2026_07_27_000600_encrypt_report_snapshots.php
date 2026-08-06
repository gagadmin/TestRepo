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
            DB::statement('ALTER TABLE report_snapshots MODIFY data LONGTEXT NOT NULL, MODIFY summary LONGTEXT NULL, MODIFY citations LONGTEXT NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE report_snapshots ALTER COLUMN data TYPE TEXT USING data::text');
            DB::statement('ALTER TABLE report_snapshots ALTER COLUMN summary TYPE TEXT USING summary::text');
            DB::statement('ALTER TABLE report_snapshots ALTER COLUMN citations TYPE TEXT USING citations::text');
        }

        DB::table('report_snapshots')->orderBy('id')->each(function (object $snapshot) {
            DB::table('report_snapshots')->where('id', $snapshot->id)->update([
                'data' => $this->encryptJson($snapshot->data),
                'summary' => $this->encryptJson($snapshot->summary),
                'citations' => $this->encryptJson($snapshot->citations),
            ]);
        });
    }

    public function down(): void
    {
        DB::table('report_snapshots')->orderBy('id')->each(function (object $snapshot) {
            DB::table('report_snapshots')->where('id', $snapshot->id)->update([
                'data' => $this->decryptJson($snapshot->data),
                'summary' => $this->decryptJson($snapshot->summary),
                'citations' => $this->decryptJson($snapshot->citations),
            ]);
        });

        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE report_snapshots MODIFY data JSON NOT NULL, MODIFY summary JSON NULL, MODIFY citations JSON NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE report_snapshots ALTER COLUMN data TYPE JSON USING data::json');
            DB::statement('ALTER TABLE report_snapshots ALTER COLUMN summary TYPE JSON USING summary::json');
            DB::statement('ALTER TABLE report_snapshots ALTER COLUMN citations TYPE JSON USING citations::json');
        }
    }

    private function encryptJson(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return Crypt::encryptString(json_encode($decoded, JSON_THROW_ON_ERROR));
    }

    private function decryptJson(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return Crypt::decryptString($value);
    }
};
