<?php

namespace Tienvx\PactProviderPackage\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tienvx\PactProviderPackage\Model\Message;
use Tienvx\PactProviderPackage\Model\ProviderState;
use Tienvx\PactProviderPackage\Service\MessageDispatcherManagerInterface;
use Tienvx\PactProviderPackage\Service\StateHandlerManagerInterface;

class MessagesController
{
    public function __construct(
        private StateHandlerManagerInterface $stateHandlerManager,
        private MessageDispatcherManagerInterface $messageDispatcherManager
    ) {
    }

    public function handle(Request $request): ?Response
    {
        $providerStates = $this->getProviderStates($request);
        $this->handleProviderStates($providerStates, Action::SETUP);
        $message = $this->dispatchMessage($request);
        $this->handleProviderStates($providerStates, Action::TEARDOWN);

        if ($message) {
            return new Response($message->contents, Response::HTTP_OK, [
                'Content-Type' => $message->contentType,
                'Pact-Message-Metadata' => \base64_encode($message->metadata),
            ]);
        }

        return null;
    }

    /**
     * @return ProviderState[]
     */
    private function getProviderStates(Request $request): array
    {
        $providerStates = $request->toArray()['providerStates'] ?? [];
        if (!is_array($providerStates) || empty($providerStates)) {
            throw new BadRequestException("'providerStates' is missing or invalid in messages request.");
        }

        return array_map(function (array $providerState): ProviderState {
            $name = $providerState['name'] ?? null;
            if (!is_string($name)) {
                throw new BadRequestException("Missing 'name' for provider state.");
            }
            $params = $providerState['params'] ?? [];
            if (!is_array($params)) {
                throw new BadRequestException(sprintf("Invalid 'params' for provider state '%s'.", $name));
            }

            return new ProviderState($name, $params);
        }, $providerStates);
    }

    private function getDescription(Request $request): string
    {
        $description = $request->toArray()['description'] ?? null;
        if (!is_string($description)) {
            throw new BadRequestException("'description' is missing or invalid in messages request.");
        }

        return $description;
    }

    /**
     * @param ProviderState[] $providerStates
     */
    private function handleProviderStates(array $providerStates, string $action): void
    {
        foreach ($providerStates as $providerState) {
            $this->stateHandlerManager->handle($providerState->state, $action, $providerState->params);
        }
    }

    private function dispatchMessage(Request $request): ?Message
    {
        return $this->messageDispatcherManager->dispatch($this->getDescription($request));
    }
}
