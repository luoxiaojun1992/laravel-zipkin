<?php

namespace Lxj\Laravel\Zipkin\Commands;

use Elasticsearch\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Input\InputOption;

class ExportWatches extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'es:watches:export';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Export es watches';

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
        );
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $esClient = $this->getEsClient();
        $res = $esClient->search([
            'index' => '.watches',
            'from' => intval($this->option('offset')),
            'size' => intval($this->option('limit')),
        ]);

        $watches = [];
        if ($res['hits']['total'] > 0) {
            foreach ($res['hits']['hits'] as $hit) {
                if ($hit['_source']['metadata']['xpack']['type'] === 'json') {
                    array_push($watches, $hit);
                }
            }
        }

        File::put(storage_path('watches.json'), json_encode($watches));
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
