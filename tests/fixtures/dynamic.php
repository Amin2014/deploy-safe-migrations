<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['status'] as $column) {
            Schema::table('payments', function (Blueprint $table) use ($column) {
                $table->dropColumn($column);
            });
        }
    }

    public function down(): void
    {
        // Rollback compatibility is deliberately reviewed separately.
    }
};
