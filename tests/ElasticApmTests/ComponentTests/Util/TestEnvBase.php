<?php

/** @noinspection PhpUndefinedClassInspection */

declare(strict_types=1);

namespace Elastic\Apm\Tests\ComponentTests\Util;

use Closure;
use Elastic\Apm\Impl\Clock;
use Elastic\Apm\Impl\Config\OptionNames;
use Elastic\Apm\Impl\Log\Logger;
use Elastic\Apm\Impl\MetadataDiscoverer;
use Elastic\Apm\Impl\Tracer;
use Elastic\Apm\Impl\Util\DbgUtil;
use Elastic\Apm\Impl\Util\IdGenerator;
use Elastic\Apm\Impl\Util\ObjectToStringBuilder;
use Elastic\Apm\Tests\Util\TestCaseBase;
use Elastic\Apm\Tests\Util\TestLogCategory;
use Elastic\Apm\TransactionDataInterface;
use Exception;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use PHPUnit\Exception as PhpUnitException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

abstract class TestEnvBase
{
    private const PORTS_RANGE_BEGIN = 50000;
    private const PORTS_RANGE_END = 60000;

    private const MAX_WAIT_SERVER_START_MICROSECONDS = 10 * 1000 * 1000; // 10 seconds
    private const MAX_TRIES_TO_START_SERVER = 3;

    public const STATUS_CHECK_URI = '/elastic_apm_php_tests_status_check';
    public const HEADER_NAME_PREFIX = 'ELASTIC_APM_PHP_TESTS_';
    public const SERVER_ID_HEADER_NAME = self::HEADER_NAME_PREFIX . 'SERVER_ID';

    public const DATA_FROM_AGENT_MAX_WAIT_TIME_SECONDS = 10;

    private const AUTH_HTTP_HEADER_NAME = 'Authorization';

    /** @var int|null */
    protected $resourcesCleanerPort = null;

    /** @var string|null */
    protected $resourcesCleanerServerId = null;

    /** @var string|null */
    protected $mockApmServerId = null;

    /** @var Logger */
    private $logger;

    /** @var DataFromAgent */
    private $dataFromAgent;

    /** @var int|null */
    private $mockApmServerPort = null;

    public function __construct()
    {
        $this->logger = AmbientContext::loggerFactory()->loggerForClass(
            TestLogCategory::TEST_UTIL,
            __NAMESPACE__,
            __CLASS__,
            __FILE__
        )->addContext('this', $this);

        $this->dataFromAgent = new DataFromAgent();
    }

    public function testEnvId(): string
    {
        return PhpUnitExtension::$testEnvId;
    }

    protected function findFreePortToListen(): int
    {
        return mt_rand(self::PORTS_RANGE_BEGIN, self::PORTS_RANGE_END - 1);
    }

    protected function isHttpServerRunning(int $port, string $serverId, string $dbgServerDesc): bool
    {
        return (new PollingCheck(
            $dbgServerDesc . ' started',
            self::MAX_WAIT_SERVER_START_MICROSECONDS,
            AmbientContext::loggerFactory()
        ))->run(
            function () use ($port, $serverId, $dbgServerDesc) {
                $logger = AmbientContext::loggerFactory()->loggerForClass(
                    TestLogCategory::TEST_UTIL,
                    __NAMESPACE__,
                    __CLASS__,
                    __FILE__
                )->addAllContext(['dbgServerDesc' => $dbgServerDesc, 'port' => $port, 'serverId' => $serverId]);

                try {
                    $response = TestHttpClientUtil::sendHttpRequest(
                        $port,
                        $serverId,
                        HttpConsts::METHOD_GET,
                        TestEnvBase::STATUS_CHECK_URI
                    );
                } catch (Throwable $throwable) {
                    ($loggerProxy = $logger->ifDebugLevelEnabled(__LINE__, __FUNCTION__))
                    && $loggerProxy->logThrowable($throwable, 'Failed to send request to check HTTP server status');
                    return false;
                }

                if ($response->getStatusCode() !== HttpConsts::STATUS_OK) {
                    ($loggerProxy = $logger->ifNoticeLevelEnabled(__LINE__, __FUNCTION__))
                    && $loggerProxy->log(
                        'Received non-OK status code in response to status check',
                        ['receivedStatusCode' => $response->getStatusCode()]
                    );
                    return false;
                }

                ($loggerProxy = $logger->ifDebugLevelEnabled(__LINE__, __FUNCTION__))
                && $loggerProxy->log('HTTP server status is OK');
                return true;
            }
        );
    }

