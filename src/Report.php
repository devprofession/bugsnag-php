<?php

namespace Bugsnag;

use Bugsnag\Breadcrumbs\Breadcrumb;
use Exception;
use InvalidArgumentException;
use Throwable;

class Report
{
    /**
     * The payload version.
     *
     * @var string
     */
    const PAYLOAD_VERSION = HttpClient::PAYLOAD_VERSION;

    /**
     * The config object.
     *
     * @var \Bugsnag\Config
     */
    protected $config;

    /**
     * The original error.
     *
     * @var \Throwable|array|null
     */
    protected $originalError;

    /**
     * The associated stacktrace.
     *
     * @var \Bugsnag\Stacktrace
     */
    protected $stacktrace;

    /**
     * The previous report.
     *
     * @var \Bugsnag\Report|null
     */
    protected $previous;

    /**
     * The error name.
     *
     * @var string
     */
    protected $name;

    /**
     * The error message.
     *
     * @var string|null
     */
    protected $message;

    /**
     * The error severity.
     *
     * @var string|null
     */
    protected $severity;

    /**
     * The associated context.
     *
     * @var string|null
     */
    protected $context;

    /**
     * The grouping hash.
     *
     * @var string|null
     */
    protected $groupingHash;

    /**
     * The associated meta data.
     *
     * @var array[]
     */
    protected $metaData = [];

    /**
     * The associated user.
     *
     * @var array
     */
    protected $user = [];

    /**
     * The associated breadcrumbs.
     *
     * @var array[]
     */
    protected $breadcrumbs = [];

    /**
     * The error time.
     *
     * @var string
     */
    protected $time;

    /**
     * Whether the error is handled or unhandled.
     *
     * @var bool
     */
    protected $unhandled = false;

    /**
     * Identifies cause for severity.
     *
     * @var array[]
     */
    protected $severityReason = [];

    /**
     * Attached session from SessionTracking.
     *
     * @var array
     */
    protected $session;

    /**
     * Create a new report from a PHP error.
     *
     * @param \Bugsnag\Configuration $config         the config instance
     * @param int                    $code           the error code
     * @param string|null            $message        the error message
     * @param string                 $file           the error file
     * @param int                    $line           the error line
     * @param bool                   $fatal          if the error was fatal
     *
     * @return static
     */
    public static function fromPHPError(Configuration $config, $code, $message, $file, $line, $fatal = false)
    {
        $report = new static($config);

        $report->setPHPError($code, $message, $file, $line, $fatal)
               ->setUnhandled(false)
               ->setSeverityReason(['type' => 'handledError']);

        return $report;
    }

    /**
     * Create a new report from a PHP throwable.
     *
     * @param \Bugsnag\Configuration $config         the config instance
     * @param \Throwable             $throwable      the throwable instance
     *
     * @return static
     */
    public static function fromPHPThrowable(Configuration $config, $throwable)
    {
        $report = new static($config);

        $report->setPHPThrowable($throwable)
               ->setUnhandled(false)
               ->setSeverityReason(['type' => 'handledException']);

        return $report;
    }

    /**
     * Create a new report from a named error.
     *
     * @param \Bugsnag\Configuration $config  the config instance
     * @param string                 $name    the error name
     * @param string|null            $message the error message
     *
     * @return static
     */
    public static function fromNamedError(Configuration $config, $name, $message = null)
    {
        $report = new static($config);

        $report->setName($name)
              ->setMessage($message)
              ->setStacktrace(Stacktrace::generate($config))
              ->setUnhandled(false)
              ->setSeverityReason(['type' => 'handledError']);

        return $report;
    }

    /**
     * Create a new report instance.
     *
     * This is only for for use only by the static methods above.
     *
     * @param \Bugsnag\Configuration $config the config instance
     *
     * @return void
     */
    protected function __construct(Configuration $config)
    {
        $this->config = $config;
        $this->time = gmdate('Y-m-d\TH:i:s\Z');
    }

