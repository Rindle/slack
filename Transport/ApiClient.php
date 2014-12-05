<?php

/*
 * This file is part of the CLSlackBundle.
 *
 * (c) Cas Leentfaar <info@casleentfaar.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CL\Slack\Transport;

use CL\Slack\Exception\SlackException;
use CL\Slack\Payload\PayloadInterface;
use CL\Slack\Payload\PayloadResponseInterface;
use CL\Slack\Transport\Events\ResponseEvent;
use CL\Slack\Transport\Events\RequestEvent;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Message\ResponseInterface;
use GuzzleHttp\Post\PostBody;
use JMS\Serializer\SerializerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class ApiClient
{
    /**
     * The (base) URL used for all communication with the Slack API.
     */
    const API_BASE_URL = 'https://slack.com/api/';

    /**
     * @var string|null
     */
    private $token;

    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * @var ClientInterface
     */
    private $client;

    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * @param string               $token
     * @param SerializerInterface  $serializer
     * @param ClientInterface|null $client
     */
    public function __construct(
        $token,
        SerializerInterface $serializer,
        ClientInterface $client = null
    ) {
        $this->token           = $token;
        $this->serializer      = $serializer;
        $this->client          = $client ?: new Client();
        $this->eventDispatcher = new EventDispatcher();
    }

    /**
     * @param PayloadInterface $payload The payload to send
     * @param string|null      $token   Optional token to use during the API-call,
     *                                  defaults to the one configured during construction
     *
     * @throws SlackException If the payload could not be sent
     *
     * @return PayloadResponseInterface Actual class depends on the payload used,
     *                                  e.g. chat.postMessage will return an instance of ChatPostMessagePayloadResponse
     */
    public function send(PayloadInterface $payload, $token = null)
    {
        try {
            if ($token === null && $this->token === null) {
                throw new \InvalidArgumentException('You must supply a token to send a payload, since you did not provide one during construction');
            }

            $responseData = $this->sendRaw($payload->getMethod(), $this->serializePayload($payload), $token);

            return $this->deserializeResponse($responseData, $payload->getResponseClass());
        } catch (\Exception $e) {
            throw new SlackException('Failed to send payload to the Slack API', null, $e);
        }
    }

    /**
     * @param string $method
     * @param array  $data
     * @param null   $token
     *
     * @throws SlackException
     *
     * @return array
     */
    private function sendRaw($method, array $data, $token = null)
    {
        try {
            if ($token === null && $this->token === null) {
                throw new \LogicException('You must supply a token to send a payload if you did not provide one during construction');
            }

            $this->eventDispatcher->dispatch(ApiClientEvents::EVENT_BEFORE, new RequestEvent($data));

            $request = $this->createRequest($method, $data, $token);

            /** @var ResponseInterface $response */
            $response = $this->client->send($request);
        } catch (\Exception $e) {
            throw new SlackException('Failed to send raw payload to the Slack API', null, $e);
        }

        try {
            $responseData = json_decode($response->getBody()->getContents(), true);
            if (!is_array($responseData)) {
                throw new \Exception(sprintf('Expected response data to be of type "array", got "%s"', gettype($responseData)));
            }

            $this->eventDispatcher->dispatch(ApiClientEvents::EVENT_AFTER, new ResponseEvent($responseData));

            return $responseData;
        } catch (\Exception $e) {
            throw new SlackException('Failed to process response from the Slack API', null, $e);
        }
    }

    /**
     * @param array  $responseData
     * @param string $responseClass
     *
     * @throws SlackException
     *
     * @return PayloadResponseInterface
     */
    private function deserializeResponse(array $responseData, $responseClass)
    {
        $deserializedResponse = $this->serializer->deserialize(json_encode($responseData), $responseClass, 'json');

        if (!is_object($deserializedResponse)) {
            throw new SlackException('The response could not be deserialized into an object');
        }

        if (!($deserializedResponse instanceof $responseClass)) {
            throw new SlackException(sprintf(
                'The response could not be deserialized into a payload response object (%s is not an instance of %s)',
                get_class($deserializedResponse),
                $responseClass
            ));
        }

        return $deserializedResponse;
    }

    /**
     * @return EventDispatcherInterface
     */
    public function getEventDispatcher()
    {
        return $this->eventDispatcher;
    }

    /**
     * @param string      $method
     * @param array       $payload
     * @param string      $requestMethod
     * @param string|null $token
     *
     * @return RequestInterface
     */
    private function createRequest($method, array $payload, $requestMethod = 'GET', $token = null)
    {
        $payload['token'] = $token ?: $this->token;

        if ($requestMethod !== 'GET') {
            $request = $this->client->createRequest('POST');
            $request->setUrl(self::API_BASE_URL . $method);

            $body = new PostBody();
            $body->replaceFields($payload);

            $request->setBody($body);
        } else {
            $request = $this->client->createRequest('GET');
            $request->setUrl(self::API_BASE_URL . $method);
            $request->setQuery($payload);
        }

        return $request;
    }

    /**
     * @param PayloadInterface $payload
     *
     * @return array
     */
    private function serializePayload(PayloadInterface $payload)
    {
        return json_decode($this->serializer->serialize($payload, 'json'), true);
    }
}