    /**
     * @param int|null              $port
     * @param string|null           $serverId
     * @param string                $dbgServerDesc
     * @param Closure               $cmdLineGenFunc
     * @param array<string, string> $additionalEnvVars
     *
     * @phpstan-param   Closure(int $port): string $cmdLineGenFunc
     */
    protected function ensureHttpServerIsRunning(
        ?int &$port,
        ?string &$serverId,
        string $dbgServerDesc,
        Closure $cmdLineGenFunc,
        array $additionalEnvVars = []
    ): void {
        if (!is_null($port)) {
            TestCase::assertNotNull($serverId);
            return;
        }
        TestCase::assertNull($serverId);

        /** @var int|null */
        $currentTryPort = null;
        for ($tryCount = 0; $tryCount < self::MAX_TRIES_TO_START_SERVER; ++$tryCount) {
            $currentTryPort = $this->findFreePortToListen();
            $currentTryServerId = $this->testEnvId() . '_' . IdGenerator::generateId(/* idLengthInBytes */ 16);
            $cmdLine = $cmdLineGenFunc($currentTryPort);

            $logger = $this->logger->inherit()->addAllContext(
                [
                    'tryCount'           => $tryCount,
                    'maxTries'           => self::MAX_TRIES_TO_START_SERVER,
                    'dbgServerDesc'      => $dbgServerDesc,
                    'currentTryPort'     => $currentTryPort,
                    'currentTryServerId' => $currentTryServerId,
                    'cmdLine'            => $cmdLine,
                ]
            );

            ($loggerProxy = $logger->ifDebugLevelEnabled(__LINE__, __FUNCTION__))
            && $loggerProxy->log('Starting HTTP server...');

            TestProcessUtil::startBackgroundProcess(
                $cmdLine,
                $this->buildEnvVars(
                    $additionalEnvVars +
                    [
                        TestConfigUtil::envVarNameForTestsOption(
                            AllComponentTestsOptionsMetadata::THIS_SERVER_PORT_OPTION_NAME
                        ) => strval($currentTryPort),
                        TestConfigUtil::envVarNameForTestsOption(
                            AllComponentTestsOptionsMetadata::THIS_SERVER_ID_OPTION_NAME
                        ) => $currentTryServerId,
                    ]
                )
            );

            if (self::isHttpServerRunning($currentTryPort, $currentTryServerId, $dbgServerDesc)) {
                ($loggerProxy = $logger->ifDebugLevelEnabled(__LINE__, __FUNCTION__))
                && $loggerProxy->log('Started HTTP server');
                $port = $currentTryPort;
                $serverId = $currentTryServerId;
                return;
            }

            ($loggerProxy = $logger->ifNoticeLevelEnabled(__LINE__, __FUNCTION__))
            && $loggerProxy->log('Failed to start HTTP server');
        }

        throw new RuntimeException("Failed to start HTTP server. dbgServerDesc: $dbgServerDesc.");
    }

    private static function runScriptNameToCmdLine(string $runScriptName): string
    {
        return 'php ' . '"' . __DIR__ . DIRECTORY_SEPARATOR . $runScriptName . '"';
    }

    private function ensureResourcesCleanerRunning(): void
    {
        $this->ensureHttpServerIsRunning(
            $this->resourcesCleanerPort /* <- ref */,
            $this->resourcesCleanerServerId /* <- ref */,
            DbgUtil::fqToShortClassName(ResourcesCleaner::class) /* <- dbgServerDesc */,
            /* cmdLineGenFunc: */
            function (/** @noinspection PhpUnusedParameterInspection */ int $port) {
                return self::runScriptNameToCmdLine('runResourcesCleaner.php');
            },
            /* additionalEnvVars */
            [
                TestConfigUtil::envVarNameForTestsOption(AllComponentTestsOptionsMetadata::ROOT_PROCESS_ID_OPTION_NAME)
                => strval(getmypid()),
            ]
        );
    }

    protected function ensureMockApmServerRunning(): void
    {
        $this->ensureResourcesCleanerRunning();

        $this->ensureHttpServerIsRunning(
            $this->mockApmServerPort /* <- ref */,
            $this->mockApmServerId /* <- ref */,
            DbgUtil::fqToShortClassName(MockApmServer::class) /* <- dbgServerDesc */,
            /* cmdLineGenFunc: */
            function (/** @noinspection PhpUnusedParameterInspection */ int $port) {
                return self::runScriptNameToCmdLine('runMockApmServer.php');
            }
        );
    }

