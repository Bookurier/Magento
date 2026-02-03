<?php
/**
 * Bookurier API client.
 */
namespace Bookurier\Shipping\Model\Api;

use Bookurier\Shipping\Model\Config;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\App\State;
use Bookurier\Shipping\Logger\Logger;

class Client
{
    private const ENDPOINT_ADD_CMDS = 'https://portal.bookurier.ro/api/add_cmds.php';

    /**
     * @var Curl
     */
    private $curl;

    /**
     * @var State
     */
    private $appState;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var Config
     */
    private $config;

    public function __construct(
        Curl $curl,
        Config $config,
        State $appState,
        Logger $logger
    )
    {
        $this->curl = $curl;
        $this->config = $config;
        $this->appState = $appState;
        $this->logger = $logger;
    }

    /**
     * @param array $payloads
     * @param int|null $storeId
     * @return array
     */
    public function addCommands(array $payloads, ?int $storeId = null): array
    {
        $body = json_encode([
            'user' => $this->config->getApiUser($storeId),
            'pwd' => $this->config->getApiPassword($storeId),
            'data' => $payloads,
        ]);

        $this->debugLog('request', [
            'endpoint' => self::ENDPOINT_ADD_CMDS,
            'body' => $this->maskSensitiveBody($body),
        ]);

        if ($this->config->isApiMockEnabled($storeId)) {
            $mock = $this->mockAddCommandsResponse($payloads);
            $this->debugLog('mock_response', $mock);
            return $mock;
        }

        $this->curl->setOption(CURLOPT_RETURNTRANSFER, true);
        $this->curl->setOption(CURLOPT_TIMEOUT, 30);
        $this->curl->setHeaders(['Content-Type' => 'application/json']);
        $this->curl->post(self::ENDPOINT_ADD_CMDS, $body);

        $body = $this->curl->getBody();
        $this->debugLog('response', [
            'status' => $this->curl->getStatus(),
            'body' => $body,
        ]);
        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            return [
                'status' => 'error',
                'message' => 'Invalid JSON response from Bookurier.',
            ];
        }

        return $decoded;
    }

    /**
     * @param array $payloads
     * @return array
     */
    private function mockAddCommandsResponse(array $payloads): array
    {
        $timestamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('YmdHis');
        $data = [];
        foreach (array_values($payloads) as $index => $payload) {
            $data[] = 'MOCK-' . $timestamp . '-' . str_pad((string)($index + 1), 3, '0', STR_PAD_LEFT);
        }

        return [
            'status' => 'success',
            'message' => 'Mocked Bookurier response.',
            'data' => $data,
        ];
    }

    /**
     * @param string $stage
     * @param array $context
     * @return void
     */
    private function debugLog(string $stage, array $context): void
    {
        if ($this->appState->getMode() !== State::MODE_DEVELOPER) {
            return;
        }
        $this->logger->debug('Bookurier API ' . $stage, $context);
    }

    /**
     * @param array $data
     * @return array
     */
    private function maskSensitiveBody(string $body): string
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return $body;
        }
        if (isset($decoded['pwd'])) {
            $decoded['pwd'] = '***';
        }
        return json_encode($decoded);
    }
}
