<?php

namespace Growthbook;

use Error;
use Exception;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\SimpleCache\CacheInterface;
use React\EventLoop\Factory as LoopFactory;
use React\EventLoop\LoopInterface;
use React\Http\Browser;
use React\Promise\PromiseInterface;
use RuntimeException;
use Throwable;
use function React\Promise\resolve;
use function React\Promise\Timer\timeout;

/**
 * Class Growthbook
 *
 * This class manages feature flagging, experiment assignment, and caching.
 */
class Growthbook implements LoggerAwareInterface
{
    private const DEFAULT_API_HOST = "https://cdn.growthbook.io";

    /** @var bool */
    public $enabled = true;

    /** @var LoggerInterface|null */
    public $logger = null;

    /** @var string */
    private $url = "";

    /** @var array<string,mixed> */
    private $attributes = [];

    /** @var Feature<mixed>[] */
    private $features = [];

    /** @var array<string, FeatureResult<mixed>> */
    private $forcedFeatures = [];

    /** @var array<string,int> */
    private $forcedVariations = [];

    /** @var bool */
    public $qaMode = false;

    /** @var callable|null */
    private $trackingCallback = null;

    /** @var CacheInterface|null */
    private $cache = null;

    /** @var int */
    private $cacheTTL = 60;

    /**
     * Because $httpClient can be either a PSR-18 ClientInterface or React\Http\Browser,
     * we handle synchronous and asynchronous requests differently.
     *
     * @var ClientInterface|Browser|null
     */
    private $httpClient;

    /** @var RequestFactoryInterface|null */
    public $requestFactory;

    /** @var string */
    private $apiHost = "";

    /** @var string */
    private $clientKey = "";

    /** @var string */
    private $decryptionKey = "";

    /** @var array<string, ViewedExperiment> */
    private $tracks = [];

    /** @var LoopInterface */
    private $loop;

    /** @var Browser */
    private $asyncClient;

    /**
     * Non-generic typehint, since React\Promise\PromiseInterface is not generic.
     *
     * @var PromiseInterface|null
     */
    public $promise;

    public static function create(): Growthbook
    {
        return new Growthbook();
    }

    /**
     * @param array{
     *   enabled?: bool,
     *   logger?: LoggerInterface,
     *   url?: string,
     *   attributes?: array<string,mixed>,
     *   features?: array<string,mixed>,
     *   forcedVariations?: array<string,int>,
     *   forcedFeatures?: array<string, FeatureResult<mixed>>,
     *   qaMode?: bool,
     *   trackingCallback?: callable,
     *   cache?: CacheInterface,
     *   httpClient?: ClientInterface|Browser,
     *   requestFactory?: RequestFactoryInterface,
     *   decryptionKey?: string,
     *   loop?: LoopInterface
     * } $options
     */
    public function __construct(array $options = [])
    {
        // We do not mark resolve(null) as "PromiseInterface<mixed>" in docblocks
        // because React\Promise\PromiseInterface is not generic.
        $this->promise = resolve(null);

        // Known config options for error-checking
        $knownOptions = [
            "enabled",
            "logger",
            "url",
            "attributes",
            "features",
            "forcedVariations",
            "forcedFeatures",
            "qaMode",
            "trackingCallback",
            "cache",
            "httpClient",
            "requestFactory",
            "decryptionKey",
            "loop"
        ];
        $unknownOptions = array_diff(array_keys($options), $knownOptions);
        if (count($unknownOptions)) {
            trigger_error(
                'Unknown Config options: ' . implode(", ", $unknownOptions),
                E_USER_NOTICE
            );
        }

        $this->enabled = $options["enabled"] ?? true;
        $this->logger = $options["logger"] ?? null;
        $this->url = $options["url"] ?? ($_SERVER['REQUEST_URI'] ?? "");
        $this->forcedVariations = $options["forcedVariations"] ?? [];
        $this->qaMode = $options["qaMode"] ?? false;
        $this->trackingCallback = $options["trackingCallback"] ?? null;
        $this->decryptionKey = $options["decryptionKey"] ?? "";
        $this->cache = $options["cache"] ?? null;

        // ReactPHP EventLoop (used for async calls)
        $this->loop = $options['loop'] ?? LoopFactory::create();
        $this->asyncClient = new Browser($this->loop);

        $this->httpClient = $options["httpClient"] ?? null;
        $this->requestFactory = $options["requestFactory"] ?? null;

        // Try discovering PSR-18 and PSR-17 implementations if not supplied
        if (!$this->httpClient) {
            try {
                $this->httpClient = Psr18ClientDiscovery::find();
            } catch (Throwable $e) {
                // If no PSR-18 client is found, it remains null
            }
        }
        if (!$this->requestFactory) {
            try {
                $this->requestFactory = Psr17FactoryDiscovery::findRequestFactory();
            } catch (Throwable $e) {
                // If no request factory is found, it remains null
            }
        }

        // Forced features
        if (array_key_exists("forcedFeatures", $options)) {
            $this->withForcedFeatures($options['forcedFeatures']);
        }
        // Features
        if (array_key_exists("features", $options)) {
            $this->withFeatures($options["features"]);
        }
        // Attributes
        if (array_key_exists("attributes", $options)) {
            $this->withAttributes($options["attributes"]);
        }
    }

