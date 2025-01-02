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
use function React\Promise\Timer\timeout;

class Growthbook implements LoggerAwareInterface
{
    private const DEFAULT_API_HOST = "https://cdn.growthbook.io";

    /** @var bool */
    public $enabled = true;
    /** @var null|LoggerInterface */
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

    /**
     * @var null|CacheInterface
     */
    private $cache = null;
    /**
     * @var integer
     */
    private $cacheTTL = 60;

    /**
     * @var null|ClientInterface
     */
    private $httpClient;

    /**
     * @var null|RequestFactoryInterface
     */
    public $requestFactory;

    /** @var string */
    private $apiHost = "";
    /** @var string */
    private $clientKey = "";
    /** @var string */
    private $decryptionKey = "";

    /** @var array<string,ViewedExperiment> */
    private $tracks = [];
    /** @var string */
    /**
     * @var LoopInterface
     */
    private $loop;

    /**
     * @var Browser
     */
    private $asyncClient;

    /**
     * @var PromiseInterface|null
     */
    public $promise = null;

    public static function create(): Growthbook
    {
        return new Growthbook();
    }

    /**
     * @param array{enabled?:bool,logger?:LoggerInterface,url?:string,attributes?:array<string,mixed>,features?:array<string,mixed>,forcedVariations?:array<string,int>,qaMode?:bool,trackingCallback?:callable,cache?:CacheInterface,httpClient?:ClientInterface,requestFactory?:RequestFactoryInterface,decryptionKey?:string,forcedFeatures?:array<string, FeatureResult<mixed>>, loop?:LoopInterface} $options
     */
    public function __construct(array $options = [])
    {
        // Warn if any unknown options are passed
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
        $this->loop = $options['loop'] ?? LoopFactory::create();
        $this->asyncClient = new Browser($this->loop);

        $unknownOptions = array_diff(array_keys($options), $knownOptions);
        if (count($unknownOptions)) {
            trigger_error('Unknown Config options: ' . implode(", ", $unknownOptions), E_USER_NOTICE);
        }

        $this->enabled = $options["enabled"] ?? true;
        $this->logger = $options["logger"] ?? null;
        $this->url = $options["url"] ?? $_SERVER['REQUEST_URI'] ?? "";
        $this->forcedVariations = $options["forcedVariations"] ?? [];
        $this->qaMode = $options["qaMode"] ?? false;
        $this->trackingCallback = $options["trackingCallback"] ?? null;

        $this->decryptionKey = $options["decryptionKey"] ?? "";

        $this->cache = $options["cache"] ?? null;
        try {
            $this->httpClient = $options["httpClient"] ?? Psr18ClientDiscovery::find();
            $this->requestFactory = $options["requestFactory"] ?? Psr17FactoryDiscovery::findRequestFactory();
        } catch (Throwable $e) {
            // Ignore errors from discovery
        }

        if (array_key_exists("forcedFeatures", $options)) {
            $this->withForcedFeatures($options['forcedFeatures']);
        }

        if (array_key_exists("features", $options)) {
            $this->withFeatures(($options["features"]));
        }
        if (array_key_exists("attributes", $options)) {
            $this->withAttributes(($options["attributes"]));
        }

    }

    /**
     * @param array<string,mixed> $attributes
     * @return Growthbook
     */
    public function withAttributes(array $attributes): Growthbook
    {
        $this->attributes = $attributes;
        return $this;
    }

    /**
     * @param callable|null $trackingCallback
     * @return Growthbook
     */
    public function withTrackingCallback($trackingCallback): Growthbook
    {
        $this->trackingCallback = $trackingCallback;
        return $this;
    }

    /**
     * @param array<string,Feature<mixed>|mixed> $features
     * @return Growthbook
     */
    public function withFeatures(array $features): Growthbook
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
     * @return Growthbook
     */
    public function withForcedVariations(array $forcedVariations): Growthbook
    {
        $this->forcedVariations = $forcedVariations;
        return $this;
    }

    /**
     * @param array<string, FeatureResult<mixed>> $forcedFeatures
     * @return Growthbook
     */
    public function withForcedFeatures(array $forcedFeatures)
    {
        $this->forcedFeatures = $forcedFeatures;
        return $this;
    }

    /**
     * @param string $url
     * @return Growthbook
     */
    public function withUrl(string $url): Growthbook
    {
        $this->url = $url;
        return $this;
    }

    public function withLogger(LoggerInterface $logger = null): Growthbook
    {
        $this->logger = $logger;
        return $this;
    }