    /**
     * Get the original error.
     *
     * @return \Throwable|array|null
     */
    public function getOriginalError()
    {
        return $this->originalError;
    }

    /**
     * Set the PHP throwable.
     *
     * @param \Throwable $throwable the throwable instance
     *
     * @throws \InvalidArgumentException
     *
     * @return $this
     */
    public function setPHPThrowable($throwable)
    {
        if (!$throwable instanceof Throwable && !$throwable instanceof Exception) {
            throw new InvalidArgumentException('The throwable must implement Throwable or extend Exception.');
        }

        $this->originalError = $throwable;

        $this->setName(get_class($throwable))
             ->setMessage($throwable->getMessage())
             ->setStacktrace(Stacktrace::fromBacktrace($this->config, $throwable->getTrace(), $throwable->getFile(), $throwable->getLine()));

        if (method_exists($throwable, 'getPrevious')) {
            $this->setPrevious($throwable->getPrevious());
        }

        return $this;
    }

    /**
     * Set the PHP error.
     *
     * @param int         $code    the error code
     * @param string|null $message the error message
     * @param string      $file    the error file
     * @param int         $line    the error line
     * @param bool        $fatal   if the error was fatal
     *
     * @return $this
     */
    public function setPHPError($code, $message, $file, $line, $fatal = false)
    {
        $this->originalError = [
            'code' => $code,
            'message' => $message,
            'file' => $file,
            'line' => $line,
            'fatal' => $fatal,
        ];

        if ($fatal) {
            // Generating stacktrace for PHP fatal errors is not possible,
            // since this code executes when the PHP process shuts down,
            // rather than at the time of the crash.
            //
            // In these situations, we generate a "stacktrace" containing only
            // the line and file number where the crash occurred.
            $stacktrace = Stacktrace::fromFrame($this->config, $file, $line);
        } else {
            $stacktrace = Stacktrace::generate($this->config);
        }

        $this->setName(ErrorTypes::getName($code))
             ->setMessage($message)
             ->setSeverity(ErrorTypes::getSeverity($code))
             ->setStacktrace($stacktrace);

        return $this;
    }

    /**
     * Set the bugsnag stacktrace.
     *
     * @param \Bugsnag\Stacktrace $stacktrace the stacktrace instance
     *
     * @return $this
     */
    protected function setStacktrace(Stacktrace $stacktrace)
    {
        $this->stacktrace = $stacktrace;

        return $this;
    }

    /**
     * Gets the severity reason.
     *
     * @return array
     */
    public function getSeverityReason()
    {
        if (!array_key_exists('type', $this->severityReason)) {
            syslog(LOG_WARNING, 'Severity reason should always have a "type" set');
            $this->severityReason['type'] = 'userSpecifiedSeverity';
        }

        return $this->severityReason;
    }

    /**
     * Sets the unhandled payload.
     *
     * @return $this
     */
    public function setSeverityReason(array $severityReason)
    {
        $this->severityReason = $severityReason;

        return $this;
    }

    /**
     * Sets the unhandled flag.
     *
     * @return $this
     */
    public function setUnhandled($unhandled)
    {
        $this->unhandled = $unhandled;

        return $this;
    }

    /**
     * Returns the unhandled flag.
     *
     * @return bool
     */
    public function getUnhandled()
    {
        return $this->unhandled;
    }

    /**
     * Get the bugsnag stacktrace.
     *
     * @return \Bugsnag\Stacktrace
     */
    public function getStacktrace()
    {
        return $this->stacktrace;
    }

    /**
     * Set the previous throwable.
     *
     * @param \Throwable $throwable the previous throwable
     *
     * @return $this
     */
    protected function setPrevious($throwable)
    {
        if ($throwable) {
            $this->previous = static::fromPHPThrowable($this->config, $throwable);
        }

        return $this;
    }

