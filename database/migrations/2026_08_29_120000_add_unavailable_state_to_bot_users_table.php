<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('bot_users', function (Blueprint $table): void {
            $table->boolean('is_unavailable')->default(false)->after('manager_last_read_at');
            $table->text('unavailable_reason')->nullable()->after('is_unavailable');
            $table->timestamp('unavailable_at')->nullable()->after('unavailable_reason');
        });
    }

    public function down(): void
    {
        $columns = array_values(array_filter(
            ['is_unavailable', 'unavailable_reason', 'unavailable_at'],
            static fn (string $column): bool => Schema::hasColumn('bot_users', $column),
        ));

        if ($columns !== []) {
            Schema::table('bot_users', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
