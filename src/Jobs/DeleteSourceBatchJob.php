<?php

namespace Carlin\DataMigrator\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class DeleteSourceBatchJob implements ShouldQueue
{
	use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $sourceConnectionName;
    protected string $table;
    protected array $ids;
    protected string $localKey;
    protected array $relationships;
    protected int $chunk;

    public function __construct(
        string $sourceConnectionName,
        string $table,
        array $ids,
        string $localKey,
        int $chunk = 500
    ) {
        $this->sourceConnectionName = $sourceConnectionName;
        $this->table = $table;
        $this->ids = $ids;
        $this->localKey = $localKey;
        $this->chunk = $chunk;
    }

    public function handle(): void
    {
        // 先删除关联表数据
		DB::connection($this->sourceConnectionName)->transaction(function (Connection $conn) {
			// 再删除主表数据
			foreach (array_chunk($this->ids, $this->chunk) as $idChunk) {
				$conn->table($this->table)->whereIn($this->localKey, $idChunk)->delete();
			}
		});

    }
}