    /**
     * @param array<string, string> $additionalEnvVars
     *
     * @return array<string, string>
     */
    public function buildEnvVars(array $additionalEnvVars): array
    {
        ($loggerProxy = $this->logger->ifTraceLevelEnabled(__LINE__, __FUNCTION__))
        && $loggerProxy->log('Entered', ['additionalEnvVars' => $additionalEnvVars]);

        /** @var array<string, string> */
        $result = getenv();

        if (!is_null($this->mockApmServerPort)) {
            $result[TestConfigUtil::envVarNameForOption(OptionNames::SERVER_URL)]
                = 'http://localhost:' . $this->mockApmServerPort;
        }

        if (!is_null($this->resourcesCleanerPort)) {
            $result[TestConfigUtil::envVarNameForTestsOption(
                AllComponentTestsOptionsMetadata::RESOURCES_CLEANER_PORT_OPTION_NAME
            )]
                = strval($this->resourcesCleanerPort);
            TestCase::assertNotNull($this->resourcesCleanerServerId);
            $result[TestConfigUtil::envVarNameForTestsOption(
                AllComponentTestsOptionsMetadata::RESOURCES_CLEANER_SERVER_ID_OPTION_NAME
            )]
                = $this->resourcesCleanerServerId;
        }

        $result += $additionalEnvVars;

        ($loggerProxy = $this->logger->ifTraceLevelEnabled(__LINE__, __FUNCTION__))
        && $loggerProxy->log('Exiting', ['result' => $result]);

        return $result;
    }

    /**
     * @param TestProperties $testProperties
     * @param Closure        $verifyFunc
     *
     * @return void
     *
     * @phpstan-param Closure(DataFromAgent): void $verifyFunc
     */
    public function sendRequestToInstrumentedAppAndVerifyDataFromAgent(
        TestProperties $testProperties,
        Closure $verifyFunc
    ): void {
        try {
            $this->dataFromAgent->clearAdded();
            $timeBeforeRequestToApp = Clock::singletonInstance()->getSystemClockCurrentTime();
            $this->sendRequestToInstrumentedApp($testProperties);
            $this->pollDataFromAgentAndVerify($timeBeforeRequestToApp, $testProperties, $verifyFunc);
        } finally {
            $testProperties->tearDown();
        }
    }

    abstract protected function sendRequestToInstrumentedApp(TestProperties $testProperties): void;

    public function shutdown(): void
    {
        ($loggerProxy = $this->logger->ifDebugLevelEnabled(__LINE__, __FUNCTION__))
        && $loggerProxy->log('Shutting down...');

        $this->signalResourcesCleanerToExit();
    }

    private function signalResourcesCleanerToExit(): void
    {
        if (is_null($this->resourcesCleanerPort)) {
            return;
        }
        TestCase::assertNotNull($this->resourcesCleanerServerId);

        ($loggerProxy = $this->logger->ifDebugLevelEnabled(__LINE__, __FUNCTION__))
        && $loggerProxy->log(
            'Signaling ' . DbgUtil::fqToShortClassName(ResourcesCleaner::class) . ' to clean and exit...'
        );

        try {
            TestHttpClientUtil::sendHttpRequest(
                $this->resourcesCleanerPort,
                $this->resourcesCleanerServerId,
                HttpConsts::METHOD_POST,
                ResourcesCleaner::CLEAN_AND_EXIT_URI_PATH
            );
        } catch (GuzzleException $ex) {
            // clean-and-exit request is expected to throw
            // because ResourcesCleaner process exits before responding
        }

        ($loggerProxy = $this->logger->ifDebugLevelEnabled(__LINE__, __FUNCTION__))
        && $loggerProxy->log(
            'Signaled ' . DbgUtil::fqToShortClassName(ResourcesCleaner::class) . ' to clean and exit'
        );
    }

