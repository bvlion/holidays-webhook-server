<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ExecResult;

class DeleteOldExecResultCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'results:delete';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '直近 100 件以上の実行結果を物理削除する。';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $results = ExecResult::orderBy('id', 'desc')->get();
        $hold = []; // command_id をキーにした保持対象
        $delete = []; // 削除対象 ID 配列
        foreach ($results as $result) {
            // キーがなければ新規追加
            if (!array_key_exists($result->command_id, $hold)) {
                $hold[$result->command_id] =[$result];
                continue;
            }
            // 100件未満なら保持対象に追加
            if (count($hold[$result->command_id]) < 100) {
                array_push($hold[$result->command_id], $result);
                continue;
            }
            // 削除対象に追加
            array_push($delete, $result->id);
        }
        ExecResult::whereIn('id', $delete)->delete();
    }
}
