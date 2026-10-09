<?php

namespace App\Console\Commands;

use App\Services\Mesh\MeshClient;
use App\Services\Mesh\MeshClientException;
use App\Support\MeshConfig;
use Illuminate\Console\Command;

class MeshTest extends Command
{
    protected $signature = 'mesh:test';

    protected $description = 'Test Mesh Email Security API connectivity';

    public function handle(): int
    {
        if (! MeshConfig::isConfigured()) {
            // #6161: 'not configured' only for a key that is really absent;
            // a stored value the PSA does not send says why (#6288: a CR
            // or LF; #6296: a row that does not decrypt). #6346: in the
            // wording the other Mesh surfaces use (notConfiguredText()).
            $note = MeshConfig::storedKeyNote();
            $this->error($note === null
                ? 'Mesh is not configured. Add API key in Settings → Integrations.'
                : "Mesh has a stored API key the PSA does not send ({$note}); nothing was sent. Replace it in Settings → Integrations.");

            return self::FAILURE;
        }

        $this->info('Testing Mesh API connection...');

        // The container's MeshClient is built from the same MeshConfig
        // values (AppServiceProvider); resolving it is the test seam (G-5).
        $client = app(MeshClient::class);

        // #5988: the health read is reported by statusPhrase(), not as
        // 'Failed to connect': Mesh may have answered it with a status.
        // The same read isHealthy() makes.
        try {
            $client->get('api/customers/', ['_size' => 1]);
        } catch (MeshClientException $e) {
            $this->error('Mesh connection test failed: '.self::sentence($e->statusPhrase('the health read')));

            return self::FAILURE;
        }

        $this->info('Connected to Mesh Email Security successfully!');

        // Show customer count. #5882: a failure here is reported by
        // statusPhrase() only, never by the exception's message or its
        // console rendering.
        try {
            $customers = $client->getCustomers(size: 1);
        } catch (MeshClientException $e) {
            $this->error('Connected, but '.self::sentence($e->statusPhrase('the customer read')));

            return self::FAILURE;
        }
        if (is_array($customers)) {
            $this->info('API responded with customer data.');
        }

        return self::SUCCESS;
    }

    /**
     * #6053: a client-detected phrase is the PSA's own sentence and already
     * ends in '.'; an upstream phrase does not. One full stop either way.
     */
    private static function sentence(string $phrase): string
    {
        return rtrim($phrase, '.').'.';
    }
}