    /**
     * @param array<string,mixed> $attributes
     * @return $this
     */
    public function withAttributes(array $attributes): self
    {
        $this->attributes = $attributes;
        return $this;
    }

    /**
     * @param callable|null $trackingCallback
     * @return $this
     */
    public function withTrackingCallback($trackingCallback): self
    {
        $this->trackingCallback = $trackingCallback;
        return $this;
    }

    /**
     * @param array<string,Feature<mixed>|mixed> $features
     * @return $this
     */
    public function withFeatures(array $features): self
    {
        $this->features = [];
        foreach ($features as $key => $feature) {
            if ($feature instanceof Feature) {
                $this->features[$key] = $feature;
            } else {
                $this->features[$key] = new Feature($feature);
            }
        }
        return $this;
    }

    /**
     * @param array<string,int> $forcedVariations
     * @return $this
     */
    public function withForcedVariations(array $forcedVariations): self
    {
        $this->forcedVariations = $forcedVariations;
        return $this;
    }

    /**
     * @param array<string, FeatureResult<mixed>> $forcedFeatures
     * @return $this
     */
    public function withForcedFeatures(array $forcedFeatures): self
    {
        $this->forcedFeatures = $forcedFeatures;
        return $this;
    }

    /**
     * @return $this
     */
    public function withUrl(string $url): self
    {
        $this->url = $url;
        return $this;
    }

    /**
     * @return $this
     */
    public function withLogger(LoggerInterface $logger = null): self
    {
        $this->logger = $logger;
        return $this;
    }

    public function setLogger(LoggerInterface $logger = null): void
    {
        $this->logger = $logger;
    }

    /**
     * @return $this
     */
    public function withHttpClient(ClientInterface $client, ?RequestFactoryInterface $requestFactory = null): self
    {
        $this->httpClient = $client;
        $this->requestFactory = $requestFactory;
        return $this;
    }

    /**
     * @return $this
     */
    public function withCache(CacheInterface $cache, int $ttl = null): self
    {
        $this->cache = $cache;
        if ($ttl !== null) {
            $this->cacheTTL = $ttl;
        }
        return $this;
    }

    /**
     * @return array<string,mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * @return array<string,Feature<mixed>>
     */
    public function getFeatures(): array
    {
        return $this->features;
    }

    /**
     * @return array<string,int>
     */
    public function getForcedVariations(): array
    {
        return $this->forcedVariations;
    }

    /**
     * @return array<string, FeatureResult<mixed>>
     */
    public function getForcedFeatured(): array
    {
        return $this->forcedFeatures;
    }

    /**
     * @return string
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * @return callable|null
     */
    public function getTrackingCallback()
    {
        return $this->trackingCallback;
    }

    /**
     * @return ViewedExperiment[]
     */
    public function getViewedExperiments(): array
    {
        return array_values($this->tracks);
    }

    public function isOn(string $key): bool
    {
        return $this->getFeature($key)->on;
    }

    public function isOff(string $key): bool
    {
        return $this->getFeature($key)->off;
    }

    /**
     * @template T
     * @param string $key
     * @param T $default
     * @return T
     */
    public function getValue(string $key, $default)
    {
        $res = $this->getFeature($key);
        return $res->value ?? $default;
    }

