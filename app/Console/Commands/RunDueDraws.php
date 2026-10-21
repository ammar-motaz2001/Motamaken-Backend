<?php

namespace App\Console\Commands;

use App\Models\Draw;
use App\Services\DrawService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('draws:run')]
#[Description('Pick the winners of every prize draw whose time has come')]
class RunDueDraws extends Command
{
    /**
     * Pick the winners of every open draw whose draw time has come.
     */
    public function handle(DrawService $draws): int
    {
        Draw::where('status', Draw::OPEN)->where('draw_at', '<=', now())->each(function (Draw $draw) use ($draws) {
            $count = count($draws->run($draw));
            $this->info("Draw {$draw->id}: {$count} winner(s).");
        });

        return self::SUCCESS;
    }
}
