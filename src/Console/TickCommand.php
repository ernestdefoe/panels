<?php

namespace ErnestDefoe\Panels\Console;

use ErnestDefoe\Panels\Publisher;
use Flarum\Console\AbstractCommand;

/**
 * Every minute: scheduled chapters go live, early access ends, followers hear about releases,
 * quarantined takedowns are purged and abandoned imports cleaned up.
 */
class TickCommand extends AbstractCommand
{
    public function __construct(private readonly Publisher $publisher)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('panels:tick')->setDescription('Release scheduled chapters, send new-chapter notifications, purge old takedowns');
    }

    protected function fire(): int
    {
        $this->publisher->tick();
        $this->info('Done.');

        return 0;
    }
}
