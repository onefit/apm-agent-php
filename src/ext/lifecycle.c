/*
   +----------------------------------------------------------------------+
   | Elastic APM agent for PHP                                            |
   +----------------------------------------------------------------------+
   | Copyright (c) 2020 Elasticsearch B.V.                                |
   +----------------------------------------------------------------------+
   | Elasticsearch B.V. licenses this file under the Apache 2.0 License.  |
   | See the LICENSE file in the project root for more information.       |
   +----------------------------------------------------------------------+
 */

#include "lifecycle.h"
#if defined(PHP_WIN32) && ! defined(CURL_STATICLIB)
#   define CURL_STATICLIB
#endif
#include <curl/curl.h>
#include <inttypes.h> // PRIu64
#include <stdbool.h>
#include <php.h>
#include <zend_compile.h>
#include <zend_exceptions.h>
#include "php_elastic_apm.h"
#include "log.h"
#include "SystemMetrics.h"
#include "php_error.h"
#include "util_for_PHP.h"
#include "elastic_apm_assert.h"
#include "MemoryTracker.h"
#include "supportability.h"
#include "elastic_apm_alloc.h"
#include "elastic_apm_API.h"
#include "tracer_PHP_part.h"
#include "backend_comm.h"

#define ELASTIC_APM_CURRENT_LOG_CATEGORY ELASTIC_APM_LOG_CATEGORY_LIFECYCLE

static const char JSON_METRICSET[] =
        "{\"metricset\":{\"samples\":{\"system.cpu.total.norm.pct\":{\"value\":%.2f},\"system.process.cpu.total.norm.pct\":{\"value\":%.2f},\"system.memory.actual.free\":{\"value\":%"PRIu64"},\"system.memory.total\":{\"value\":%"PRIu64"},\"system.process.memory.size\":{\"value\":%"PRIu64"},\"system.process.memory.rss.bytes\":{\"value\":%"PRIu64"}},\"timestamp\":%"PRIu64"}}\n";

static
String buildSupportabilityInfo( size_t supportInfoBufferSize, char* supportInfoBuffer )
{
    TextOutputStream txtOutStream = makeTextOutputStream( supportInfoBuffer, supportInfoBufferSize );
    TextOutputStreamState txtOutStreamStateOnEntryStart;
    if ( ! textOutputStreamStartEntry( &txtOutStream, &txtOutStreamStateOnEntryStart ) )
    {
        return ELASTIC_APM_TEXT_OUTPUT_STREAM_NOT_ENOUGH_SPACE_MARKER;
    }

    StructuredTextToOutputStreamPrinter structTxtToOutStreamPrinter;
    initStructuredTextToOutputStreamPrinter(
            /* in */ &txtOutStream
                     , /* prefix */ ELASTIC_APM_STRING_LITERAL_TO_VIEW( "" )
                     , /* out */ &structTxtToOutStreamPrinter );

    printSupportabilityInfo( (StructuredTextPrinter*) &structTxtToOutStreamPrinter );

    return textOutputStreamEndEntry( &txtOutStreamStateOnEntryStart, &txtOutStream );
}

void logSupportabilityInfo( LogLevel logLevel )
{
    ResultCode resultCode;
    enum
    {
        supportInfoBufferSize = 100 * 1000 + 1
    };
    char* supportInfoBuffer = NULL;

    ELASTIC_APM_PEMALLOC_STRING_IF_FAILED_GOTO( supportInfoBufferSize, supportInfoBuffer );
    String supportabilityInfo = buildSupportabilityInfo( supportInfoBufferSize, supportInfoBuffer );

    ELASTIC_APM_LOG_WITH_LEVEL( logLevel, "Supportability info:\n%s", supportabilityInfo );

    // resultCode = resultSuccess;

    finally:
    ELASTIC_APM_PEFREE_STRING_AND_SET_TO_NULL( supportInfoBufferSize, supportInfoBuffer );
    ELASTIC_APM_UNUSED( resultCode );
    return;

    failure:
    goto finally;
}

