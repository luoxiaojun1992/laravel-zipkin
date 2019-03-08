<?php

namespace Lxj\Laravel\Zipkin\Commands;

use Elasticsearch\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Input\InputOption;

class ImportWatches extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'es:watches:import';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import es watches';

    private $esOptions = [
        'connection' => 'zipkin',
    ];

    /** @var Client */
    private $esClient;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $this->esOptions = array_merge($this->esOptions, config('zipkin.es_options', []));
    }

    protected function configure()
    {
        $this->addOption(
            'override',
            null,
            InputOption::VALUE_OPTIONAL,
            'Override all watches',
            0
        )->addOption(
            'offset',
            null,
            InputOption::VALUE_OPTIONAL,
            'Query Offset',
            0
        )->addOption(
            'limit',
            null,
            InputOption::VALUE_OPTIONAL,
            'Query Limit',
            100
        )->addOption(
            'file',
            null,
            InputOption::VALUE_OPTIONAL,
            'Watches json file',
            null
        );
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $fileOption = $this->option('file');
        $watchesJson = File::get(isset($fileOption) ? $fileOption : storage_path('watches.json'));
        $watches = json_decode($watchesJson, true);
        if (!json_last_error()) {

            if (!intval($this->option('override'))) {
                //Remove current watches
                $currentWatchIds = $this->getCurrentWatchIds();
                foreach ($watches as $k => $hit) {
                    if (in_array($hit['_id'], $currentWatchIds)) {
                        unset($watches[$k]);
                    }
                }
            }

            $this->output->progressStart(count($watches));

            $esClient = $this->getEsClient();

            foreach ($watches as $hit) {
                $esClient->index([
                    'index' => '_watcher',
                    'type' => 'watch',
                    'id' => $hit['_id'],
                    'body' => $hit['_source'],
                ]);

                $this->output->progressAdvance(1);
            }

            $this->output->progressFinish();
        } else {
            var_dump(json_last_error());
        }
    }

    private function getCurrentWatchIds()
    {
        $esClient = $this->getEsClient();
        $res = $esClient->search([
            'index' => '.watches',
            'from' => intval($this->option('offset')),
            'size' => intval($this->option('limit')),
        ]);
        $watchIds = [];
        if ($res['hits']['total'] > 0) {
            foreach ($res['hits']['hits'] as $hit) {
                array_push($watchIds, $hit['_id']);
            }
        }

        return $watchIds;
    }

    private function getEsClient()
    {
        if (is_null($this->esClient)) {
            if (!empty($this->esOptions['connection'])) {
                $this->esClient = \Elasticsearch::connection($this->esOptions['connection']);
            }
        }

        return $this->esClient;
    }
}
