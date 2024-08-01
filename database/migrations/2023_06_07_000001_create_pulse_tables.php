<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Laravel\Pulse\Support\PulseMigration;

/**
 * Tabla ajustada a Postgresql 8.4.20
 */
return new class extends PulseMigration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! $this->shouldRun()) {
            return;
        }

        Schema::create('pulse_values', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('timestamp');
            $table->string('type');
            $table->mediumText('key');
            $table->uuid('key_hash');
            $table->mediumText('value');

            $table->index('timestamp'); // For trimming...
            $table->index('type'); // For fast lookups and purging...
            $table->unique(['type', 'key_hash']); // For data integrity and upserts...
        });

        Schema::create('pulse_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('timestamp');
            $table->string('type');
            $table->mediumText('key');
            $table->uuid('key_hash');
            $table->bigInteger('value')->nullable();

            $table->index('timestamp'); // For trimming...
            $table->index('type'); // For purging...
            $table->index('key_hash'); // For mapping...
            $table->index(['timestamp', 'type', 'key_hash', 'value']); // For aggregate queries...
        });

        Schema::create('pulse_aggregates', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('bucket');
            $table->unsignedMediumInteger('period');
            $table->string('type');
            $table->mediumText('key');
            $table->uuid('key_hash');
            $table->string('aggregate');
            $table->decimal('value', 20, 2);
            $table->unsignedInteger('count')->nullable();

            $table->unique(['bucket', 'period', 'type', 'aggregate', 'key_hash']); // Force "on duplicate update"...
            $table->index(['period', 'bucket']); // For trimming...
            $table->index('type'); // For purging...
            $table->index(['period', 'type', 'aggregate', 'bucket']); // For aggregate queries...
        });

        // Verificar si el lenguaje plpgsql existe antes de crearlo
        if ($this->driver() === 'pgsql') {
            $plpgsqlExists = DB::select("SELECT lanname FROM pg_catalog.pg_language WHERE lanname = 'plpgsql'");

            if (empty($plpgsqlExists)) {
                DB::statement('CREATE LANGUAGE plpgsql');
            }

            DB::statement('
                CREATE OR REPLACE FUNCTION generate_key_hash() RETURNS trigger AS $$
                BEGIN
                    NEW.key_hash := md5(NEW.key);
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
            ');

            DB::statement('
                CREATE TRIGGER trigger_generate_key_hash_pulse_values
                BEFORE INSERT OR UPDATE ON pulse_values
                FOR EACH ROW
                EXECUTE PROCEDURE generate_key_hash();
            ');

            DB::statement('
                CREATE TRIGGER trigger_generate_key_hash_pulse_entries
                BEFORE INSERT OR UPDATE ON pulse_entries
                FOR EACH ROW
                EXECUTE PROCEDURE generate_key_hash();
            ');

            DB::statement('
                CREATE TRIGGER trigger_generate_key_hash_pulse_aggregates
                BEFORE INSERT OR UPDATE ON pulse_aggregates
                FOR EACH ROW
                EXECUTE PROCEDURE generate_key_hash();
            ');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if ($this->driver() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS trigger_generate_key_hash_pulse_values ON pulse_values;');
            DB::statement('DROP TRIGGER IF EXISTS trigger_generate_key_hash_pulse_entries ON pulse_entries;');
            DB::statement('DROP TRIGGER IF EXISTS trigger_generate_key_hash_pulse_aggregates ON pulse_aggregates;');
            DB::statement('DROP FUNCTION IF EXISTS generate_key_hash();');
        }

        Schema::dropIfExists('pulse_values');
        Schema::dropIfExists('pulse_entries');
        Schema::dropIfExists('pulse_aggregates');
    }
};