void elasticApmModuleInit( int type, int moduleNumber )
{
    ResultCode resultCode;
    Tracer* const tracer = getGlobalTracer();
    const ConfigSnapshot* config = NULL;

    ELASTIC_APM_CALL_IF_FAILED_GOTO( constructTracer( tracer ) );

    ELASTIC_APM_LOG_DEBUG_FUNCTION_ENTRY();

    if ( ! tracer->isInited )
    {
        resultCode = resultFailure;
        ELASTIC_APM_LOG_DEBUG_FUNCTION_EXIT_MSG( "Extension is not initialized" );
        goto failure;
    }

    registerElasticApmIniEntries( moduleNumber, &tracer->iniEntriesRegistrationState );

    ELASTIC_APM_CALL_IF_FAILED_GOTO( ensureAllComponentsHaveLatestConfig( tracer ) );
    logSupportabilityInfo( logLevel_debug );
    config = getTracerCurrentConfigSnapshot( tracer );

    if ( ! config->enabled )
    {
        resultCode = resultSuccess;
        ELASTIC_APM_LOG_DEBUG_FUNCTION_EXIT_MSG( "Because extension is not enabled" );
        goto finally;
    }

    CURLcode result = curl_global_init( CURL_GLOBAL_ALL );
    if ( result != CURLE_OK )
    {
        resultCode = resultFailure;
        ELASTIC_APM_LOG_TRACE_FUNCTION_EXIT_MSG( "curl_global_init failed" );
        goto finally;
    }
    tracer->curlInited = true;

    resultCode = resultSuccess;
    ELASTIC_APM_LOG_DEBUG_FUNCTION_EXIT();

    finally:

    // We ignore errors because we want the monitored application to continue working
    // even if APM encountered an issue that prevent it from working
    ELASTIC_APM_UNUSED( resultCode );
    return;

    failure:
    moveTracerToFailedState( tracer );
    goto finally;
}

void elasticApmModuleShutdown( int type, int moduleNumber )
{
    ELASTIC_APM_UNUSED( type );

    ResultCode resultCode;

    ELASTIC_APM_LOG_DEBUG_FUNCTION_ENTRY();

    Tracer* const tracer = getGlobalTracer();
    const ConfigSnapshot* const config = getTracerCurrentConfigSnapshot( tracer );

    if ( ! config->enabled )
    {
        resultCode = resultSuccess;
        ELASTIC_APM_LOG_DEBUG_FUNCTION_EXIT_MSG( "Because extension is not enabled" );
        goto finally;
    }

    if ( tracer->curlInited )
    {
        curl_global_cleanup();
        tracer->curlInited = false;
    }

    unregisterElasticApmIniEntries( moduleNumber, &tracer->iniEntriesRegistrationState );

    resultCode = resultSuccess;
    ELASTIC_APM_LOG_DEBUG_FUNCTION_EXIT();

    finally:
    destructTracer( tracer );

    // We ignore errors because we want the monitored application to continue working
    // even if APM encountered an issue that prevent it from working
    ELASTIC_APM_UNUSED( resultCode );
}

void elasticApmRequestInit()
{
#if defined(ZTS) && defined(COMPILE_DL_ELASTIC_APM)
    ZEND_TSRMLS_CACHE_UPDATE();
#endif

    TimePoint requestInitStartTime;
    getCurrentTime( &requestInitStartTime );

    ELASTIC_APM_LOG_DEBUG_FUNCTION_ENTRY();

    ResultCode resultCode;
    Tracer* const tracer = getGlobalTracer();
    const ConfigSnapshot* config = getTracerCurrentConfigSnapshot( tracer );

    if ( ! tracer->isInited )
    {
        resultCode = resultFailure;
        ELASTIC_APM_LOG_DEBUG_FUNCTION_EXIT_MSG( "Extension is not initialized" );
        goto failure;
    }

    if ( ! config->enabled )
    {
        resultCode = resultSuccess;
        ELASTIC_APM_LOG_DEBUG_FUNCTION_EXIT_MSG( "Because extension is not enabled" );
        goto finally;
    }

    ELASTIC_APM_CALL_IF_FAILED_GOTO( constructRequestScoped( &tracer->requestScoped ) );

    if ( isMemoryTrackingEnabled( &tracer->memTracker ) ) memoryTrackerRequestInit( &tracer->memTracker );

    ELASTIC_APM_CALL_IF_FAILED_GOTO( ensureAllComponentsHaveLatestConfig( tracer ) );
    logSupportabilityInfo( logLevel_trace );

    ELASTIC_APM_CALL_IF_FAILED_GOTO( bootstrapTracerPhpPart( config, &requestInitStartTime ) );

    readSystemMetrics( &tracer->startSystemMetricsReading );

    resultCode = resultSuccess;

    finally:

    ELASTIC_APM_LOG_DEBUG_FUNCTION_EXIT_MSG( "resultCode: %s (%d)", resultCodeToString( resultCode ), resultCode );
    // We ignore errors because we want the monitored application to continue working
    // even if APM encountered an issue that prevent it from working
    ELASTIC_APM_UNUSED( resultCode );
    return;

    failure:
    goto finally;
}

