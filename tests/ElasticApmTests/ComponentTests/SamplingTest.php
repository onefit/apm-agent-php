<?php

declare(strict_types=1);

namespace Elastic\Apm\Tests\ComponentTests;

use Elastic\Apm\ElasticApm;
use Elastic\Apm\Impl\Util\ArrayUtil;
use Elastic\Apm\Tests\ComponentTests\Util\ComponentTestCaseBase;
use Elastic\Apm\Tests\ComponentTests\Util\ConfigSetterBase;
use Elastic\Apm\Tests\ComponentTests\Util\DataFromAgent;
use Elastic\Apm\Tests\ComponentTests\Util\TestProperties;
use RuntimeException;

final class SamplingTest extends ComponentTestCaseBase
{
    /**
     * @return iterable<array<ConfigSetterBase|float|null>>
     */
    public function rateConfigTestDataProvider(): iterable
    {
        yield [null, null];

        foreach ($this->configSetterTestDataProvider() as $configSetter) {
            self::assertCount(1, $configSetter);
            foreach ([0.0, 0.001, 0.01, 0.1, 0.3, 0.5, 0.9, 1.0] as $transactionSampleRate) {
                yield [$configSetter[0], $transactionSampleRate];
            }
        }
    }

    /**
     * @param array<string, mixed> $args
     */
    public static function appCodeForRateConfigTest(array $args): void
    {
        $tx = ElasticApm::getCurrentTransaction();
        $transactionSampleRate = ArrayUtil::getValueIfKeyExistsElse('transactionSampleRate', $args, 1.0);
        $expectedIsSampled = $transactionSampleRate === 1.0 ? true : ($transactionSampleRate === 0.0 ? false : null);
        if (!is_null($expectedIsSampled) && $tx->isSampled() !== $expectedIsSampled) {
            $tx->discard();
            throw new RuntimeException(
                "transactionSampleRate: $transactionSampleRate" .
                " expectedIsSampled: $expectedIsSampled" .
                " tx->isSampled(): " . ($tx->isSampled() ? 'true' : 'false')
            );
        }

        $tx->setLabel('TX_label_key', 123);
        $tx->captureCurrentSpan(
            'span1_name',
            'span1_type',
            function () use ($tx) {
                $tx->captureCurrentSpan(
                    'span11_name',
                    'span11_type',
                    function () {
                    }
                );
            }
        );
    }

    /**
     * @dataProvider rateConfigTestDataProvider
     *
     * @param ConfigSetterBase|null $configSetter
     * @param float|null            $transactionSampleRate
     */
    public function testRateConfig(?ConfigSetterBase $configSetter, ?float $transactionSampleRate): void
    {
        $testProperties = new TestProperties(
            [__CLASS__, 'appCodeForRateConfigTest'],
            /* appCodeArgs: */ ['transactionSampleRate' => $transactionSampleRate]
        );
        if (is_null($transactionSampleRate)) {
            self::assertNull($configSetter);
        } else {
            self::assertNotNull($configSetter);
            $testProperties->withConfig($configSetter)->transactionSampleRate(strval($transactionSampleRate));
        }
        $this->sendRequestToInstrumentedAppAndVerifyDataFromAgentEx(
            $testProperties,
            function (DataFromAgent $dataFromAgent) use ($transactionSampleRate): void {
                $tx = $dataFromAgent->singleTransaction();
                if (is_null($transactionSampleRate) || $transactionSampleRate === 1.0) {
                    self::assertTrue($tx->isSampled());
                } elseif ($transactionSampleRate === 0.0) {
                    self::assertFalse($tx->isSampled());
                }

                if ($tx->isSampled()) {
                    self::assertCount(2, $dataFromAgent->idToSpan);
                    // Started and dropped spans should be counted only for sampled transactions
                    self::assertSame(2, $tx->getStartedSpansCount());

                    self::assertCount(1, $tx->getLabels());
                    self::assertSame(123, $tx->getLabels()['TX_label_key']);
                } else {
                    self::assertEmpty($dataFromAgent->idToSpan);
                    // Started and dropped spans should be counted only for sampled transactions
                    self::assertSame(0, $tx->getStartedSpansCount());

                    self::assertEmpty($tx->getLabels());
                }
                self::assertSame(0, $tx->getDroppedSpansCount());
            }
        );
    }
}
