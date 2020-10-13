<?php

declare(strict_types=1);

namespace Elastic\Apm\Impl\AutoInstrument;

use Elastic\Apm\AutoInstrument\InterceptedCallTrackerInterface;
use Elastic\Apm\AutoInstrument\RegistrationContextInterface;
use Elastic\Apm\ElasticApm;
use Elastic\Apm\Impl\Constants;
use Elastic\Apm\Impl\Util\DbgUtil;
use Elastic\Apm\SpanInterface;

/**
 * Code in this file is part of implementation internals and thus it is not covered by the backward compatibility.
 *
 * @internal
 */
final class PdoMySQLAutoInstrumentation
{
    public static function register(RegistrationContextInterface $ctx): void
    {
        self::pdoStatementExecute($ctx);
    }

    public static function pdoStatementExecute(RegistrationContextInterface $ctx): void
    {
        $ctx->interceptCallsToMethod(
            'pdostatement',
            'execute',
            function (): InterceptedCallTrackerInterface {
                return new class implements InterceptedCallTrackerInterface {
                    use InterceptedCallTrackerTrait;

                    /** @var SpanInterface */
                    private $span;

                    public function preHook($thisObj, ...$interceptedCallArgs): void
                    {
                        $this->span = ElasticApm::beginCurrentSpan(
                            $thisObj->queryString,
                            Constants::SPAN_TYPE_DB,
                            Constants::SPAN_TYPE_DB_SUBTYPE_MYSQL
                        );
                    }

                    public function postHook(bool $hasExitedByException, $returnValueOrThrown): void
                    {
                        if (!$hasExitedByException) {
                            $this->span->setLabel('rows_affected', (int)$returnValueOrThrown);
                        }

                        self::endSpan($this->span, $hasExitedByException, $returnValueOrThrown);
                    }
                };
            }
        );
    }
}