    /**
     * @template T
     * @param string $key
     * @return FeatureResult<T>|FeatureResult<null>
     */
    public function getFeature(string $key): FeatureResult
    {
        if (!array_key_exists($key, $this->features)) {
            $this->log(LogLevel::DEBUG, "Unknown feature - $key");
            return new FeatureResult(null, "unknownFeature");
        }
        $this->log(LogLevel::DEBUG, "Evaluating feature - $key");
        $feature = $this->features[$key];

        // Check if the feature is forced
        if (array_key_exists($key, $this->forcedFeatures)) {
            $this->log(LogLevel::DEBUG, "Feature Forced - $key", [
                "feature" => $key,
                "result" => $this->forcedFeatures[$key],
            ]);
            return $this->forcedFeatures[$key];
        }

        if ($feature->rules) {
            foreach ($feature->rules as $rule) {
                if ($rule->condition) {
                    if (!Condition::evalCondition($this->attributes, $rule->condition)) {
                        $this->log(LogLevel::DEBUG, "Skip rule because of targeting condition", [
                            "feature" => $key,
                            "condition" => $rule->condition
                        ]);
                        continue;
                    }
                }
                if ($rule->filters) {
                    if ($this->isFilteredOut($rule->filters)) {
                        $this->log(LogLevel::DEBUG, "Skip rule because of filtering (e.g. namespace)", [
                            "feature" => $key,
                            "filters" => $rule->filters
                        ]);
                        continue;
                    }
                }

                // If forced
                if (isset($rule->force)) {
                    if (!$this->isIncludedInRollout(
                        $rule->seed ?? $key,
                        $rule->hashAttribute,
                        $rule->range,
                        $rule->coverage,
                        $rule->hashVersion
                    )) {
                        $this->log(LogLevel::DEBUG, "Skip rule because of rollout percent", [
                            "feature" => $key
                        ]);
                        continue;
                    }
                    $this->log(LogLevel::DEBUG, "Force feature value from rule", [
                        "feature" => $key,
                        "value" => $rule->force
                    ]);
                    return new FeatureResult($rule->force, "force");
                }

                // Convert to experiment
                $exp = $rule->toExperiment($key);
                if (!$exp) {
                    $this->log(LogLevel::DEBUG, "Skip rule because could not convert to an experiment", [
                        "feature" => $key,
                        "filters" => $rule->filters
                    ]);
                    continue;
                }

                $result = $this->runExperiment($exp, $key);
                if (!$result->inExperiment) {
                    $this->log(LogLevel::DEBUG, "Skip rule because user not included in experiment", [
                        "feature" => $key
                    ]);
                    continue;
                }

                if ($result->passthrough) {
                    $this->log(LogLevel::DEBUG, "User put into holdout experiment, continue to next rule", [
                        "feature" => $key
                    ]);
                    continue;
                }
                $this->log(LogLevel::DEBUG, "Use feature value from experiment", [
                    "feature" => $key,
                    "value" => $result->value
                ]);
                return new FeatureResult($result->value, "experiment", $exp, $result);
            }
        }
        return new FeatureResult($feature->defaultValue ?? null, "defaultValue");
    }

    /**
     * @template T
     * @param InlineExperiment<T> $exp
     * @return ExperimentResult<T>
     */
    public function runInlineExperiment(InlineExperiment $exp): ExperimentResult
    {
        return $this->runExperiment($exp, null);
    }

