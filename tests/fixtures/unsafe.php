<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->renameColumn('status', 'state');
            $table->dropColumn('legacy_reference');
            $table->integer('amount')->change();
            $table->string('currency');
        });
    }

    public function down(): void
    {
        // Rollback compatibility is deliberately reviewed separately.
    }
};
