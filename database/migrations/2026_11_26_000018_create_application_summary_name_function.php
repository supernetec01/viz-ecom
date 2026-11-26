<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Input stays in PostgreSQL; only the masked result is selected into summary records.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION application_summary_name(input text) RETURNS text
            LANGUAGE plpgsql IMMUTABLE STRICT AS $function$
            DECLARE result text;
            BEGIN
                result := normalize(input, NFKC);
                result := regexp_replace(result, '[' || chr(173) || chr(8203) || chr(8204) || chr(8205) || chr(8206) || chr(8207) || chr(8288) || chr(65279) || chr(8234) || '-' || chr(8238) || chr(8294) || '-' || chr(8297) || ']', '', 'g');
                result := regexp_replace(result, '[[:alnum:]_.%+-]+[[:space:]]*(@|\[[[:space:]]*at[[:space:]]*\]|\([[:space:]]*at[[:space:]]*\)|[[:space:]]+at[[:space:]]+)[[:space:]]*[[:alnum:]-]+([[:space:]]*(\.|\[[[:space:]]*dot[[:space:]]*\]|\([[:space:]]*dot[[:space:]]*\)|[[:space:]]+dot[[:space:]]+)[[:space:]]*[[:alnum:]-]+)+', '[contact]', 'gi');
                result := regexp_replace(result, '([[:alpha:]][[:alnum:]+.-]*://|//|www\.|mailto:|tel:)[^[:space:]<>"'']+', '[contact]', 'gi');
                result := regexp_replace(result, '([[:alnum:]][[:alnum:]-]*[[:space:]]*(\.|\[[[:space:]]*dot[[:space:]]*\]|\([[:space:]]*dot[[:space:]]*\)|[[:space:]]+dot[[:space:]]+)[[:space:]]*)+[[:alpha:]][[:alnum:]-]{1,62}([/:][^[:space:]<>"'']*)?', '[contact]', 'gi');
                result := regexp_replace(result, '(\+|00)?[[:digit:]]([[:space:].()/_-]*[[:alpha:]]{0,3}[[:space:].()/_-]*[[:digit:]]){7,14}', '[contact]', 'g');
                result := regexp_replace(result, '((không|khong|một|mot|hai|ba|bốn|bon|tư|tu|năm|nam|sáu|sau|bảy|bay|tám|tam|chín|chin)[[:space:]._-]+){7,14}(không|khong|một|mot|hai|ba|bốn|bon|tư|tu|năm|nam|sáu|sau|bảy|bay|tám|tam|chín|chin)', '[contact]', 'gi');
                RETURN result;
            END;
            $function$
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION application_summary_name(text)');
    }
};
