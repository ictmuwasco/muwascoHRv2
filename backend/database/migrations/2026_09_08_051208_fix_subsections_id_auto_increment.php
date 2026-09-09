<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Fix auto-increment on subsections.id and offices.id — the inherited
     * live schema omitted it, causing factory inserts to fail with
     * "Field 'id' doesn't have a default value".
     */
    public function up(): void
    {
        // Fix id=0 row that prevents auto-increment resequencing.
        $maxId = DB::table('subsections')->max('id');
        DB::table('subsections')->where('id', 0)->update(['id' => $maxId + 1]);
        DB::statement('ALTER TABLE subsections MODIFY COLUMN id INT(11) NOT NULL AUTO_INCREMENT');

        // offices.id has no PK — add both PK and auto-increment.
        DB::statement('ALTER TABLE offices ADD PRIMARY KEY (id)');
        DB::statement('ALTER TABLE offices MODIFY COLUMN id INT(11) NOT NULL AUTO_INCREMENT');
    }

    /**
     * Irreversible without risk of breaking existing data — intentional no-op.
     */
    public function down(): void
    {
    }
};
