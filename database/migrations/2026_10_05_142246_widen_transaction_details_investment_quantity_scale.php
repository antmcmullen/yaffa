<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE `transaction_details_investment` MODIFY `quantity` DECIMAL(20,10) UNSIGNED NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $wouldLosePrecision = DB::table('transaction_details_investment')
            ->whereNotNull('quantity')
            ->whereRaw('quantity != ROUND(quantity, 4)')
            ->exists();

        if ($wouldLosePrecision) {
            throw new RuntimeException(
                'Cannot reverse investment quantity to DECIMAL(14,4): one or more rows have more than four fractional digits. '
                . 'Resolve these quantities before rolling back this migration.'
            );
        }

        DB::statement('ALTER TABLE `transaction_details_investment` MODIFY `quantity` DECIMAL(14,4) UNSIGNED NULL');
    }
};
