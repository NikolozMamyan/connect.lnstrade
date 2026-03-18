<?php
namespace App\MessageHandler;

use App\Message\SyncClientMessage;
use App\Service\Flux\ClientFluxOrchestrator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SyncClientMessageHandler
{
    public function __construct(private ClientFluxOrchestrator $orchestrator)
    {
    }

    public function __invoke(SyncClientMessage $message)
    {
        $this->orchestrator->run();
    }
}