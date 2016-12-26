<?php

namespace Alsma\BTCEConnector\Api;

use Alsma\BTCEConnector\Exception\RemoteError;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;

use Psr\Http\Message\ResponseInterface;

class Client extends \GuzzleHttp\Client
{
    const TRADE_URI = 'tapi';

    const RETRY_COUNT = 3;
    const RETRY_INTERVAL = 1;

    /** @var string */
    protected $apiKey;

    /** @var string */
    protected $apiSecret;

    /** @var array */
    protected $defaultRequestOptions = [];

    /**
     * @param array  $config
     * @param string $proxy
     */
    public function __construct(array $config, string $proxy = null)
    {
        if (null !== $proxy) {
            $this->defaultRequestOptions[RequestOptions::PROXY] = $proxy;
        }

        $this->apiKey = $config['api_key'] ?? '';
        $this->apiSecret = $config['api_secret'] ?? '';

        parent::__construct(['base_uri' => 'https://btc-e.com', 'http_errors' => false]);
    }

    /**
     * {@see https://btc-e.com/tapi/docs#RedeemCoupon}
     *
     * @param string $code
     *
     * @return array
     */
    public function redeemCoupon(string $code): array
    {
        return $this->sendTradeAPIRequest(['method' => 'RedeemCoupon', 'coupon' => $code]);
    }

    /**
     * {@see https://btc-e.com/tapi/docs#getInfo}
     *
     * @return array
     */
    public function getInfo(): array
    {
        return $this->sendTradeAPIRequest(['method' => 'getInfo']);
    }

    /**
     * @param string $pair
     * @param string $type
     * @param string $rate
     * @param string $amount
     *
     * @return array
     */
    public function trade(string $pair, string $type, string $rate, string $amount): array
    {
        return $this->sendTradeAPIRequest(['method' => 'Trade', 'pair' => $pair, 'type' => $type, 'rate' => $rate, 'amount' => $amount]);
    }

    /**
     * @param string $orderId
     *
     * @return array
     */
    public function cancelOrder(string $orderId): array
    {
        return $this->sendTradeAPIRequest(['method' => 'CancelOrder', 'order_id' => $orderId]);
    }

    /**
     * {@see https://btc-e.com/tapi/docs#TradeHistory}
     *
     * @param array $filters
     *
     * @return array
     */
    public function getTradeHistory(array $filters = []): array
    {
        $filters = array_intersect_key($filters, array_flip([
            'from',
            'count',
            'from_id',
            'end_id',
            'order',
            'since',
            'end',
            'pair'
        ]));

        return $this->sendTradeAPIRequest(array_merge($filters, ['method' => 'TradeHistory']));
    }

    /**
     * {@see https://btc-e.com/tapi/docs#TransHistory}
     *
     * @param array $filters
     *
     * @return array
     */
    public function getTransHistory(array $filters = []): array
    {
        $filters = array_intersect_key($filters, array_flip([
            'from',
            'count',
            'from_id',
            'end_id',
            'order',
            'since',
            'end'
        ]));

        return $this->sendTradeAPIRequest(array_merge(['method' => 'TransHistory'], $filters));
    }

    /**
     * {@see https://btc-e.com/api/3/docs#info}
     *
     * @return array
     */
    public function getPairsInfo(): array
    {
        $pairInfo = $this->sendOpenAPIRequest(new Uri('api/3/info'));

        return $pairInfo['pairs'] ?? [];
    }

    /**
     * {@see https://btc-e.com/api/3/docs#depth}
     *
     * @param string $pair
     * @param int    $limit
     *
     * @return array
     */
    public function getDepth($pair, $limit = 150): array
    {
        $result = $this->sendOpenAPIRequest(new Uri(sprintf('api/3/depth/%s?limit=%d', $pair, $limit)));

        return current($result);
    }

    /**
     * @param array $body
     * @param int   $retryNo
     *
     * @return array
     */
    protected function sendTradeAPIRequest(array $body = [], int $retryNo = 0): array
    {
        $body['nonce'] = (int)bcmul(bcadd(time(), substr(microtime(), 0, 3), 1), 10) - 13e9;

        $postFields = http_build_query($body, '', '&');

        $request = new Request('POST', self::TRADE_URI, [], $postFields);
        $request = $request->withHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
        $request = $request->withHeader('Sign', hash_hmac('sha512', $postFields, $this->apiSecret));
        $request = $request->withHeader('Key', $this->apiKey);

        $response = $this->send($request);
        $data = \GuzzleHttp\json_decode((string)$response->getBody(), true);

        if (self::isSuccessful($response) && isset($data['success']) && 1 === $data['success']) {
            return $data['return'];
        } elseif (self::isServerError($response) && $retryNo <= self::RETRY_COUNT) {
            sleep(self::RETRY_INTERVAL);

            return $this->sendTradeAPIRequest($request, ++$retryNo);
        } else {
            throw new RemoteError(isset($data['error']) ? $data['error'] : null);
        }
    }

    /**
     * @param Uri $uri
     * @param int $retryNo
     *
     * @return mixed
     */
    protected function sendOpenAPIRequest(Uri $uri, int $retryNo = 0): array
    {
        $response = $this->get($uri, $this->defaultRequestOptions);
        $data = \GuzzleHttp\json_decode((string)$response->getBody(), true);

        if (self::isSuccessful($response) && is_array($data)) {
            return $data;
        } elseif (self::isServerError($response) && $retryNo <= self::RETRY_COUNT) {
            sleep(self::RETRY_INTERVAL);

            return $this->sendOpenAPIRequest($uri, ++$retryNo);
        } else {
            throw new RemoteError($data['error'] ?? 'unknown');
        }
    }

    /**
     * @param ResponseInterface $response
     *
     * @return bool
     */
    private static function isSuccessful(ResponseInterface $response)
    {
        return $response->getStatusCode() === 200;
    }

    /**
     * @param ResponseInterface $response
     *
     * @return bool
     */
    private static function isServerError(ResponseInterface $response)
    {
        return $response->getStatusCode() >= 500 && $response->getStatusCode() < 600;
    }
}
