<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent WITH SCHEMA public');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm WITH SCHEMA public');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.viz_search_normalize(input text) RETURNS text
            LANGUAGE sql STABLE PARALLEL SAFE SET search_path = pg_catalog, public
            AS $fn$
                SELECT lower(public.unaccent('public.unaccent'::regdictionary,
                    regexp_replace(btrim(normalize(coalesce(input, ''), NFKC)), '\s+', ' ', 'g')))
            $fn$;
            CREATE OR REPLACE FUNCTION public.viz_refresh_search_text() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, public
            AS $fn$
            BEGIN
                IF TG_TABLE_NAME = 'jobs' THEN
                    NEW.search_text := public.viz_search_normalize(NEW.title);
                ELSE
                    NEW.search_text := public.viz_search_normalize(NEW.name);
                END IF;
                RETURN NEW;
            END
            $fn$;
            SQL);
        foreach (['jobs' => 'title', 'companies' => 'name'] as $table => $source) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->text('search_text')->default(''));
            // Stable unaccent is not misdeclared immutable. Persist normalized text and index that column.
            DB::statement("UPDATE {$table} SET search_text = public.viz_search_normalize({$source})");
            DB::statement("CREATE TRIGGER {$table}_refresh_search_text BEFORE INSERT OR UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION public.viz_refresh_search_text()");
            DB::statement("CREATE INDEX {$table}_search_text_trgm ON {$table} USING gin (search_text public.gin_trgm_ops)");
        }
    }

    public function down(): void
    {
        foreach (['jobs', 'companies'] as $table) {
            DB::statement("DROP TRIGGER {$table}_refresh_search_text ON {$table}");
            DB::statement("DROP INDEX {$table}_search_text_trgm");
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('search_text'));
        }
        DB::statement('DROP FUNCTION public.viz_refresh_search_text()');
        DB::statement('DROP FUNCTION public.viz_search_normalize(text)');
        // Extensions may predate this migration or be used elsewhere; never drop shared extensions.
    }
};