    /**
     * @param float          $timeBeforeRequestToApp
     * @param TestProperties $testProperties
     * @param Closure        $verifyFunc
     *
     * @return void
     *
     * @phpstan-param Closure(DataFromAgent): void $verifyFunc
     */
    public function pollDataFromAgentAndVerify(
        float $timeBeforeRequestToApp,
        TestProperties $testProperties,
        Closure $verifyFunc
    ): void {
        /** @var Exception|null */
        $lastException = null;
        $lastCheckedNextIntakeApiRequestIndex = $this->dataFromAgent->nextIntakeApiRequestIndexToFetch();
        $hasPassed = (new PollingCheck(
            __FUNCTION__ . ' passes',
            self::DATA_FROM_AGENT_MAX_WAIT_TIME_SECONDS * 1000 * 1000 /* maxWaitTimeInMicroseconds */,
            AmbientContext::loggerFactory()
        ))->run(
            function () use (
                $timeBeforeRequestToApp,
                $testProperties,
                $verifyFunc,
                &$lastException,
                &$lastCheckedNextIntakeApiRequestIndex
            ) {
                $this->ensureLatestDataFromMockApmServer($timeBeforeRequestToApp);
                $currentNextIntakeApiRequestIndex = $this->dataFromAgent->nextIntakeApiRequestIndexToFetch();
                if (
                    !is_null($lastException)
                    && ($currentNextIntakeApiRequestIndex === $lastCheckedNextIntakeApiRequestIndex)
                ) {
                    // No new data since the last check - there's no point in invoking $verifyFunc() again
                    return false;
                }

                $lastCheckedNextIntakeApiRequestIndex = $currentNextIntakeApiRequestIndex;
                try {
                    $this->verifyDataAgainstRequest($testProperties);
                    $verifyFunc($this->dataFromAgent);
                } catch (Exception $ex) {
                    if ($ex instanceof ConnectException || $ex instanceof PhpUnitException) {
                        $lastException = $ex;
                        return false;
                    }

                    throw $ex;
                }
                return true;
            }
        );

        if (!$hasPassed) {
            assert(!is_null($lastException));

            ($loggerProxy = $this->logger->ifErrorLevelEnabled(__LINE__, __FUNCTION__))
            && $loggerProxy->log(
                __FUNCTION__ . ' failed.',
                ['last exception from verifyFunc()' => $lastException]
            );

            throw $lastException;
        }
    }

    private function ensureLatestDataFromMockApmServer(float $timeBeforeRequestToApp): void
    {
        try {
            $newIntakeApiRequests = $this->fetchLatestDataFromMockApmServer();
            if (!empty($newIntakeApiRequests)) {
                $this->dataFromAgent->addIntakeApiRequests($newIntakeApiRequests, $timeBeforeRequestToApp);
            }
            return;
        } catch (Throwable $thrown) {
            ($loggerProxy = $this->logger->ifErrorLevelEnabled(__LINE__, __FUNCTION__))
            && $loggerProxy->log(
                'Failed to process data from the agent',
                ['thrown' => $thrown]
            );
            /** @noinspection PhpUnhandledExceptionInspection */
            throw $thrown;
        }
    }

    protected function verifyDataAgainstRequest(TestProperties $testProperties): void
    {
        $this->verifyHttpRequestHeaders($testProperties);

        $this->verifyMetadata($testProperties);

        $rootTransaction = TestCaseBase::findRootTransaction($this->dataFromAgent->idToTransaction);
        $this->verifyRootTransactionName($testProperties, $rootTransaction);
        $this->verifyRootTransactionType($testProperties, $rootTransaction);

        TestCaseBase::assertValidTransactionsAndSpans(
            $this->dataFromAgent->idToTransaction,
            $this->dataFromAgent->idToSpan
        );
    }

    protected function verifyHttpRequestHeaders(TestProperties $testProperties): void
    {
        $this->verifyAuthHttpRequestHeaders(
            $testProperties->configuredApiKey /* <- expectedApiKey */,
            /* expectedSecretToken: */
            is_null($testProperties->configuredApiKey)
                ? $testProperties->configuredSecretToken
                : null,
            $this->dataFromAgent
        );
    }

    public static function verifyAuthHttpRequestHeaders(
        ?string $expectedApiKey,
        ?string $expectedSecretToken,
        DataFromAgent $dataFromAgent
    ): void {
        if (!is_null($expectedApiKey)) {
            TestCase::assertNull($expectedSecretToken);
        }

        $expectedAuthHeaderValue = is_null($expectedApiKey)
            ? (is_null($expectedSecretToken) ? null : "Bearer $expectedSecretToken")
            : "ApiKey $expectedApiKey";

        foreach ($dataFromAgent->intakeApiRequests as $intakeApiRequest) {
            if (is_null($expectedAuthHeaderValue)) {
                TestCase::assertArrayNotHasKey(self::AUTH_HTTP_HEADER_NAME, $intakeApiRequest->headers);
            } else {
                $actualAuthHeaderValue = $intakeApiRequest->headers[self::AUTH_HTTP_HEADER_NAME];
                TestCase::assertCount(1, $actualAuthHeaderValue);
                TestCase::assertSame($expectedAuthHeaderValue, $actualAuthHeaderValue[0]);
            }
        }
    }

