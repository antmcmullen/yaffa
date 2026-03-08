<?php

use App\Models\Investment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('account_entities', function (Blueprint $table) {
            $table->foreignIdFor(Investment::class, 'default_investment_id')->nullable()->constrained('investments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('account_entities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_investment_id');
        });
    }
};
