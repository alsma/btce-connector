<?php

namespace Alsma\BTCEConnector\Api;

use Guzzle\Common\Collection;
use Guzzle\Http\Client as HttpClient;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\EntityEnclosingRequest;

use Alsma\BTCEConnector\Exception\RemoteError;

/*
 * Use this class instead of GuzzleHttp\Client because of error when content-type header is set.
 * See CurlFactory set it always.
 */

class Client extends HttpClient
{
    const TRADE_URI = 'tapi';

    const RETRY_COUNT = 3;
    const RETRY_INTERVAL = 1;

    /** @var string */
    protected $apiKey;

    /** @var string */
    protected $apiSecret;

    /**
     * @param array       $config
     * @param string|null $proxy
     */
    public function __construct(array $config, $proxy = null)
    {
        $defaults = ['base_url' => 'https://btc-e.com/',];
        $required = ['base_url', 'api_key', 'api_secret'];

        if (null !== $proxy) {
            $defaults[self::REQUEST_OPTIONS] = [
                'proxy'  => $proxy,
                'verify' => false
            ];
        }

        $config = Collection::fromConfig($config, $defaults, $required);
        $this->apiKey = $config->get('api_key');
        $this->apiSecret = $config->get('api_secret');
        $config->set('exceptions', false);

        parent::__construct($config->get('base_url'), $config);
    }

    /**
     * {@see https://btc-e.com/tapi/docs#RedeemCoupon}
     *
     * @param string $code
     *
     * @return array
     */
    public function redeemCoupon($code)
    {
        $request = $this->post(self::TRADE_URI, [], ['method' => 'RedeemCoupon', 'coupon' => $code]);

        return $this->sendTradeAPIRequest($request);
    }

    /**
     * {@see https://btc-e.com/tapi/docs#getInfo}
     *
     * @return array
     */
    public function getInfo()
    {
        $request = $this->post(self::TRADE_URI, [], ['method' => 'getInfo']);

        return $this->sendTradeAPIRequest($request);
    }

    /**
     * @param string $pair
     * @param string $type
     * @param float  $rate
     * @param float  $amount
     *
     * @return array
     */
    public function trade($pair, $type, $rate, $amount)
    {
        $request = $this->post(self::TRADE_URI, [], [
            'method' => 'Trade',
            'pair'   => $pair,
            'type'   => $type,
            'rate'   => $rate,
            'amount' => $amount
        ]);

        return $this->sendTradeAPIRequest($request);
    }

    /**
     * @param $orderId
     *
     * @return mixed
     */
    public function cancelOrder($orderId)
    {
        $request = $this->post(self::TRADE_URI, [], [
            'method'   => 'CancelOrder',
            'order_id' => $orderId
        ]);

        return $this->sendTradeAPIRequest($request);
    }

    /**
     * {@see https://btc-e.com/tapi/docs#TradeHistory}
     *
     * @param array $filters
     *
     * @return array
     */
    public function getTradeHistory(array $filters = [])
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

        $request = $this->post(self::TRADE_URI, [], array_merge($filters, ['method' => 'TradeHistory']));

        return $this->sendTradeAPIRequest($request);
    }

    /**
     * {@see https://btc-e.com/tapi/docs#TransHistory}
     *
     * @param array $filters
     *
     * @return array
     */
    public function getTransHistory(array $filters = [])
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

        $request = $this->post(self::TRADE_URI, [], array_merge(['method' => 'TransHistory'], $filters));

        return $this->sendTradeAPIRequest($request);
    }

    /**
     * {@see https://btc-e.com/api/3/docs#info}
     *
     * @return array
     */
    public function getPairsInfo()
    {
        $request = $this->get('api/3/info');

        return $this->sendOpenAPIRequest($request)['pairs'];
    }

    /**
     * {@see https://btc-e.com/api/3/docs#depth}
     *
     * @param string $pair
     * @param int    $limit
     *
     * @return array
     */
    public function getDepth($pair, $limit = 150)
    {
        $request = $this->get(['api/3/depth/{pair}?limit={limit}', ['pair' => $pair, 'limit' => $limit]]);
        $result = $this->sendOpenAPIRequest($request);

        return current($result);
    }

    /**
     * @param RequestInterface $request
     * @param int              $retryNo
     *
     * @return mixed
     * @throws RemoteError
     */
    protected function sendTradeAPIRequest(RequestInterface $request, $retryNo = 0)
    {
        if ($request instanceof EntityEnclosingRequest) {
            $nonce = (int)bcmul(bcadd(time() - 1e5, substr(microtime(), 0, 4), 2), 100);

            $request->setPostField('nonce', $nonce);
            $request->setHeader('Sign', hash_hmac('sha512', $request->getPostFields(), $this->apiSecret));
            $request->setHeader('Key', $this->apiKey);
        }

        $response = $this->send($request);
        $data = $request->getResponse()->json();
        if ($response->isSuccessful() && isset($data['success']) && 1 === $data['success']) {
            return $data['return'];
        } elseif ($response->isServerError() && $retryNo <= self::RETRY_COUNT) {
            sleep(self::RETRY_INTERVAL);

            return $this->sendTradeAPIRequest($request, ++$retryNo);
        } else {
            throw new RemoteError(isset($data['error']) ? $data['error'] : null);
        }
    }

    /**
     * @param RequestInterface $request
     * @param int              $retryNo
     *
     * @return mixed
     * @throws RemoteError
     */
    protected function sendOpenAPIRequest(RequestInterface $request, $retryNo = 0)
    {
        $response = $this->send($request);
        $data = $request->getResponse()->json();
        if ($response->isSuccessful()) {
            return $data;
        } elseif ($response->isServerError() && $retryNo <= self::RETRY_COUNT) {
            sleep(self::RETRY_INTERVAL);

            return $this->sendTradeAPIRequest($request, ++$retryNo);
        } else {
            throw new RemoteError(isset($data['error']) ? $data['error'] : null);
        }
    }
}