    protected function verifyMetadata(TestProperties $testProperties): void
    {
        $expectedEnvironment = Tracer::limitNullableKeywordString($testProperties->configuredEnvironment);
        self::verifyEnvironment($expectedEnvironment, $this->dataFromAgent);

        $expectedServiceName = is_null($testProperties->configuredServiceName)
            ? MetadataDiscoverer::DEFAULT_SERVICE_NAME
            : MetadataDiscoverer::adaptServiceName($testProperties->configuredServiceName);
        self::verifyServiceName($expectedServiceName, $this->dataFromAgent);

        $expectedServiceVersion = Tracer::limitNullableKeywordString($testProperties->configuredServiceVersion);
        self::verifyServiceVersion($expectedServiceVersion, $this->dataFromAgent);
    }

    public static function verifyEnvironment(?string $expected, DataFromAgent $dataFromAgent): void
    {
        foreach ($dataFromAgent->metadata as $metadata) {
            TestCase::assertSame($expected, $metadata->service()->environment());
        }
    }

    public static function verifyServiceName(string $expected, DataFromAgent $dataFromAgent): void
    {
        foreach ($dataFromAgent->metadata as $metadata) {
            TestCase::assertSame($expected, $metadata->service()->name());
        }
    }

    public static function verifyServiceVersion(?string $expected, DataFromAgent $dataFromAgent): void
    {
        foreach ($dataFromAgent->metadata as $metadata) {
            TestCase::assertSame($expected, $metadata->service()->version());
        }
    }

    protected function verifyRootTransactionName(
        TestProperties $testProperties,
        TransactionDataInterface $rootTransaction
    ): void {
        if (!is_null($testProperties->transactionName)) {
            TestCase::assertSame($testProperties->transactionName, $rootTransaction->getName());
        }
    }

    protected function verifyRootTransactionType(
        TestProperties $testProperties,
        TransactionDataInterface $rootTransaction
    ): void {
        if (!is_null($testProperties->transactionType)) {
            TestCase::assertSame($testProperties->transactionType, $rootTransaction->getType());
        }
    }

    /**
     * @return IntakeApiRequest[]
     */
    private function fetchLatestDataFromMockApmServer(): array
    {
        TestCase::assertNotNull($this->mockApmServerPort);
        TestCase::assertNotNull($this->mockApmServerId);

        $response = TestHttpClientUtil::sendHttpRequest(
            $this->mockApmServerPort,
            $this->mockApmServerId,
            HttpConsts::METHOD_GET,
            MockApmServer::MOCK_API_URI_PREFIX . MockApmServer::GET_INTAKE_API_REQUESTS,
            [MockApmServer::FROM_INDEX_HEADER_NAME => strval($this->dataFromAgent->nextIntakeApiRequestIndexToFetch())]
        );

        if ($response->getStatusCode() !== HttpConsts::STATUS_OK) {
            throw new RuntimeException('Received unexpected status code');
        }

        $decodedBody = json_decode($response->getBody()->getContents(), /* assoc */ true);

        $requestsJson = $decodedBody[MockApmServer::INTAKE_API_REQUESTS_JSON_KEY];
        $newIntakeApiRequests = [];
        foreach ($requestsJson as $requestJson) {
            $newIntakeApiRequests[] = IntakeApiRequest::jsonDeserialize($requestJson);
        }

        if (!empty($newIntakeApiRequests)) {
            ($loggerProxy = $this->logger->ifDebugLevelEnabled(__LINE__, __FUNCTION__))
            && $loggerProxy->log(
                'Fetched new intake API requests received from agent',
                ['newIntakeApiRequestsCount' => count($newIntakeApiRequests)]
            );
        }
        return $newIntakeApiRequests;
    }

    abstract public function isHttp(): bool;

    public function __toString(): string
    {
        $builder = new ObjectToStringBuilder(DbgUtil::fqToShortClassName(get_called_class()));
        $builder->add('testEnvId', $this->testEnvId());
        return $builder->build();
    }
}
