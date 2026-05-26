<?php

/**
 * AYANE : ayane.co.jp
 * powered by NENE.
 *
 * PHP Version >= 8.4
 *
 * @package   AYANE
 * @author    hideyukiMORI <info@ayane.co.jp>
 * @copyright 2021 AYANE
 * @license   https://opensource.org/licenses/MIT MIT License
 * @link      https://ayane.co.jp/
 */

declare(strict_types=1);

namespace Nene\Xion;

use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;

/**
 * Selects the Monolog formatter based on the LOG_FORMAT constant (FT19).
 *
 * Isolated from the Log singleton so that formatter selection can be unit-tested
 * without instantiating file handlers or requiring path constants.
 *
 * Supported formats:
 *  - 'json'  → JsonFormatter  (one JSON object per line; ingest-ready for
 *              Datadog / Loki / Elasticsearch / any NDJSON consumer)
 *  - 'text'  → LineFormatter  (Monolog default; human-readable for dev / tail)
 *
 * Controlled by the NENE_LOG_FORMAT environment variable, resolved to the
 * LOG_FORMAT constant in ini/xSystemIni.php. Unknown values fall back to text.
 *
 * @see Log
 */
final class LogFormatterFactory
{
    /**
     * Build the formatter for all Monolog handlers.
     *
     * @param string $format 'json' or 'text' (any other value → text)
     * @return FormatterInterface
     */
    public static function create(string $format = 'text'): FormatterInterface
    {
        if ($format === 'json') {
            return new JsonFormatter();
        }

        return new LineFormatter();
    }

    /**
     * Convenience: read the LOG_FORMAT constant (if defined) and return the
     * appropriate formatter. Used by Log::__construct() to stay DRY.
     *
     * @return FormatterInterface
     */
    public static function fromConstant(): FormatterInterface
    {
        $format = defined('LOG_FORMAT') ? (string) constant('LOG_FORMAT') : 'text';

        return self::create($format);
    }
}
