<?php

namespace MoySklad\Http;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use JMS\Serializer\Serializer;
use MoySklad\ApiClient;
use MoySklad\Entity\MetaEntity;
use MoySklad\Util\Exception\ApiClientException;
use MoySklad\Util\Constant;
use MoySklad\Util\Param\Param;
use MoySklad\Util\Serializer\SerializerInstance;
use MoySklad\Util\StringsTrait;

final class RequestExecutor
{
    use StringsTrait;

    const METHOD_GET = 'GET',
        METHOD_POST = 'POST',
        METHOD_PUT = 'PUT',
        METHOD_DELETE = 'DELETE';

    const PATH_TYPE = 'path',
        URL_TYPE = 'url';

    /**
     * @var string
     */
    private $hostApiPath = '';

    /**
     * @var string
     */
    private $url;

    /**
     * @var array
     */
    private $query = [];

    /**
     * @var array
     */
    private $headers = [
        'Content-Type' => 'application/json',
        'Accept'       => 'application/json;charset=utf-8',
    ];

    /**
     * @var Param[]
     */
    private $params = [];

    /**
     * @var MetaEntity|MetaEntity[]
     */
    private $body = null;

    /**
     * @var RequestSenderInterface
     */
    private $client;

    /**
     * @var Serializer
     */
    public $serializer;

    /**
     * RequestExecutor constructor.
     * @param ApiClient $api
     * @param string $url
     * @param string $type
     */
    private function __construct(ApiClient $api, string $url, string $type = self::PATH_TYPE)
    {
        $this->serializer = SerializerInstance::getInstance();

        switch ($type) {
            case self::PATH_TYPE:
                if (is_null($api)) {
                    throw new \InvalidArgumentException('To make an API request you need an initialized instance of ApiClient!');
                }

                $this->client = $api->getClient();
                $this->hostApiPath = $api->getHost().Constant::API_PATH;
                $this->url = $this->hostApiPath.$url;
                break;
            case self::URL_TYPE:
                if (is_null($api->getClient())) {
                    throw new \InvalidArgumentException("To make an API request you need an initialized instance of RequestSenderInterface!");
                }

                $this->client = $api->getClient();
                $this->url = $url;

                break;
        }

        $this->auth($api);
    }

    /**
     * @param ApiClient $api
     * @param string $path
     * @return RequestExecutor
     */
    public static function path(ApiClient $api, string $path): self
    {
        return new self($api, $path);
    }

    /**
     * @param ApiClient $api
     * @param string $url
     * @return RequestExecutor
     */
    public static function url(ApiClient $api, string $url): self
    {
        return new self($api, $url, self::URL_TYPE);
    }

    /**
     * @param string $key
     * @param string $value
     * @return RequestExecutor
     */
    public function header(string $key, string $value): self
    {
        if (strlen($key) > 0) {
            $this->headers[$key] = $value;
        }

        return $this;
    }

    /**
     * @param string $key
     * @param string $value
     * @return RequestExecutor
     */
    public function query(string $key, string $value): self
    {
        if (strlen($key) > 0) {
            $this->query[$key] = $value;
        }

        return $this;
    }

    /**
     * @param Param[] $params
     * @return RequestExecutor
     */
    public function params(array $params): self
    {
        if (!is_null($this->params) && count($params) > 0) {
            $this->params = $params;
        }

        return $this;
    }

    /**
     * @param MetaEntity[] $body
     * @return RequestExecutor
     */
    public function bodyArray(array $body): self
    {
        $this->body = $body;

        return $this;
    }

    /**
     * @param MetaEntity $body
     * @return RequestExecutor
     */
    public function body(MetaEntity $body): self
    {
        $this->body = $body;

        return $this;
    }

    /**
     * @param ApiClient $api
     * @return RequestExecutor
     */
    private function auth(ApiClient $api): self
    {
        if ($api->getToken()) {
            $this->headers['Authorization'] = 'Basic '.$api->getToken();
            return $this;
        }

        $this->headers['Authorization'] = 'Basic '.base64_encode($api->getLogin().':'.$api->getPassword());
        return $this;
    }

    /**
     * @return string
     */
    private function buildFullUrl(): string
    {
        if (count($this->params) < 1) {
            return $this->url;
        }

        $paramTypes = array_unique(array_column($this->params, 'type'));
        foreach ($paramTypes as $paramType) {
            $this->query[urlencode($paramType)] = Param::renderParamString($paramType, $this->params);
        }

        return $this->url.'?'.http_build_query($this->query);
    }

    /**
     * @param Request $request
     * @return string
     * @throws ApiClientException
     */
    private function executeRequest(Request $request): string
    {
        try {
            $response = $this->client->sendRequest($request);

            if ($response->getStatusCode() != 200 &&
                    $response->getStatusCode() != 201 &&
                    $response->getStatusCode() != 204) {

                throw new ApiClientException(
                    $request->getMethod().' '.$request->getUri(),
                    $response->getStatusCode(),
                    $response->getReasonPhrase()
                );
            }

            return $response->getBody()->getContents();
        } catch (GuzzleException $e) {
            $message = $e->getMessage();
            if ($e instanceof ClientException) {
                $message .= ' Response content: ' . $e->getResponse()->getBody()->getContents();
            }

            throw new ApiClientException($request->getMethod().' '.$request->getUri(), $e->getCode(), $message);
        }
    }

    /**
     * @param string $className
     * @return MetaEntity|MetaEntity[]
     * @throws ApiClientException
     */
    public function get(string $className)
    {
        $request = new Request(self::METHOD_GET, $this->buildFullUrl(), $this->headers);

        return $this->serializer->deserialize($this->executeRequest($request), $className, SerializerInstance::JSON_FORMAT);
    }

    /**
     * @param string $className
     * @return MetaEntity|MetaEntity[]
     * @throws ApiClientException
     */
    public function post(string $className)
    {
        $strBody = null;
        if (!is_null($this->body)) {
            $strBody = $this->serializer->serialize($this->body, SerializerInstance::JSON_FORMAT);
        }

        $request = new Request(self::METHOD_POST, $this->buildFullUrl(), $this->headers, $strBody);

        return $this->serializer->deserialize($this->executeRequest($request), $className, SerializerInstance::JSON_FORMAT);
    }

    /**
     * @param string $className
     * @return MetaEntity
     * @throws ApiClientException
     */
    public function put(string $className): MetaEntity
    {
        $strBody = null;
        if (!is_null($this->body)) {
            $strBody = $this->serializer->serialize($this->body, SerializerInstance::JSON_FORMAT);
        }

        $request = new Request(self::METHOD_PUT, $this->buildFullUrl(), $this->headers, $strBody);

        return $this->serializer->deserialize($this->executeRequest($request), $className, SerializerInstance::JSON_FORMAT);
    }

    /**
     * @throws ApiClientException
     */
    public function delete(): void
    {
        $request = new Request(self::METHOD_DELETE, $this->buildFullUrl(), $this->headers);

        $this->executeRequest($request);
    }
}
