<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('investment_groups', function (Blueprint $table) {
            $table->boolean('auto_invest')->default(false)->after('generates_interest');
        });
    }

    public function down(): void
    {
        Schema::table('investment_groups', function (Blueprint $table) {
            $table->dropColumn('auto_invest');
        });
    }
};