    /**
     * @template T
     * @param InlineExperiment<T> $exp
     * @param string|null $featureId
     * @return ExperimentResult<T>
     */
    private function runExperiment(InlineExperiment $exp, string $featureId = null): ExperimentResult
    {
        $this->log(LogLevel::DEBUG, "Attempting to run experiment - " . $exp->key);

        // 1. Too few variations
        if (count($exp->variations) < 2) {
            $this->log(LogLevel::DEBUG, "Skip experiment because there aren't enough variations", [
                "experiment" => $exp->key
            ]);
            return new ExperimentResult($exp, "", -1, false, $featureId);
        }

        // 2. Growthbook disabled
        if (!$this->enabled) {
            $this->log(LogLevel::DEBUG, "Skip experiment because the Growthbook instance is disabled", [
                "experiment" => $exp->key
            ]);
            return new ExperimentResult($exp, "", -1, false, $featureId);
        }

        $hashAttribute = $exp->hashAttribute ?? "id";
        $hashValue = $this->getHashValue($hashAttribute);

        // 3. Forced via querystring
        if ($this->url) {
            $qsOverride = static::getQueryStringOverride($exp->key, $this->url, count($exp->variations));
            if ($qsOverride !== null) {
                $this->log(LogLevel::DEBUG, "Force variation from querystring", [
                    "experiment" => $exp->key,
                    "variation" => $qsOverride
                ]);
                return new ExperimentResult($exp, $hashValue, $qsOverride, false, $featureId);
            }
        }

        // 4. Forced via forcedVariations
        if (array_key_exists($exp->key, $this->forcedVariations)) {
            $this->log(LogLevel::DEBUG, "Force variation from context", [
                "experiment" => $exp->key,
                "variation" => $this->forcedVariations[$exp->key]
            ]);
            return new ExperimentResult($exp, $hashValue, $this->forcedVariations[$exp->key], false, $featureId);
        }

        // 5. Experiment is not active
        if (!$exp->active) {
            $this->log(LogLevel::DEBUG, "Skip experiment because it is inactive", [
                "experiment" => $exp->key
            ]);
            return new ExperimentResult($exp, $hashValue, -1, false, $featureId);
        }

        // 6. Hash value is empty
        if (!$hashValue) {
            $this->log(LogLevel::DEBUG, "Skip experiment because of empty attribute - $hashAttribute", [
                "experiment" => $exp->key
            ]);
            return new ExperimentResult($exp, "", -1, false, $featureId);
        }

        // 7. Filtered out
        if ($exp->filters) {
            if ($this->isFilteredOut($exp->filters)) {
                $this->log(LogLevel::DEBUG, "Skip experiment because of filters (e.g. namespace)", [
                    "experiment" => $exp->key
                ]);
                return new ExperimentResult($exp, $hashValue, -1, false, $featureId);
            }
        } elseif ($exp->namespace && !static::inNamespace($hashValue, $exp->namespace)) {
            $this->log(LogLevel::DEBUG, "Skip experiment because not in namespace", [
                "experiment" => $exp->key
            ]);
            return new ExperimentResult($exp, $hashValue, -1, false, $featureId);
        }

        // 8. Condition fails
        if ($exp->condition && !Condition::evalCondition($this->attributes, $exp->condition)) {
            $this->log(LogLevel::DEBUG, "Skip experiment because of targeting conditions", [
                "experiment" => $exp->key
            ]);
            return new ExperimentResult($exp, $hashValue, -1, false, $featureId);
        }

        // 9. Calculate bucket ranges
        $ranges = $exp->ranges ?? static::getBucketRanges(count($exp->variations), $exp->coverage, $exp->weights ?? []);
        $n = static::hash(
            $exp->seed ?? $exp->key,
            $hashValue,
            $exp->hashVersion ?? 1
        );
        if ($n === null) {
            $this->log(LogLevel::DEBUG, "Skip experiment because of invalid hash version", [
                "experiment" => $exp->key
            ]);
            return new ExperimentResult($exp, $hashValue, -1, false, $featureId);
        }
        $assigned = static::chooseVariation($n, $ranges);

        // 10. Not assigned
        if ($assigned === -1) {
            $this->log(LogLevel::DEBUG, "Skip experiment because user is not included in a variation", [
                "experiment" => $exp->key
            ]);
            return new ExperimentResult($exp, $hashValue, -1, false, $featureId);
        }

        // 11. Force variation in experiment config
        if ($exp->force !== null) {
            $this->log(LogLevel::DEBUG, "Force variation from the experiment config", [
                "experiment" => $exp->key,
                "variation" => $exp->force
            ]);
            return new ExperimentResult($exp, $hashValue, $exp->force, false, $featureId);
        }

        // 12. QA mode
        if ($this->qaMode) {
            $this->log(LogLevel::DEBUG, "Skip experiment because Growthbook instance is in QA Mode", [
                "experiment" => $exp->key
            ]);
            return new ExperimentResult($exp, $hashValue, -1, false, $featureId);
        }

        // 13. Build the result object
        $result = new ExperimentResult($exp, $hashValue, $assigned, true, $featureId, $n);

        // 14. Fire tracking callback
        $this->tracks[$exp->key] = new ViewedExperiment($exp, $result);
        if ($this->trackingCallback) {
            try {
                call_user_func($this->trackingCallback, $exp, $result);
            } catch (Throwable $e) {
                if ($this->logger) {
                    $this->log(LogLevel::ERROR, "Error calling the trackingCallback function", [
                        "experiment" => $exp->key,
                        "error" => $e
                    ]);
                } else {
                    throw $e;
                }
            }
        }

        // 15. Return the result
        $this->log(LogLevel::DEBUG, "Assigned user a variation", [
            "experiment" => $exp->key,
            "variation" => $assigned
        ]);
        return $result;
    }

