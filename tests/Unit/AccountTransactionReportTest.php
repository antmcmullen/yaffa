<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Console\Commands\AccountTransactionReport;

class AccountTransactionReportTest extends TestCase
{
    public function test_build_query_contains_account_filters()
    {
        $cmd = new AccountTransactionReport();

        $builder = $cmd->buildQueryForAccount(60, 1);

        $sql = $builder->toSql();

        // The query should reference the investment account column when including investments.
        $this->assertStringContainsString('transaction_details_investment', $sql);
        $this->assertStringContainsString('account_id', $sql);
        $this->assertStringContainsString('account_from_id', $sql);
        $this->assertStringContainsString('account_to_id', $sql);
    }
}