    /**
     * Set the error name.
     *
     * @param string $name the error name
     *
     * @throws \InvalidArgumentException
     *
     * @return $this
     */
    public function setName($name)
    {
        if (is_scalar($name) || method_exists($name, '__toString')) {
            $this->name = (string) $name;
        } else {
            throw new InvalidArgumentException('The name must be a string.');
        }

        if ($this->name === '') {
            $this->name = 'Error';
        }

        return $this;
    }

    /**
     * Get the error name.
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Set the error message.
     *
     * @param string|null $message the error message
     *
     * @throws \InvalidArgumentException
     *
     * @return $this
     */
    public function setMessage($message)
    {
        if ($message === null) {
            $this->message = null;
        } elseif (is_scalar($message) || method_exists($message, '__toString')) {
            $this->message = (string) $message;
        } else {
            throw new InvalidArgumentException('The message must be a string.');
        }

        return $this;
    }

    /**
     * Get the error message.
     *
     * @return string|null
     */
    public function getMessage()
    {
        return $this->message;
    }

    /**
     * Set the error severity.
     *
     * @param string|null $severity the error severity
     *
     * @throws \InvalidArgumentException
     *
     * @return $this
     */
    public function setSeverity($severity)
    {
        if (in_array($severity, ['error', 'warning', 'info', null], true)) {
            $this->severity = $severity;
        } else {
            throw new InvalidArgumentException('The severity must be either "error", "warning", or "info".');
        }

        return $this;
    }

    /**
     * Get the error severity.
     *
     * @return string
     */
    public function getSeverity()
    {
        return $this->severity ?: 'warning';
    }

    /**
     * Set a context representing the current type of request, or location in code.
     *
     * @param string|null $context the current context
     *
     * @return $this
     */
    public function setContext($context)
    {
        $this->context = $context;

        return $this;
    }

    /**
     * Get the error context.
     *
     * @return string|null
     */
    public function getContext()
    {
        return $this->context;
    }

    /**
     * Set the grouping hash.
     *
     * @param string|null $groupingHash the grouping hash
     *
     * @return $this
     */
    public function setGroupingHash($groupingHash)
    {
        $this->groupingHash = $groupingHash;

        return $this;
    }

    /**
     * Get the grouping hash.
     *
     * @return string|null
     */
    public function getGroupingHash()
    {
        return $this->groupingHash;
    }

    /**
     * Set the error meta data.
     *
     * @param array[] $metaData an array of arrays of custom data
     * @param bool    $merge    should we merge the meta data
     *
     * @return $this
     */
    public function setMetaData(array $metaData, $merge = true)
    {
        $this->metaData = $merge ? array_merge_recursive($this->metaData, $metaData) : $metaData;

        return $this;
    }

    /**
     * Adds a tab to the meta data.
     * Conflicting keys will be merged if able, otherwise the new values will be accepted.
     * Null values will be deleted from the metadata.
     *
     * @param array[] $metadata an array of custom data to attach to the report
     *
     * @return $this
     */
    public function addMetaData(array $metadata)
    {
        $this->metaData = array_replace_recursive($this->metaData, $metadata);
        $this->metaData = $this->removeNullElements($this->metaData);

        return $this;
    }

    /**
     * Get the error meta data.
     *
     * @return array[]
     */
    public function getMetaData()
    {
        return $this->metaData;
    }

    /**
     * Set the current user.
     *
     * @param array $user the current user
     *
     * @return $this
     */
    public function setUser(array $user)
    {
        $this->user = $user;

        return $this;
    }

    /**
     * Get the current user.
     *
     * @return array
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * Add a breadcrumb to the report.
     *
     * @param \Bugsnag\Breadcrumbs\Breadcrumb $breadcrumb
     *
     * @return void
     */
    public function addBreadcrumb(Breadcrumb $breadcrumb)
    {
        $data = $breadcrumb->toArray();

        if ($metaData = $this->cleanupObj($breadcrumb->getMetaData(), true)) {
            $data['metaData'] = $metaData;

            if (strlen(json_encode($data)) > Breadcrumb::MAX_SIZE) {
                unset($data['metaData']);
            }
        }

        $this->breadcrumbs[] = $data;
    }

