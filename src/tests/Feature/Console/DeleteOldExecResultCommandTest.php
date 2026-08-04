<?php

namespace Tests\Feature\Console;

use App\Models\ExecResult;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class DeleteOldExecResultCommandTest extends TestCase
{
    use DatabaseTransactions;

    public function test_コマンドごとに新しい100件を残し古い実行結果を削除する()
    {
        $keptCommandId = 1;
        $untouchedCommandId = 2;
        ExecResult::factory()->count(105)->create(['command_id' => $keptCommandId, 'trigger_id' => 1]);
        ExecResult::factory()->count(50)->create(['command_id' => $untouchedCommandId, 'trigger_id' => 2]);

        Artisan::call('results:delete');

        // id の大きい(新しい)100件だけが残り、古い5件は物理削除されていること
        $this->assertSame(100, ExecResult::where('command_id', $keptCommandId)->count());
        $this->assertSame(50, ExecResult::where('command_id', $untouchedCommandId)->count());
    }

    public function test_ちょうど100件の場合は削除されない()
    {
        ExecResult::factory()->count(100)->create(['command_id' => 3, 'trigger_id' => 1]);

        Artisan::call('results:delete');

        $this->assertSame(100, ExecResult::where('command_id', 3)->count());
    }
}
