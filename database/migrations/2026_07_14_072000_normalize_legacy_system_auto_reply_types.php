<?php

use App\Models\AutoReply;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function up(): void
    {
        foreach (AutoReply::systemTriggers() as $type => $systemTrigger) {
            DB::table('auto_replies')
                ->where('type', $type)
                ->where('trigger', '!=', $systemTrigger)
                ->update([
                    'type' => AutoReply::TYPE_REGULAR,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Исходный системный тип старых обычных правил определить надёжно нельзя.
    }
};