    /**
     * Get the report summary.
     *
     * @return string[]
     */
    public function getSummary()
    {
        $summary = [];

        $name = $this->getName();
        $message = $this->getMessage();

        if ($name !== $message) {
            $summary['name'] = $name;
        }

        $summary['message'] = $message;

        $summary['severity'] = $this->getSeverity();

        return array_filter($summary);
    }

    /**
     * Sets the session data.
     *
     * @return $this
     */
    public function setSessionData(array $session)
    {
        $this->session = $session;
    }

    /**
     * Get the array representation.
     *
     * @return array
     */
    public function toArray()
    {
        $event = [
            'app' => $this->config->getAppData(),
            'device' => array_merge(['time' => $this->time], $this->config->getDeviceData()),
            'user' => $this->getUser(),
            'context' => $this->getContext(),
            'payloadVersion' => HttpClient::PAYLOAD_VERSION,
            'severity' => $this->getSeverity(),
            'exceptions' => $this->exceptionArray(),
            'breadcrumbs' => $this->breadcrumbs,
            'metaData' => $this->cleanupObj($this->getMetaData(), true),
            'unhandled' => $this->getUnhandled(),
            'severityReason' => $this->getSeverityReason(),
        ];

        if ($hash = $this->getGroupingHash()) {
            $event['groupingHash'] = $hash;
        }

        if (isset($this->session)) {
            $event['session'] = $this->session;
        }

        return $event;
    }

    /**
     * Get the exception array.
     *
     * @return array
     */
    protected function exceptionArray()
    {
        $exceptionArray = [$this->exceptionObject()];
        $previous = $this->previous;
        while ($previous) {
            $exceptionArray[] = $previous->exceptionObject();
            $previous = $previous->previous;
        }

        return $this->cleanupObj($exceptionArray, false);
    }

    /**
     * Get serializable representation of the exception causing this report.
     *
     * @return array
     */
    protected function exceptionObject()
    {
        return [
            'errorClass' => $this->name,
            'message' => $this->message,
            'stacktrace' => $this->stacktrace->toArray(),
        ];
    }

    /**
     * Cleanup the given object.
     *
     * @param mixed $obj        the data to cleanup
     * @param bool  $isMetaData if it is meta data
     *
     * @return array|null
     */
    protected function cleanupObj($obj, $isMetaData)
    {
        if (is_null($obj)) {
            return;
        }

        if (is_array($obj)) {
            $clean = [];

            foreach ($obj as $key => $value) {
                $clean[$key] = $this->shouldFilter($key, $isMetaData) ? '[FILTERED]' : $this->cleanupObj($value, $isMetaData);
            }

            return $clean;
        }

        if (is_string($obj)) {
            return (function_exists('mb_detect_encoding') && !mb_detect_encoding($obj, 'UTF-8', true)) ? utf8_encode($obj) : $obj;
        }

        if (is_object($obj)) {
            return $this->cleanupObj(json_decode(json_encode($obj), true), $isMetaData);
        }

        return $obj;
    }

    /**
     * Should we filter the given element.
     *
     * @param string $key        the associated key
     * @param bool   $isMetaData if it is meta data
     *
     * @return bool
     */
    protected function shouldFilter($key, $isMetaData)
    {
        if ($isMetaData) {
            foreach ($this->config->getFilters() as $filter) {
                if (strpos($key, $filter) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Recursively remove null elements.
     *
     * @param array $array  the array to remove null elements from
     *
     * @return array
     */
    protected function removeNullElements($array)
    {
        foreach ($array as $key => $val) {
            if (is_array($val)) {
                $array[$key] = $this->removeNullElements($val);
            } elseif (is_null($val)) {
                unset($array[$key]);
            }
        }

        return $array;
    }
}