    /**
     * @param string $level
     * @param string $message
     * @param array<mixed> $context
     */
    public function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger) {
            $this->logger->log($level, $message, $context);
        }
    }

    /**
     * Hash function with 2 different versions:
     * v2: no known bias (fnv1a32->hexdec->mod)
     * v1: older approach with slight bias
     *
     * @return float|null
     */
    public static function hash(string $seed, string $value, int $version): ?float
    {
        // Version 2 hashing
        if ($version === 2) {
            $n = hexdec(hash("fnv1a32", hexdec(hash("fnv1a32", $seed . $value)) . ""));
            return ($n % 10000) / 10000;
        }
        // Version 1 hashing
        if ($version === 1) {
            $n = hexdec(hash("fnv1a32", $value . $seed));
            return ($n % 1000) / 1000;
        }
        return null;
    }

    /**
     * Helper to check if a number is within a range
     *
     * @param float $n
     * @param array{0:float,1:float} $range
     * @return bool
     */
    public static function inRange(float $n, array $range): bool
    {
        return $n >= $range[0] && $n < $range[1];
    }

    /**
     * Check if user is included in a rollout
     *
     * @param string      $seed
     * @param string|null $hashAttribute
     * @param array{0:float,1:float}|null $range
     * @param float|null  $coverage
     * @param int|null    $hashVersion
     * @return bool
     */
    private function isIncludedInRollout(
        string $seed,
        ?string $hashAttribute,
        ?array $range,
        ?float $coverage,
        ?int $hashVersion
    ): bool {
        if ($coverage === null && $range === null) {
            return true;
        }

        $hashValue = strval($this->attributes[$hashAttribute ?? "id"] ?? "");
        if ($hashValue === "") {
            return false;
        }

        $n = self::hash($seed, $hashValue, $hashVersion ?? 1);
        if ($n === null) {
            return false;
        }
        if ($range) {
            return self::inRange($n, $range);
        } elseif ($coverage !== null) {
            return $n <= $coverage;
        }
        return true;
    }

    private function getHashValue(string $hashAttribute): string
    {
        return strval($this->attributes[$hashAttribute] ?? "");
    }

    /**
     * @param array<array{
     *   seed:string,
     *   ranges:array<array{0:float,1:float}>,
     *   hashVersion?:int,
     *   attribute?:string
     * }> $filters
     * @return bool
     */
    private function isFilteredOut(array $filters): bool
    {
        foreach ($filters as $filter) {
            $hashValue = $this->getHashValue($filter["attribute"] ?? "id");
            if ($hashValue === "") {
                // If there's no attribute to hash, can't filter user out,
                // so the user is effectively included (return false).
                return false;
            }

            $n = self::hash($filter["seed"] ?? "", $hashValue, $filter["hashVersion"] ?? 2);
            if ($n === null) {
                return false;
            }

            $filtered = false;
            foreach ($filter["ranges"] as $range) {
                if (self::inRange($n, $range)) {
                    $filtered = true;
                    break;
                }
            }
            if (!$filtered) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if user is in a namespace
     *
     * @param string $userId
     * @param array{0:string,1:float,2:float} $namespace
     * @return bool
     */
    public static function inNamespace(string $userId, array $namespace): bool
    {
        // @phpstan-ignore-next-line
        if (count($namespace) < 3) {
            return false;
        }
        $n = static::hash("__" . $namespace[0], $userId, 1);
        if ($n === null) {
            return false;
        }
        return $n >= $namespace[1] && $n < $namespace[2];
    }

    /**
     * Helper to get an array of equal weights
     *
     * @return float[]
     */
    public static function getEqualWeights(int $numVariations): array
    {
        $weights = [];
        for ($i = 0; $i < $numVariations; $i++) {
            $weights[] = 1 / $numVariations;
        }
        return $weights;
    }

    /**
     * Build bucket ranges for each variation
     *
     * @param int          $numVariations
     * @param float        $coverage
     * @param float[]|null $weights
     * @return array<array{0:float,1:float}>
     */
    public static function getBucketRanges(int $numVariations, float $coverage, ?array $weights = null): array
    {
        $coverage = max(0, min(1, $coverage));
        if (!$weights || count($weights) !== $numVariations) {
            $weights = static::getEqualWeights($numVariations);
        }
        $sum = array_sum($weights);
        if ($sum < 0.99 || $sum > 1.01) {
            $weights = static::getEqualWeights($numVariations);
        }

        $cumulative = 0;
        $ranges = [];
        foreach ($weights as $weight) {
            $start = $cumulative;
            $cumulative += $weight;
            $ranges[] = [$start, $start + $coverage * $weight];
        }
        return $ranges;
    }

    /**
     * Determine which variation a user is bucketed into
     *
     * @param float                             $n
     * @param array<array{0:float,1:float}>     $ranges
     * @return int
     */
    public static function chooseVariation(float $n, array $ranges): int
    {
        foreach ($ranges as $i => $range) {
            if (self::inRange($n, $range)) {
                return (int)$i;
            }
        }
        return -1;
    }

    /**
     * For overriding experiment variations via query string
     *
     * @param string $id
     * @param string $url
     * @param int    $numVariations
     * @return int|null
     */
    public static function getQueryStringOverride(string $id, string $url, int $numVariations): ?int
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (!$query) {
            return null;
        }
        parse_str($query, $params);
        if (!isset($params[$id]) || !is_numeric($params[$id])) {
            return null;
        }
        $variation = (int)$params[$id];
        if ($variation < 0 || $variation >= $numVariations) {
            return null;
        }
        return $variation;
    }

    /**
     * Decrypt features from an encrypted string
     *
     * @param string $encryptedString
     * @return string
     */
    public function decrypt(string $encryptedString): string
    {
        if (!$this->decryptionKey) {
            throw new Error("Must specify a decryption key in order to use encrypted feature flags");
        }
        $parts = explode(".", $encryptedString, 2);
        $iv = base64_decode($parts[0]);
        $cipherText = $parts[1];

        $password = base64_decode($this->decryptionKey);

        $decrypted = openssl_decrypt($cipherText, "aes-128-cbc", $password, 0, $iv);
        if (!$decrypted) {
            throw new Error("Failed to decrypt");
        }
        return $decrypted;
    }

    /**
     * Load features (async or sync)
     *
     * @param array{
     *   async?: bool,
     *   skipCache?: bool,
     *   staleWhileRevalidate?: bool,
     *   timeout?: int
     * } $options
     * @return PromiseInterface
     */
    public function loadFeatures(
        string $clientKey,
        string $apiHost = "",
        string $decryptionKey = "",
        array  $options = []
    ): PromiseInterface {
        $this->clientKey = $clientKey;
        $this->apiHost = $apiHost;
        $this->decryptionKey = $decryptionKey;

        if (!$this->clientKey) {
            throw new Exception("Must specify a clientKey before loading features.");
        }
        if (!$this->httpClient) {
            throw new Exception("Must set an HTTP Client before loading features.");
        }
        if (!$this->requestFactory) {
            throw new Exception("Must set an HTTP Request Factory before loading features");
        }

        $isAsync = $options['async'] ?? false;
        if ($isAsync) {
            $this->promise = $this->loadFeaturesAsyncInternal($options);
            return $this->promise;
        }

        // Synchronous fetch
        $this->loadFeaturesSyncInternal($options);
        // We set promise to a resolved promise to avoid null
        $this->promise = resolve(null);
        return $this->promise;
    }

    /**
     * Synchronous version of fetching features
     *
     * @param array{
     *   async?: bool,
     *   skipCache?: bool,
     *   staleWhileRevalidate?: bool,
     *   timeout?: int
     * } $options
     */
    private function loadFeaturesSyncInternal(array $options): void
    {
        $timeout = $options['timeout'] ?? null;
        $skipCache = $options['skipCache'] ?? false;
        $staleWhileRevalidate = $options['staleWhileRevalidate'] ?? true;

        $url = rtrim($this->apiHost ?: self::DEFAULT_API_HOST, "/")
            . "/api/features/" . $this->clientKey;
        $cacheKey = md5($url);
        $now = time();

        // If we have cache & skipCache is false, attempt to load from cache
        if ($this->cache && !$skipCache) {
            $cachedData = $this->cache->get($cacheKey);
            $cachedTime = $this->cache->get($cacheKey . '_time');
            if ($cachedData) {
                $features = json_decode($cachedData, true);
                if (is_array($features)) {
                    $age = $cachedTime ? ($now - (int)$cachedTime) : PHP_INT_MAX;
                    if ($age < $this->cacheTTL) {
                        // Cache is fresh
                        $this->log(LogLevel::INFO, "Load features from cache (sync)", [
                            "url" => $url,
                            "numFeatures" => count($features),
                        ]);
                        $this->withFeatures($features);
                        return;
                    } else {
                        // Cache is stale
                        if ($staleWhileRevalidate) {
                            $this->log(LogLevel::INFO, "Load stale features from cache, then revalidate (sync)", [
                                "url" => $url,
                                "numFeatures" => count($features),
                            ]);
                            $this->withFeatures($features);

                            // Fetch fresh data
                            $fresh = $this->fetchFeaturesSync($url);
                            if ($fresh !== null) {
                                $this->storeFeaturesInCache($fresh, $cacheKey);
                            }
                            return;
                        } else {
                            $this->log(LogLevel::INFO, "Cache stale, fetch new features (sync)", ["url" => $url]);
                        }
                    }
                }
            }
        }

        // If no cache or no valid data found, fetch fresh from server
        $fresh = $this->fetchFeaturesSync($url);
        if ($fresh !== null) {
            $this->storeFeaturesInCache($fresh, $cacheKey);
        }
    }

    /**
     * Asynchronous version of fetching features
     *
     * @param array{
     *   async?: bool,
     *   skipCache?: bool,
     *   staleWhileRevalidate?: bool,
     *   timeout?: int
     * } $options
     * @return PromiseInterface
     */
    private function loadFeaturesAsyncInternal(array $options): PromiseInterface
    {
        $timeout = $options['timeout'] ?? null;
        $skipCache = $options['skipCache'] ?? false;
        $staleWhileRevalidate = $options['staleWhileRevalidate'] ?? true;

        $url = rtrim($this->apiHost ?: self::DEFAULT_API_HOST, "/") . "/api/features/" . $this->clientKey;
        $cacheKey = md5($url);
        $now = time();

        // Attempt to load from cache if available
        if ($this->cache && !$skipCache) {
            $cachedData = $this->cache->get($cacheKey);
            $cachedTime = $this->cache->get($cacheKey . '_time');
            if ($cachedData) {
                $features = json_decode($cachedData, true);
                if (is_array($features)) {
                    $age = $cachedTime ? ($now - (int)$cachedTime) : PHP_INT_MAX;
                    if ($age < $this->cacheTTL) {
                        $this->log(LogLevel::INFO, "Load features from cache (async)", [
                            "url" => $url,
                            "numFeatures" => count($features),
                        ]);
                        $this->withFeatures($features);
                        return resolve($features);
                    }
                    // If stale is allowed, serve stale & revalidate
                    if ($staleWhileRevalidate) {
                        $this->log(LogLevel::INFO, "Load stale features from cache, then revalidate (async)", [
                            "url" => $url,
                            "numFeatures" => count($features),
                        ]);
                        $this->withFeatures($features);

                        // Return a promise that tries to fetch fresh data
                        /** @var PromiseInterface $updatePromise */
                        $updatePromise = $this->asyncFetchFeatures($url, $timeout)
                            ->then(function (array $fresh) use ($cacheKey) {
                                $this->storeFeaturesInCache($fresh, $cacheKey);
                                return $fresh;
                            })
                            ->then(
                                null,
                                function (Throwable $e) {
                                    $this->log(LogLevel::WARNING, "Revalidation failed (async)", [
                                        "error" => $e->getMessage()
                                    ]);
                                    return $this->features;
                                }
                            );
                        return $updatePromise;
                    }

                    $this->log(LogLevel::INFO, "Cache stale, fetch new features (async)", ["url" => $url]);
                }
            }
        }

        // No valid cache or skipCache=true => fetch from server
        /** @var PromiseInterface $promise */
        $promise = $this->asyncFetchFeatures($url, $timeout)
            ->then(function (array $fresh) use ($cacheKey) {
                $this->storeFeaturesInCache($fresh, $cacheKey);
                return $fresh;
            });
        return $promise;
    }

    /**
     * Fetch features asynchronously with React\Http\Browser and optional timeout.
     *
     * @param string   $url
     * @param int|null $timeout
     * @return PromiseInterface
     */
    private function asyncFetchFeatures(string $url, ?int $timeout): PromiseInterface
    {
        // Browser->get() returns a PromiseInterface<ResponseInterface>
        /** @var PromiseInterface$request */
        $request = $this->asyncClient->get($url);

        // Wrap with timeout if needed
        if ($timeout !== null && $timeout > 0) {
            /** @var PromiseInterface$request */
            $request = timeout($request, $timeout, $this->loop);
        }

        return $request->then(function (ResponseInterface $response) use ($url) {
            $body = (string)$response->getBody();
            $parsed = json_decode($body, true);
            if (!$parsed || !is_array($parsed) || !isset($parsed['features'])) {
                $this->log(LogLevel::WARNING, "Could not load features (async)", [
                    "url" => $url,
                    "responseBody" => $body
                ]);
                throw new RuntimeException("Invalid features response");
            }
            $features = isset($parsed["encryptedFeatures"])
                ? json_decode($this->decrypt($parsed["encryptedFeatures"]), true)
                : $parsed["features"];

            $this->log(LogLevel::INFO, "Async load features from URL", [
                "url" => $url,
                "numFeatures" => count($features)
            ]);
            $this->withFeatures($features);

            return $features;
        });
    }

    /**
     * Fetch features synchronously (PSR-18) or throw if Browser is used.
     *
     * @param string $url
     * @return array<string,mixed>|null
     */
    private function fetchFeaturesSync(string $url): ?array
    {
        if (!$this->requestFactory) {
            throw new RuntimeException("RequestFactory is null");
        }
        if (!$this->httpClient) {
            throw new RuntimeException("HttpClient is null");
        }

        $req = $this->requestFactory->createRequest('GET', $url);

        // If it's a PSR-18 client, we can do sendRequest()
        if ($this->httpClient instanceof ClientInterface) {
            $res = $this->httpClient->sendRequest($req);
        } elseif ($this->httpClient instanceof Browser) {
            // If it's React Browser, synchronous usage is not typical
            throw new RuntimeException("Synchronous requests are not supported when using React\\Http\\Browser");
        } else {
            throw new RuntimeException("Unsupported HTTP client");
        }

        $body = (string)$res->getBody();
        $parsed = json_decode($body, true);

        if (!$parsed || !is_array($parsed) || !isset($parsed['features'])) {
            $this->log(LogLevel::WARNING, "Could not load features (sync)", [
                "url" => $url,
                "responseBody" => $body,
            ]);
            return null;
        }

        $features = isset($parsed["encryptedFeatures"])
            ? json_decode($this->decrypt($parsed["encryptedFeatures"]), true)
            : $parsed["features"];

        $this->log(LogLevel::INFO, "Load features from URL (sync)", [
            "url" => $url,
            "numFeatures" => count($features),
        ]);
        $this->withFeatures($features);
        return $features;
    }

    /**
     * Store fetched features in cache
     *
     * @param array<string,mixed> $features
     * @param string              $cacheKey
     * @return bool
     */
    private function storeFeaturesInCache(array $features, string $cacheKey): bool
    {
        if ($this->cache) {
            $success1 = $this->cache->set($cacheKey, json_encode($features), $this->cacheTTL);
            $success2 = $this->cache->set($cacheKey . '_time', time(), $this->cacheTTL);
            $this->log(LogLevel::INFO, "Cache features", [
                "numFeatures" => count($features),
                "ttl" => $this->cacheTTL
            ]);
            return $success1 && $success2;
        }
        return true;
    }
}