    public function setLogger(LoggerInterface $logger = null): void
    {
        $this->logger = $logger;
    }

    public function withHttpClient(ClientInterface $client, ?RequestFactoryInterface $requestFactory = null): Growthbook
    {
        $this->httpClient = $client;
        $this->requestFactory = $requestFactory;
        return $this;
    }

    public function withCache(CacheInterface $cache, int $ttl = null): Growthbook
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
    public function getForcedFeatured()
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
                    if (self::isFilteredOut($rule->filters)) {
                        $this->log(LogLevel::DEBUG, "Skip rule because of filtering (e.g. namespace)", [
                            "feature" => $key,
                            "filters" => $rule->filters
                        ]);
                        continue;
                    }
                }

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
            return new ExperimentResult($exp, $hashValue, -1, false, $featureId);
        }

        // 7. Filtered out / not in namespace
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

        // 11. Forced variation
        if ($exp->force !== null) {
            $this->log(LogLevel::DEBUG, "Force variation from the experiment config", [
                "experiment" => $exp->key,
                "variation" => $exp->force
            ]);
            return new ExperimentResult($exp, $hashValue, $exp->force, false, $featureId);
        }

        // 12. QA mode
        if ($this->qaMode) {
            $this->log(LogLevel::DEBUG, "Skip experiment because Growthbook instance in QA Mode", [
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
     * @param mixed $context
     */
    public function log(string $level, string $message, $context = []): void
    {
        if ($this->logger) {
            $this->logger->log($level, $message, $context);
        }
    }

    public static function hash(string $seed, string $value, int $version): ?float
    {
        // New hashing algorithm
        if ($version === 2) {
            $n = hexdec(hash("fnv1a32", hexdec(hash("fnv1a32", $seed . $value)) . ""));
            return ($n % 10000) / 10000;
        } // Original hashing algorithm (with a bias flaw)
        elseif ($version === 1) {
            $n = hexdec(hash("fnv1a32", $value . $seed));
            return ($n % 1000) / 1000;
        }

        return null;
    }

    /**
     * @param float $n
     * @param array{0:float,1:float} $range
     * @return bool
     */
    public static function inRange(float $n, array $range): bool
    {
        return $n >= $range[0] && $n < $range[1];
    }

    /**
     * @param string $seed
     * @param string|null $hashAttribute
     * @param array{0:float,1:float}|null $range
     * @param float|null $coverage
     * @param int|null $hashVersion
     * @return bool
     */
    private function isIncludedInRollout(string $seed, string $hashAttribute = null, array $range = null, float $coverage = null, int $hashVersion = null): bool
    {
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
        return strval($this->attributes[$hashAttribute ?? "id"] ?? "");
    }

    /**
     * @param array{seed:string,ranges:array{0:float,1:float}[],hashVersion?:int,attribute?:string}[] $filters
     * @return bool
     */
    private function isFilteredOut(array $filters): bool
    {
        foreach ($filters as $filter) {
            $hashValue = $this->getHashValue($filter["attribute"] ?? "id");
            if ($hashValue === "") {
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
     * @param int $numVariations
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
     * @param int $numVariations
     * @param float $coverage
     * @param null|(float[]) $weights
     * @return array{0:float,1:float}[]
     */
    public static function getBucketRanges(int $numVariations, float $coverage, array $weights = null): array
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
     * @param float $n
     * @param array{0:float,1:float}[] $ranges
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
     * @param string $id
     * @param string $url
     * @param int $numVariations
     * @return int|null
     */
    public static function getQueryStringOverride(string $id, string $url, int $numVariations): ?int
    {
        // Extract the querystring from the url
        /** @var string|false */
        $query = parse_url($url, PHP_URL_QUERY);
        if (!$query) {
            return null;
        }

        // Parse the query string and check if $id is there
        parse_str($query, $params);
        if (!isset($params[$id]) || !is_numeric($params[$id])) {
            return null;
        }

        // Make sure it's a valid variation integer
        $variation = (int)$params[$id];
        if ($variation < 0 || $variation >= $numVariations) {
            return null;
        }

        return $variation;
    }

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
     * @param array<string,mixed> $options {
     *    @type bool $async
     *    @type bool $skipCache
     *    @type bool $staleWhileRevalidate
     *    @type int  $timeout
     * }
     */
    public function loadFeatures(
        string $clientKey,
        string $apiHost = "",
        string $decryptionKey = "",
        array  $options = []
    ): void {
        $this->clientKey = $clientKey;
        $this->apiHost = $apiHost;
        $this->decryptionKey = $decryptionKey;

        $isAsync = $options['async'] ?? false;

        if (!$this->clientKey) {
            throw new Exception("Must specify a clientKey before loading features.");
        }
        if (!$this->httpClient) {
            throw new Exception("Must set an HTTP Client before loading features.");
        }
        if (!$this->requestFactory) {
            throw new Exception("Must set an HTTP Request Factory before loading features");
        }

        if ($isAsync) {
            $this->promise = $this->loadFeaturesAsyncInternal($options);
            return;
        }

        $this->loadFeaturesSyncInternal($options);
    }
    /**
     * @param array<string,mixed> $options {
     *    @type bool $async
     *    @type bool $skipCache
     *    @type bool $staleWhileRevalidate
     *    @type int  $timeout
     * }
     */
    private function loadFeaturesSyncInternal(array $options): void
    {
        $timeout = $options['timeout'] ?? null;
        $skipCache = $options['skipCache'] ?? false;
        $staleWhileRevalidate = $options['staleWhileRevalidate'] ?? true;

        $url = rtrim($this->apiHost ?: self::DEFAULT_API_HOST, "/") . "/api/features/" . $this->clientKey;
        $cacheKey = md5($url);
        $now = time();

        if ($this->cache && !$skipCache) {
            $cachedData = $this->cache->get($cacheKey);
            $cachedTime = $this->cache->get($cacheKey . '_time');
            if ($cachedData) {
                $features = json_decode($cachedData, true);
                if (is_array($features)) {
                    $age = $cachedTime ? ($now - (int)$cachedTime) : PHP_INT_MAX;
                    if ($age < $this->cacheTTL) {
                        $this->log(LogLevel::INFO, "Load features from cache (sync)", [
                            "url" => $url,
                            "numFeatures" => count($features),
                        ]);
                        $this->withFeatures($features);
                        return;
                    } else {
                        if ($staleWhileRevalidate) {
                            $this->log(LogLevel::INFO, "Load stale features from cache, then revalidate (sync)", [
                                "url" => $url,
                                "numFeatures" => count($features),
                            ]);
                            $this->withFeatures($features);

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
        $fresh = $this->fetchFeaturesSync($url);
        if ($fresh !== null) {
            $this->storeFeaturesInCache($fresh, $cacheKey);
        }
    }
    /**
     * @param array<string,mixed> $options {
     *    @type bool $async
     *    @type bool $skipCache
     *    @type bool $staleWhileRevalidate
     *    @type int  $timeout
     * }
     */
    private function loadFeaturesAsyncInternal(array $options): PromiseInterface
    {
        $timeout = $options['timeout'] ?? null;
        $skipCache = $options['skipCache'] ?? false;
        $staleWhileRevalidate = $options['staleWhileRevalidate'] ?? true;

        $url = rtrim($this->apiHost ?: self::DEFAULT_API_HOST, "/") . "/api/features/" . $this->clientKey;
        $cacheKey = md5($url);
        $now = time();

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
                        return \React\Promise\resolve($features);
                    } else {
                        if ($staleWhileRevalidate) {
                            $this->log(LogLevel::INFO, "Load stale features from cache, then revalidate (async)", [
                                "url" => $url,
                                "numFeatures" => count($features),
                            ]);
                            $this->withFeatures($features);

                            /** @var PromiseInterface<array<string,mixed>> $updatePromise */
                            $updatePromise = $this->asyncFetchFeatures($url, $timeout)
                                ->then(function ($fresh) use ($cacheKey) {
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
                        } else {
                            $this->log(LogLevel::INFO, "Cache stale, fetch new features (async)", ["url" => $url]);
                        }
                    }
                }
            }
        }

        /** @var PromiseInterface<array<string,mixed>> $promise */
        $promise = $this->asyncFetchFeatures($url, $timeout)
            ->then(function ($fresh) use ($cacheKey) {
                $this->storeFeaturesInCache($fresh, $cacheKey);
                return $fresh;
            });

        return $promise;
    }

    /**
     * @param string $url
     * @param int|null $timeout
     * @return PromiseInterface<array<string,mixed>>
     */
    private function asyncFetchFeatures(string $url, ?int $timeout): PromiseInterface
    {
        /** @var PromiseInterface<ResponseInterface> $request */
        $request = $this->asyncClient->get($url);
        if ($timeout !== null && $timeout > 0) {
            /** @var PromiseInterface<ResponseInterface> $request */
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
        $res = $this->httpClient->sendRequest($req);
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
     * @param array<string,mixed> $features
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