static
void appendMetrics( const SystemMetricsReading* startSystemMetricsReading, const TimePoint* currentTime, TextOutputStream* serializedEventsTxtOutStream )
{
    SystemMetricsReading endSystemMetricsReading;
    readSystemMetrics( &endSystemMetricsReading );
    SystemMetrics system_metrics;
    getSystemMetrics( startSystemMetricsReading, &endSystemMetricsReading, &system_metrics );

    streamPrintf(
            serializedEventsTxtOutStream
            , JSON_METRICSET
            , system_metrics.machineCpu // system.cpu.total.norm.pct
            , system_metrics.processCpu // system.process.cpu.total.norm.pct
            , system_metrics.machineMemoryFree  // system.memory.actual.free
            , system_metrics.machineMemoryTotal // system.memory.total
            , system_metrics.processMemorySize  // system.process.memory.size
            , system_metrics.processMemoryRss   // system.process.memory.rss.bytes
            , timePointToEpochMicroseconds( currentTime ) );
}

static void sendMetrics( const Tracer* tracer, const ConfigSnapshot* config )
{
    ResultCode resultCode;
    TimePoint currentTime;
    enum { serializedEventsBufferSize = 1000 * 1000 };
    char* serializedEventsBuffer = NULL;

    if ( isEmptyStringView( tracer->requestScoped.lastMetadataFromPhpPart ) )
    {
        ELASTIC_APM_LOG_ERROR( "Cannot send metrics because there's no last metadata from PHP part" );
        resultCode = resultFailure;
        goto failure;
    }

    getCurrentTime( &currentTime );

    ELASTIC_APM_EMALLOC_STRING_IF_FAILED_GOTO( serializedEventsBufferSize, serializedEventsBuffer );
    TextOutputStream serializedEventsTxtOutStream =
            makeTextOutputStream( serializedEventsBuffer, serializedEventsBufferSize );
    serializedEventsTxtOutStream.autoTermZero = false;

    streamStringView( tracer->requestScoped.lastMetadataFromPhpPart, &serializedEventsTxtOutStream );
    streamStringView( ELASTIC_APM_STRING_LITERAL_TO_VIEW( "\n" ), &serializedEventsTxtOutStream );
    serializedEventsTxtOutStream.autoTermZero = true;
    appendMetrics( &tracer->startSystemMetricsReading, &currentTime, &serializedEventsTxtOutStream );

    sendEventsToApmServer( config, textOutputStreamContentAsStringView( &serializedEventsTxtOutStream ) );

    resultCode = resultSuccess;

    finally:
    ELASTIC_APM_EFREE_STRING_AND_SET_TO_NULL( serializedEventsBufferSize, serializedEventsBuffer );

    ELASTIC_APM_LOG_DEBUG_FUNCTION_EXIT_MSG( "resultCode: %s (%d)", resultCodeToString( resultCode ), resultCode );
    // We ignore errors because we want the monitored application to continue working
    // even if APM encountered an issue that prevent it from working
    ELASTIC_APM_UNUSED( resultCode );
    return;

    failure:
    goto finally;
}

void elasticApmRequestShutdown()
{
    ResultCode resultCode;
    Tracer* const tracer = getGlobalTracer();
    const ConfigSnapshot* const config = getTracerCurrentConfigSnapshot( tracer );

    ELASTIC_APM_LOG_DEBUG_FUNCTION_ENTRY();

    if ( ! tracer->isInited )
    {
        resultCode = resultFailure;
        ELASTIC_APM_LOG_TRACE_FUNCTION_EXIT_MSG( "Extension is not initialized" );
        goto failure;
    }

    if ( ! config->enabled )
    {
        resultCode = resultSuccess;
        ELASTIC_APM_LOG_DEBUG_FUNCTION_EXIT_MSG( "Because extension is not enabled" );
        goto failure;
    }

    // We should shutdown PHP part first because sendMetrics() uses metadata sent by PHP part on shutdown
    shutdownTracerPhpPart( config );

    // sendMetrics( tracer, config );

    resetCallInterceptionOnRequestShutdown();

    destructRequestScoped( &tracer->requestScoped );

    resultCode = resultSuccess;

    finally:
    if ( isMemoryTrackingEnabled( &tracer->memTracker ) ) memoryTrackerRequestShutdown( &tracer->memTracker );

    ELASTIC_APM_LOG_DEBUG_FUNCTION_EXIT_MSG( "resultCode: %s (%d)", resultCodeToString( resultCode ), resultCode );
    // We ignore errors because we want the monitored application to continue working
    // even if APM encountered an issue that prevent it from working
    ELASTIC_APM_UNUSED( resultCode );
    return;

    failure:
    goto finally;
}
