<?php

namespace App\Console\Commands;

use App\Jobs\Knowledge\IngestBusinessKnowledgeJob;
use App\Models\Business;
use Illuminate\Console\Command;

class IngestBusinessKnowledgeCommand extends Command
{
    protected $signature = 'ai:ingest-knowledge
                            {business? : Business id (omit for all)}
                            {--namespace=brand : brand|products|faqs|policies|tone|posts|memories}
                            {--sync : Run inline instead of queue}';

    protected $description = 'Ingest shop knowledge into the SK/Supabase vector store';

    public function handle(): int
    {
        $namespace = (string) $this->option('namespace');
        $ids = $this->argument('business')
            ? [(int) $this->argument('business')]
            : Business::query()->orderBy('id')->pluck('id')->all();

        foreach ($ids as $id) {
            $job = new IngestBusinessKnowledgeJob((int) $id, $namespace);
            if ($this->option('sync')) {
                $job->handle(app(\App\AI\Runtime\SkAgentClient::class));
                $this->info("Ingested business {$id} ({$namespace})");
            } else {
                dispatch($job);
                $this->info("Queued ingest for business {$id} ({$namespace})");
            }
        }

        return self::SUCCESS;
    }
}
